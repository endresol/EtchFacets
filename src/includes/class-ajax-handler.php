<?php
declare(strict_types=1);

/**
 * EtchFacets AJAX Handler
 *
 * Handles the AJAX endpoint for facet filtering.
 *
 * @package EtchFacets
 */

class EtchFacets_Ajax_Handler {

	/** @var EtchFacets_Query_Builder */
	private EtchFacets_Query_Builder $query_builder;

	/** @var EtchFacets_Count_Calculator */
	private EtchFacets_Count_Calculator $count_calculator;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->query_builder    = new EtchFacets_Query_Builder();
		$this->count_calculator = new EtchFacets_Count_Calculator( $this->query_builder );
	}

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_ajax_etchfacets_filter', [ $this, 'handle_filter' ] );
		add_action( 'wp_ajax_nopriv_etchfacets_filter', [ $this, 'handle_filter' ] );
		add_action( 'wp_ajax_etchfacets_map', [ $this, 'handle_map' ] );
		add_action( 'wp_ajax_nopriv_etchfacets_map', [ $this, 'handle_map' ] );
	}

	/**
	 * Handle the facet filter AJAX request.
	 *
	 * Verifies nonce, sanitizes input, builds and runs the WP_Query,
	 * captures HTML output, calculates facet counts, and returns JSON.
	 *
	 * @return void
	 */
	public function handle_filter(): void {
		// 1. Verify nonce.
		if ( ! check_ajax_referer( 'etchfacets_nonce', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce', 403 );
		}

		// 2. Read and sanitize POST data.
		// JS sends JSON strings via FormData — decode them.
		$raw_facets  = isset( $_POST['facets'] ) ? json_decode( wp_unslash( $_POST['facets'] ), true ) : [];
		$raw_sources = isset( $_POST['sources'] ) ? json_decode( wp_unslash( $_POST['sources'] ), true ) : [];
		$raw_logic   = isset( $_POST['logic'] ) ? json_decode( wp_unslash( $_POST['logic'] ), true ) : [];
		$raw_context = isset( $_POST['query_context'] ) ? json_decode( wp_unslash( $_POST['query_context'] ), true ) : [];

		$facets  = $this->sanitize_facets( is_array( $raw_facets ) ? $raw_facets : [] );
		$sources = $this->sanitize_sources( is_array( $raw_sources ) ? $raw_sources : [] );
		$logic   = $this->sanitize_logic( is_array( $raw_logic ) ? $raw_logic : [] );
		$page    = absint( $_POST['page'] ?? 1 );
		if ( $page < 1 ) {
			$page = 1;
		}

		// Which facet/listing instance this request belongs to. Not needed for
		// query correctness (the JS already scopes selections per instance
		// before sending them, and each instance has its own AbortController),
		// but echoed back so the calling instance can sanity-check the
		// response is its own, and as a hook point for future per-group
		// template resolution.
		$group = sanitize_key( $_POST['group'] ?? 'main' );

		$query_context = $this->sanitize_query_context( is_array( $raw_context ) ? $raw_context : [] );

		// 3. Build base args from query_context.
		$base_args = array_merge( $query_context, [
			'paged' => $page,
		] );

		// Base args without paged for count calculations (counts reflect all pages).
		$base_args_without_paged = $query_context;

		// 4. Build full query args via the query builder.
		$args = $this->query_builder->build_query_args( $facets, $sources, $logic, $base_args );

		// 5. Run the query.
		$query = new WP_Query( $args );

		// 6. Capture HTML output.
		$template = sanitize_text_field( $_POST['template'] ?? '' );
		$html     = $this->capture_loop_html( $query, $template );

		// 7. Calculate counts.
		$counts = $this->count_calculator->calculate_all( $facets, $sources, $logic, $base_args_without_paged );

		// 8. Build and send response.
		$response = [
			'html'       => $html,
			'counts'     => $counts,
			'total'      => $query->found_posts,
			'max_pages'  => $query->max_num_pages,
			'page'       => $page,
			'group'      => $group,
			'query_args' => WP_DEBUG ? $args : null,
		];

		/**
		 * Filter the AJAX response before sending.
		 *
		 * @param array    $response The response data.
		 * @param WP_Query $query    The executed query.
		 * @param array    $facets   The sanitized facet selections.
		 * @param array    $sources  The sanitized source definitions.
		 */
		$response = apply_filters( 'etchfacets_ajax_response', $response, $query, $facets, $sources );

		wp_send_json_success( $response );
	}

	/**
	 * Handle the map marker AJAX request.
	 *
	 * Accepts the same input shape as handle_filter but returns a flat marker
	 * dataset (no pagination, no HTML) for plotting on the map. The map's own
	 * viewport (geo) facet is intentionally excluded so markers reflect every
	 * other facet — panning the map filters the list, not the marker set.
	 *
	 * @return void
	 */
	public function handle_map(): void {
		// 1. Verify nonce.
		if ( ! check_ajax_referer( 'etchfacets_nonce', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce', 403 );
		}

		// 2. Read and sanitize POST data.
		$raw_facets  = isset( $_POST['facets'] ) ? json_decode( wp_unslash( $_POST['facets'] ), true ) : [];
		$raw_sources = isset( $_POST['sources'] ) ? json_decode( wp_unslash( $_POST['sources'] ), true ) : [];
		$raw_logic   = isset( $_POST['logic'] ) ? json_decode( wp_unslash( $_POST['logic'] ), true ) : [];
		$raw_context = isset( $_POST['query_context'] ) ? json_decode( wp_unslash( $_POST['query_context'] ), true ) : [];

		$facets        = $this->sanitize_facets( is_array( $raw_facets ) ? $raw_facets : [] );
		$sources       = $this->sanitize_sources( is_array( $raw_sources ) ? $raw_sources : [] );
		$logic         = $this->sanitize_logic( is_array( $raw_logic ) ? $raw_logic : [] );
		$query_context = $this->sanitize_query_context( is_array( $raw_context ) ? $raw_context : [] );

		// See handle_filter() — echoed back only, not used for query building.
		$group = sanitize_key( $_POST['group'] ?? 'main' );

		// 3. Resolve the geo facet and its coordinate meta keys.
		$geo_facet = '';
		$lat_key   = '';
		$lng_key   = '';
		foreach ( $sources as $facet_name => $source_string ) {
			if ( EtchFacets_Query_Builder::is_geo_source( $source_string ) ) {
				$geo_facet = $facet_name;
				$parsed    = EtchFacets_Query_Builder::parse_source( $source_string );
				$keys      = array_map( 'trim', explode( ',', $parsed['value'] ) );
				$lat_key   = $keys[0] ?? '';
				$lng_key   = isset( $keys[1] ) ? $keys[1] : '';
				break;
			}
		}

		/**
		 * Filter the coordinate meta keys used to read marker positions.
		 *
		 * @param array  $keys      [lat_key, lng_key].
		 * @param string $geo_facet The geo facet name.
		 */
		[ $lat_key, $lng_key ] = apply_filters( 'etchfacets/map/coord_keys', [ $lat_key, $lng_key ], $geo_facet );

		if ( '' === $lat_key || '' === $lng_key ) {
			wp_send_json_error( 'No geo facet configured', 400 );
		}

		// 4. Markers reflect all facets EXCEPT the map viewport itself.
		$marker_facets = $facets;
		if ( $geo_facet ) {
			unset( $marker_facets[ $geo_facet ] );
		}

		/**
		 * Filter the maximum number of markers returned per request.
		 *
		 * @param int $max Default 500.
		 */
		$max = (int) apply_filters( 'etchfacets/map/max_markers', 500 );
		$max = max( 1, $max );

		// 5. Build the query, restricted to posts that have both coordinates.
		$args                   = $this->query_builder->build_query_args( $marker_facets, $sources, $logic, $query_context );
		$args['fields']         = 'ids';
		$args['posts_per_page'] = $max + 1; // +1 to detect truncation.
		$args['paged']          = 1;
		$args['no_found_rows']  = true;

		$geo_exists = [
			'relation' => 'AND',
			[ 'key' => $lat_key, 'compare' => 'EXISTS' ],
			[ 'key' => $lng_key, 'compare' => 'EXISTS' ],
		];

		if ( ! empty( $args['meta_query'] ) ) {
			$args['meta_query'] = [
				'relation' => 'AND',
				$args['meta_query'],
				$geo_exists,
			];
		} else {
			$args['meta_query'] = $geo_exists;
		}

		// 6. Run the query.
		$query = new WP_Query( $args );
		$ids   = $query->posts;

		$truncated = count( $ids ) > $max;
		if ( $truncated ) {
			$ids = array_slice( $ids, 0, $max );
		}

		// 7. Prime the meta + term caches, then build the marker payload.
		$post_type = $query_context['post_type'] ?? 'post';
		if ( ! empty( $ids ) ) {
			update_meta_cache( 'post', $ids );
			update_object_term_cache( $ids, $post_type );
		}
		$taxonomies = get_object_taxonomies( $post_type, 'names' );

		$markers = [];
		foreach ( $ids as $id ) {
			$lat = get_post_meta( $id, $lat_key, true );
			$lng = get_post_meta( $id, $lng_key, true );

			if ( '' === $lat || '' === $lng || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
				continue;
			}

			// Terms per taxonomy — lets the frontend color-code markers (via
			// data-etchfacet-color-taxonomy) without a second request per post.
			$terms = [];
			foreach ( $taxonomies as $taxonomy ) {
				$post_terms = get_the_terms( $id, $taxonomy );
				if ( is_array( $post_terms ) && ! empty( $post_terms ) ) {
					$terms[ $taxonomy ] = array_map(
						static fn( WP_Term $term ): array => [ 'slug' => $term->slug, 'name' => $term->name ],
						$post_terms
					);
				}
			}

			// Fall back to a conventional `_ef_photo` meta URL when the post has
			// no real featured image — useful for demo/seed data that isn't
			// worth uploading actual media attachments for.
			$thumb = get_the_post_thumbnail_url( $id, 'medium' );
			if ( ! $thumb ) {
				$thumb = get_post_meta( $id, '_ef_photo', true );
			}

			$marker = [
				'id'    => (int) $id,
				'lat'   => (float) $lat,
				'lng'   => (float) $lng,
				'title' => get_the_title( $id ),
				'url'   => get_permalink( $id ),
				'thumb' => (string) ( $thumb ?: '' ),
				'terms' => $terms,
			];

			/**
			 * Filter a single marker payload.
			 *
			 * @param array $marker The marker data.
			 * @param int   $id     The post ID.
			 */
			$marker = apply_filters( 'etchfacets/map/marker', $marker, $id );

			if ( ! empty( $marker ) ) {
				$markers[] = $marker;
			}
		}

		/**
		 * Filter the full marker array before sending.
		 *
		 * @param array $markers The marker dataset.
		 */
		$markers = apply_filters( 'etchfacets/map/markers', $markers );

		wp_send_json_success( [
			'markers'   => array_values( $markers ),
			'total'     => count( $markers ),
			'truncated' => $truncated,
			'group'     => $group,
		] );
	}

	/**
	 * Capture the loop HTML output for the given query.
	 *
	 * @param WP_Query $query    The query to loop through.
	 * @param string   $template Optional template part to use.
	 * @return string The captured HTML.
	 */
	private function capture_loop_html( WP_Query $query, string $template ): string {
		ob_start();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$this->render_post_item( get_the_ID(), $template );
			}
			wp_reset_postdata();
		} else {
			echo '<div class="etchfacets-no-results">' . esc_html__( 'No results found.', 'etchfacets' ) . '</div>';
		}

		return ob_get_clean();
	}

	/**
	 * Try to render a post item using Etch's block rendering if available.
	 * Falls back to a template part or basic markup.
	 *
	 * @param int    $post_id  The post ID to render.
	 * @param string $template The template part name to use.
	 * @return void
	 */
	private function render_post_item( int $post_id, string $template ): void {
		// Use a template part if provided and found.
		if ( $template && locate_template( $template ) ) {
			get_template_part( $template );
			return;
		}

		// Fall back to basic markup.
		echo '<article class="etchfacets-item" id="post-' . intval( $post_id ) . '">';
		echo '<h2>' . esc_html( get_the_title() ) . '</h2>';

		if ( has_post_thumbnail() ) {
			echo '<div class="etchfacets-thumbnail">' . get_the_post_thumbnail( $post_id, 'medium' ) . '</div>';
		}

		echo '<div class="etchfacets-excerpt">' . wp_kses_post( get_the_excerpt() ) . '</div>';
		echo '</article>';
	}

	/**
	 * Sanitize the facets array from POST data.
	 *
	 * Each key is sanitized with sanitize_key(), each value array
	 * is sanitized with sanitize_text_field().
	 *
	 * @param mixed $raw Raw facets data.
	 * @return array Sanitized facets.
	 */
	private function sanitize_facets( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $raw as $key => $values ) {
			$safe_key = sanitize_key( $key );
			if ( is_array( $values ) ) {
				$sanitized[ $safe_key ] = array_map( 'sanitize_text_field', $values );
			} else {
				$sanitized[ $safe_key ] = [ sanitize_text_field( $values ) ];
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize the sources array from POST data.
	 *
	 * @param mixed $raw Raw sources data.
	 * @return array Sanitized sources.
	 */
	private function sanitize_sources( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $raw as $key => $value ) {
			$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( $value );
		}

		return $sanitized;
	}

	/**
	 * Sanitize the logic array from POST data.
	 *
	 * @param mixed $raw Raw logic data.
	 * @return array Sanitized logic.
	 */
	private function sanitize_logic( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $raw as $key => $value ) {
			$sanitized[ sanitize_key( $key ) ] = sanitize_key( $value );
		}

		return $sanitized;
	}

	/**
	 * Sanitize the query_context from POST data.
	 *
	 * @param mixed $raw Raw query context data.
	 * @return array Sanitized query context.
	 */
	private function sanitize_query_context( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$context = [];

		if ( isset( $raw['post_type'] ) ) {
			$context['post_type'] = sanitize_key( $raw['post_type'] );
		}

		$posts_per_page = absint( $raw['posts_per_page'] ?? 12 );
		$context['posts_per_page'] = min( $posts_per_page, 100 );
		if ( $context['posts_per_page'] < 1 ) {
			$context['posts_per_page'] = 12;
		}

		$context['orderby'] = sanitize_key( $raw['orderby'] ?? 'date' );

		$order = strtoupper( sanitize_text_field( $raw['order'] ?? 'DESC' ) );
		$context['order'] = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';

		// Pass through tax_query and meta_query if present (base query filters, not facet filters).
		if ( isset( $raw['tax_query'] ) && is_array( $raw['tax_query'] ) ) {
			$context['tax_query'] = $raw['tax_query'];
		}

		if ( isset( $raw['meta_query'] ) && is_array( $raw['meta_query'] ) ) {
			$context['meta_query'] = $raw['meta_query'];
		}

		return $context;
	}
}
