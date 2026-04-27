<?php
declare(strict_types=1);

/**
 * Plugin Name: EtchFacets
 * Description: Faceted search engine for EtchWP
 * Version: 0.1.0
 * Author: EtchFacets
 * Requires PHP: 8.1
 * Requires at least: 5.9
 * Text Domain: etchfacets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ETCHFACETS_VERSION', '0.1.0' );
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

	if ( empty( $facets ) ) {
		return null;
	}

	$query_builder = new EtchFacets_Query_Builder();
	$args          = $query_builder->build_query_args( $facets, $sources, $logic, [] );

	$parsed = compact( 'facets', 'sources', 'logic', 'args' );
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

	$parsed = etchfacets_parse_url_params();
	if ( ! $parsed ) {
		return;
	}

	$args = $parsed['args'];

	// Determine which taxonomies/meta keys our facets target.
	// Only apply to queries that could match (by checking the query's post type
	// supports the taxonomies we're filtering by).
	$query_post_type = $query->get( 'post_type' );

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
		$query->set( 'meta_query', array_merge( $existing, $args['meta_query'] ) );
	}

	if ( isset( $args['s'] ) ) {
		$query->set( 's', $args['s'] );
	}

	if ( isset( $args['author__in'] ) ) {
		$query->set( 'author__in', $args['author__in'] );
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
	] );
}
