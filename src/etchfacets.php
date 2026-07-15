<?php
declare(strict_types=1);

/**
 * Plugin Name: EtchFacets
 * Description: Faceted search engine for EtchWP
 * Version: 0.3.2
 * Author: Normadic Studio
 * Requires PHP: 8.1
 * Requires at least: 5.9
 * Text Domain: etchfacets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ETCHFACETS_VERSION', '0.3.2' );
define( 'ETCHFACETS_PLUGIN_FILE', __FILE__ );
define( 'ETCHFACETS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ETCHFACETS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ETCHFACETS_PLUGIN_DIR . 'includes/class-query-builder.php';
require_once ETCHFACETS_PLUGIN_DIR . 'includes/class-count-calculator.php';
require_once ETCHFACETS_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once ETCHFACETS_PLUGIN_DIR . 'includes/class-facet-renderer.php';
require_once ETCHFACETS_PLUGIN_DIR . 'includes/class-choices-rest.php';

// GitHub-based auto-updates via plugin-update-checker (PUC).
require_once ETCHFACETS_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$etchfacets_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/endresol/EtchFacets/',
	ETCHFACETS_PLUGIN_FILE,
	'etchfacets'
);

// Look at GitHub Releases (tagged) only, and pull the built zip attached as a release asset.
$etchfacets_update_checker->getVcsApi()->enableReleaseAssets();
$etchfacets_update_checker->setBranch( 'main' );

add_action( 'plugins_loaded', 'etchfacets_init' );

/**
 * Initialize plugin classes.
 */
function etchfacets_init(): void {
	$ajax_handler = new EtchFacets_Ajax_Handler();
	$ajax_handler->init();

	$renderer = new EtchFacets_Facet_Renderer();
	$renderer->init();

	$choices_rest = new EtchFacets_Choices_Rest();
	$choices_rest->init();
}

add_action( 'pre_get_posts', 'etchfacets_filter_query' );

/**
 * Split a `_...` GET param key into the instance (group) it belongs to and
 * its base name within that group.
 *
 * The "main" instance's params are unprefixed (`_foo`) for backward
 * compatibility with existing bookmarked/shared URLs — anything not matching
 * the prefixed form below falls into that group. Any other instance's params
 * are prefixed with its group id (`_{group}__foo`, e.g. `_ef-0__category`).
 * Mirrors `parseParamKey()` in assets/js/etchfacets.js — keep both in sync.
 *
 * @param string $key The raw GET param key (including its leading `_`).
 * @return array{group: string, base: string}|null
 */
function etchfacets_parse_param_key( string $key ): ?array {
	if ( 0 !== strpos( $key, '_' ) ) {
		return null;
	}

	// Group class includes "_" (a sanitize_key'd override value can contain
	// one) and matches greedily so the LAST "__" in the key is treated as
	// the group/base separator.
	if ( preg_match( '/^_([a-zA-Z0-9_-]+)__(.+)$/', $key, $matches ) ) {
		return [ 'group' => $matches[1], 'base' => $matches[2] ];
	}

	return [ 'group' => 'main', 'base' => substr( $key, 1 ) ];
}

/**
 * Parse facet params from the current URL, bucketed per facet/listing
 * instance (group). Cached statically so we only parse once per request.
 *
 * @return array<string, array{facets: array, sources: array, logic: array, args: array, post_type: string, page: int}>|null
 *         Keyed by group id, or null if the URL carries no recognized state.
 */
function etchfacets_parse_url_params(): ?array {
	static $parsed = null;
	static $ran    = false;

	if ( $ran ) {
		return $parsed;
	}
	$ran = true;

	if ( empty( $_GET ) ) {
		return null;
	}

	// Bucket every recognized param by the instance (group) it belongs to.
	$buckets = [];

	foreach ( $_GET as $key => $value ) {
		$parsed_key = etchfacets_parse_param_key( (string) $key );
		if ( ! $parsed_key ) {
			continue;
		}

		$group = sanitize_key( $parsed_key['group'] );
		$base  = $parsed_key['base'];
		if ( empty( $group ) ) {
			continue;
		}

		if ( ! isset( $buckets[ $group ] ) ) {
			$buckets[ $group ] = [
				'raw_facets' => [],
				'src'        => [],
				'logic'      => [],
				'pt'         => '',
				'page'       => 1,
			];
		}

		if ( 0 === strpos( $base, 'src_' ) ) {
			$facet_name = sanitize_key( substr( $base, 4 ) );
			if ( $facet_name ) {
				$buckets[ $group ]['src'][ $facet_name ] = sanitize_text_field( wp_unslash( $value ) );
			}
			continue;
		}

		if ( 0 === strpos( $base, 'logic_' ) ) {
			$facet_name = sanitize_key( substr( $base, 6 ) );
			if ( $facet_name ) {
				$buckets[ $group ]['logic'][ $facet_name ] = sanitize_key( wp_unslash( $value ) );
			}
			continue;
		}

		if ( 'pt' === $base ) {
			$buckets[ $group ]['pt'] = sanitize_key( wp_unslash( $value ) );
			continue;
		}

		if ( 'page' === $base ) {
			$page                      = absint( $value );
			$buckets[ $group ]['page'] = $page < 1 ? 1 : $page;
			continue;
		}

		$facet_name = sanitize_key( $base );
		if ( $facet_name ) {
			$buckets[ $group ]['raw_facets'][ $facet_name ] = wp_unslash( $value );
		}
	}

	if ( empty( $buckets ) ) {
		return null;
	}

	$query_builder = new EtchFacets_Query_Builder();
	$groups        = [];

	foreach ( $buckets as $group => $bucket ) {
		$facets  = [];
		$sources = [];
		$logic   = [];

		foreach ( $bucket['raw_facets'] as $facet_name => $raw_value ) {
			// A facet param with no matching _src_ companion carries no
			// resolvable source — skip it (same as a single-instance page).
			if ( ! isset( $bucket['src'][ $facet_name ] ) ) {
				continue;
			}

			$facets[ $facet_name ]  = array_map( 'sanitize_text_field', explode( ',', $raw_value ) );
			$sources[ $facet_name ] = $bucket['src'][ $facet_name ];
			$logic[ $facet_name ]   = $bucket['logic'][ $facet_name ] ?? 'or';
		}

		// Nothing to filter and no pagination for this instance — skip it.
		if ( empty( $facets ) && $bucket['page'] <= 1 ) {
			continue;
		}

		$args = $query_builder->build_query_args( $facets, $sources, $logic, [] );

		$groups[ $group ] = [
			'facets'    => $facets,
			'sources'   => $sources,
			'logic'     => $logic,
			'args'      => $args,
			'post_type' => $bucket['pt'],
			'page'      => $bucket['page'],
		];
	}

	if ( empty( $groups ) ) {
		return null;
	}

	$parsed = $groups;
	return $parsed;
}

/**
 * Modify WP_Query based on _facet URL parameters.
 *
 * Only applies to queries whose post_type matches the faceted content,
 * preventing menu queries, widget queries, etc. from being affected. Runs
 * once per facet/listing instance (group) found in the URL — each group's
 * guard independently decides whether it applies to this particular query,
 * so two instances targeting different post types never leak into each
 * other's queries, and a query that genuinely belongs to neither is left
 * untouched.
 *
 * @param WP_Query $query The query to modify.
 */
function etchfacets_filter_query( WP_Query $query ): void {
	if ( is_admin() ) {
		return;
	}

	// Never modify the main query when it resolves a singular page/post.
	// The listing loop is rendered by a *secondary* query (e.g. an Etch query
	// loop), which is still filtered below. Applying facet filters to the
	// singular main query makes it return 0 posts, so WordPress concludes the
	// page doesn't exist and serves a 404.
	if ( $query->is_main_query() && $query->is_singular() ) {
		return;
	}

	$groups = etchfacets_parse_url_params();
	if ( ! $groups ) {
		return;
	}

	foreach ( $groups as $parsed ) {
		$args        = $parsed['args'];
		$target_type = $parsed['post_type'] ?? '';

		// Determine this query's post type(s).
		$query_post_type = $query->get( 'post_type' );
		$query_types     = (array) ( $query_post_type ?: 'post' );

		if ( $target_type ) {
			// Scope filtering to the listing's declared post type. Etch renders
			// each card by running additional secondary queries (for related
			// posts, meta, etc.); without this guard the facet's tax/meta_query
			// would leak into those and blank out the card data.
			if ( ! in_array( $target_type, $query_types, true ) ) {
				continue;
			}
		} else {
			/**
			 * Filter whether an untargeted facet group (no `_pt` in the URL —
			 * every markup generator this plugin ships always emits it, so this
			 * only happens with hand-authored `data-etchfacets-query` markup
			 * that omits post_type) may still apply its filters to this query.
			 *
			 * Defaults to false: with no declared post type we can't tell which
			 * of possibly several listings on the page this group belongs to,
			 * so the safe default is to filter nothing rather than guess and
			 * leak filters into an unrelated query/post type/card. Sites that
			 * know they only ever have one facet-driven post type per page can
			 * opt back into the old best-effort matching.
			 *
			 * @param bool     $allow  Whether to apply anyway. Default false.
			 * @param WP_Query $query  The query under consideration.
			 * @param array    $parsed This group's parsed facet state.
			 */
			$allow_untargeted = (bool) apply_filters( 'etchfacets/filter_query/apply_untargeted', false, $query, $parsed );

			if ( ! $allow_untargeted ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						'EtchFacets: skipped applying facet filters — no post_type (`_pt`) declared for this facet group. Add post_type to data-etchfacets-query, or opt into legacy best-effort matching via the etchfacets/filter_query/apply_untargeted filter. Query post_type(s): %s.',
						implode( ',', $query_types )
					) );
				}
				continue;
			}
		}

		if ( isset( $args['tax_query'] ) ) {
			$existing = $query->get( 'tax_query' ) ?: [];
			$query->set( 'tax_query', array_merge( $existing, $args['tax_query'] ) );
		}

		if ( isset( $args['meta_query'] ) ) {
			$existing = $query->get( 'meta_query' ) ?: [];

			// Group rather than flat-merge: a flat merge would fold the facet
			// clauses into an existing top-level `relation => OR`, which would
			// loosen (not narrow) the query — e.g. the map bbox would become an
			// OR branch and leak in posts outside the viewport.
			if ( ! empty( $existing ) ) {
				$query->set( 'meta_query', [
					'relation' => 'AND',
					$existing,
					$args['meta_query'],
				] );
			} else {
				$query->set( 'meta_query', $args['meta_query'] );
			}
		}

		if ( isset( $args['s'] ) ) {
			$query->set( 's', $args['s'] );
		}

		if ( isset( $args['author__in'] ) ) {
			$query->set( 'author__in', $args['author__in'] );
		}

		if ( isset( $args['orderby'] ) ) {
			$query->set( 'orderby', $args['orderby'] );
		}

		if ( isset( $args['order'] ) ) {
			$query->set( 'order', $args['order'] );
		}

		if ( ! empty( $parsed['page'] ) && $parsed['page'] > 1 ) {
			$query->set( 'paged', $parsed['page'] );
		}
	}
}

add_action( 'wp_enqueue_scripts', 'etchfacets_enqueue_assets' );

/**
 * Enqueue frontend script and localize config.
 */
function etchfacets_enqueue_assets(): void {
	wp_enqueue_script(
		'etchfacets',
		ETCHFACETS_PLUGIN_URL . 'assets/js/etchfacets.js',
		[],
		ETCHFACETS_VERSION,
		true
	);

	wp_localize_script( 'etchfacets', 'etchfacetsConfig', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'etchfacets_nonce' ),
	] );

	wp_enqueue_style(
		'etchfacets',
		ETCHFACETS_PLUGIN_URL . 'assets/css/etchfacets.css',
		[],
		ETCHFACETS_VERSION
	);

	etchfacets_enqueue_map_assets();
}

/**
 * Enqueue the Google Maps facet module and its config.
 *
 * The small module is always loaded; it self-exits when no `.etchfacets-map`
 * element exists and only pulls in the (heavy) Google Maps API when a map is
 * actually present on the page.
 */
function etchfacets_enqueue_map_assets(): void {
	wp_enqueue_script(
		'etchfacets-map',
		ETCHFACETS_PLUGIN_URL . 'assets/js/etchfacets-map.js',
		[ 'etchfacets' ],
		ETCHFACETS_VERSION,
		true
	);

	wp_localize_script( 'etchfacets-map', 'etchfacetsMapConfig', [
		'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
		'nonce'      => wp_create_nonce( 'etchfacets_nonce' ),
		/**
		 * Filter the Google Maps API key used by the map facet.
		 *
		 * @param string $api_key The resolved API key.
		 */
		'apiKey'     => apply_filters( 'etchfacets/map/api_key', get_option( 'etchfacets_gmaps_key', '' ) ),
		'maxMarkers' => (int) apply_filters( 'etchfacets/map/max_markers', 500 ),
	] );
}

add_action( 'etch/canvas/enqueue_assets', 'etchfacets_canvas_assets' );

/**
 * Enqueue assets in the Etch canvas.
 */
function etchfacets_canvas_assets(): void {
	wp_enqueue_script(
		'etchfacets',
		ETCHFACETS_PLUGIN_URL . 'assets/js/etchfacets.js',
		[],
		ETCHFACETS_VERSION,
		true
	);

	wp_localize_script( 'etchfacets', 'etchfacetsConfig', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'etchfacets_nonce' ),
	] );

	wp_enqueue_style(
		'etchfacets',
		ETCHFACETS_PLUGIN_URL . 'assets/css/etchfacets.css',
		[],
		ETCHFACETS_VERSION
	);

	etchfacets_enqueue_map_assets();
}

add_action( 'wp_enqueue_scripts', 'etchfacets_builder_assets' );

/**
 * Enqueue the Etch builder Settings Bar control script.
 *
 * The Etch builder UI is a frontend application, so this must hook into
 * `wp_enqueue_scripts` (not `admin_enqueue_scripts`). The script itself
 * self-checks for `window.etchControls`, so it's safe to load on all
 * frontend pages.
 *
 * @see https://docs.etchwp.com/integrations/controls
 */
function etchfacets_builder_assets(): void {
	wp_enqueue_script(
		'etchfacets-builder',
		ETCHFACETS_PLUGIN_URL . 'assets/js/etchfacets-builder.js',
		[],
		ETCHFACETS_VERSION,
		true
	);
}

add_filter( 'etch_autocompletion_classes', 'etchfacets_register_classes' );

/**
 * Register CSS classes for Etch autocompletion.
 *
 * @param array $classes Existing classes.
 * @return array Merged classes.
 */
function etchfacets_register_classes( array $classes ): array {
	return array_merge( $classes, [
		'etchfacets-instance',
		'etchfacets-template',
		'etchfacets-loading',
		'etchfacet-count',
		'etchfacet-ghost',
		'etchfacet-hidden',
		'etchfacets-facet-header',
		'etchfacets-facet-choices',
		'etchfacets-facet-toggle',
		'etchfacets-facet-toggle-icon',
		'etchfacets-map',
		'etchfacets-map--loading',
		'etchfacets-map--error',
		'etchfacets-map-info',
		'etchfacets-active-filters',
		'etchfacets-active-filter',
		'etchfacets-active-filter-text',
		'etchfacets-active-filter-remove',
		'etchfacets-range-reset',
	] );
}

add_action( 'admin_menu', 'etchfacets_register_settings_page' );

/**
 * Register the EtchFacets settings page under Settings.
 */
function etchfacets_register_settings_page(): void {
	add_options_page(
		__( 'EtchFacets', 'etchfacets' ),
		__( 'EtchFacets', 'etchfacets' ),
		'manage_options',
		'etchfacets',
		'etchfacets_render_settings_page'
	);
}

add_action( 'admin_init', 'etchfacets_register_settings' );

/**
 * Register settings, sections and fields.
 */
function etchfacets_register_settings(): void {
	register_setting( 'etchfacets_settings', 'etchfacets_gmaps_key', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	] );

	add_settings_section(
		'etchfacets_map_section',
		__( 'Map facet', 'etchfacets' ),
		function () {
			echo '<p>' . esc_html__( 'Settings for the Google Maps viewport facet.', 'etchfacets' ) . '</p>';
		},
		'etchfacets'
	);

	add_settings_field(
		'etchfacets_gmaps_key',
		__( 'Google Maps API key', 'etchfacets' ),
		function () {
			$value = get_option( 'etchfacets_gmaps_key', '' );
			printf(
				'<input type="text" name="etchfacets_gmaps_key" value="%s" class="regular-text" autocomplete="off">',
				esc_attr( $value )
			);
			echo '<p class="description">' . esc_html__( 'A Google Maps JavaScript API key with the Maps JavaScript API enabled.', 'etchfacets' ) . '</p>';
		},
		'etchfacets',
		'etchfacets_map_section'
	);
}

/**
 * Render the settings page wrapper.
 */
function etchfacets_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'EtchFacets', 'etchfacets' ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'etchfacets_settings' );
			do_settings_sections( 'etchfacets' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}
