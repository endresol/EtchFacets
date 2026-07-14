<?php
declare(strict_types=1);

/**
 * EtchFacets Choices REST Endpoint
 *
 * Exposes a facet's choice list (taxonomy terms / distinct meta values) as
 * JSON so a native Etch Loop (JSON loop source) can render each choice as a
 * real, editable component node instead of relying on server-rendered HTML
 * strings. Backs the "Facet — Checkboxes" (and future Radio/Dropdown)
 * components' internal Loop Prop.
 *
 * Returns the choice list for SSR/first-paint only — live count updates
 * after a user interacts still go through the existing `etchfacets_filter`
 * admin-ajax action and updateCounts() in etchfacets.js. Pass `post_type` to
 * scope terms/counts to a specific post type (recommended whenever the
 * taxonomy or meta key is shared across more than one post type) and
 * `hide_empty=false` to include zero-count terms — omitting both preserves
 * the original global/unscoped, empty-hidden behavior.
 *
 * @package EtchFacets
 */

class EtchFacets_Choices_Rest {

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register `GET /wp-json/etchfacets/v1/choices`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route( 'etchfacets/v1', '/choices', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_request' ],
			// Choice lists are already publicly visible today via shortcode
			// output on any page using [etchfacets_facet] — no new exposure.
			'permission_callback' => '__return_true',
			'args'                => [
				'name'       => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'source'     => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'post_type'  => [
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				],
				'hide_empty' => [
					'type'              => 'boolean',
					'default'           => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
				],
			],
		] );
	}

	/**
	 * Handle the request — returns `[{value, label, count}, ...]` for
	 * `taxonomy:`/`meta:` sources, reusing the same lookup logic the
	 * `[etchfacets_facet]` shortcode already uses. Other source types (which
	 * have no canned choice UI — `search`, `author`, `date`, `post_type`,
	 * `sort`, `geo:`, `meta_range:`) return an empty list, matching
	 * `EtchFacets_Facet_Renderer::get_choices()`'s existing behavior.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$source     = (string) $request->get_param( 'source' );
		$post_type  = (string) $request->get_param( 'post_type' );
		$hide_empty = (bool) $request->get_param( 'hide_empty' );

		$renderer = new EtchFacets_Facet_Renderer();
		$choices  = $renderer->get_choices( $source, $post_type, $hide_empty );

		return new WP_REST_Response( $choices, 200 );
	}
}
