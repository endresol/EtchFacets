<?php
declare(strict_types=1);

/**
 * Plugin Name: EtchFacets
 * Description: Faceted search engine for EtchWP
 * Version: 0.2.0
 * Author: EtchFacets
 * Requires PHP: 8.1
 * Requires at least: 5.9
 * Text Domain: etchfacets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ETCHFACETS_VERSION', '0.2.0' );
define( 'ETCHFACETS_PLUGIN_FILE', __FILE__ );
define( 'ETCHFACETS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ETCHFACETS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ETCHFACETS_PLUGIN_DIR . 'includes/class-query-builder.php';
require_once ETCHFACETS_PLUGIN_DIR . 'includes/class-count-calculator.php';
require_once ETCHFACETS_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once ETCHFACETS_PLUGIN_DIR . 'includes/class-facet-renderer.php';

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
}

add_action( 'pre_get_posts', 'etchfacets_filter_query' );

/**
 * Parse facet params from the current URL.
 * Cached statically so we only parse once per request.
 *
 * @return array|null ['facets' => [...], 'sources' => [...], 'logic' => [...], 'args' => [...]] or null.
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

	$facets  = [];
	$sources = [];
	$logic   = [];

	foreach ( $_GET as $key => $value ) {
		// Skip _src_ and _logic_ params — they're metadata, not facet values.
		if ( 0 !== strpos( $key, '_' ) || 0 === strpos( $key, '_src_' ) || 0 === strpos( $key, '_logic_' ) ) {
			continue;
		}

		$facet_name = sanitize_key( substr( $key, 1 ) );
		if ( empty( $facet_name ) ) {
			continue;
		}

		$source_key = '_src_' . $facet_name;
		if ( ! isset( $_GET[ $source_key ] ) ) {
			continue;
		}

		$source = sanitize_text_field( wp_unslash( $_GET[ $source_key ] ) );
		$values = array_map( 'sanitize_text_field', explode( ',', wp_unslash( $value ) ) );

		$facets[ $facet_name ]  = $values;
		$sources[ $facet_name ] = $source;
		$logic[ $facet_name ]   = sanitize_key( $_GET[ '_logic_' . $facet_name ] ?? 'or' );
	}

	// Pagination — parsed independently of facets so a bare page-N request
	// (no active filters) still gets its 'paged' arg applied below.
	$page = isset( $_GET['_page'] ) ? absint( $_GET['_page'] ) : 1;
	if ( $page < 1 ) {
		$page = 1;
	}

	if ( empty( $facets ) && $page <= 1 ) {
		return null;
	}

	// The listing's target post type (sent by the JS as _pt). Used to scope
	// filtering to the listing query only.
	$post_type = isset( $_GET['_pt'] ) ? sanitize_key( wp_unslash( $_GET['_pt'] ) ) : '';

	$query_builder = new EtchFacets_Query_Builder();
	$args          = $query_builder->build_query_args( $facets, $sources, $logic, [] );

	$parsed = compact( 'facets', 'sources', 'logic', 'args', 'post_type', 'page' );
	return $parsed;
}

/**
 * Modify WP_Query based on _facet URL parameters.
 *
 * Only applies to queries whose post_type matches the faceted content,
 * preventing menu queries, widget queries, etc. from being affected.
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

	$parsed = etchfacets_parse_url_params();
	if ( ! $parsed ) {
		return;
	}

	$args        = $parsed['args'];
	$target_type = $parsed['post_type'] ?? '';

	// Determine this query's post type(s).
	$query_post_type = $query->get( 'post_type' );

	// Scope filtering to the listing's post type. Etch renders each card by
	// running additional secondary queries (for related posts, meta, etc.);
	// without this guard the facet meta_query would leak into those and blank
	// out the card data. If the target post type is known, only filter queries
	// for that post type.
	if ( $target_type ) {
		$query_types = (array) ( $query_post_type ?: 'post' );
		if ( ! in_array( $target_type, $query_types, true ) ) {
			return;
		}
	}

	// If we have taxonomy filters, check if this query's post type uses those taxonomies.
	if ( isset( $args['tax_query'] ) ) {
		$applies = false;
		foreach ( $args['tax_query'] as $clause ) {
			if ( ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) ) {
				continue;
			}
			$tax_object = get_taxonomy( $clause['taxonomy'] );
			if ( $tax_object ) {
				// Check if the query's post type is in this taxonomy's object_type.
				$post_types = (array) ( $query_post_type ?: 'post' );
				foreach ( $post_types as $pt ) {
					if ( in_array( $pt, $tax_object->object_type, true ) ) {
						$applies = true;
						break 2;
					}
				}
			}
		}

		if ( $applies ) {
			$existing = $query->get( 'tax_query' ) ?: [];
			$query->set( 'tax_query', array_merge( $existing, $args['tax_query'] ) );
		}
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
		'etchfacets-template',
		'etchfacets-loading',
		'etchfacet-count',
		'etchfacet-ghost',
		'etchfacet-hidden',
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
