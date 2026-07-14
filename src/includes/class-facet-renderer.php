<?php
declare(strict_types=1);

/**
 * EtchFacets Facet Renderer
 *
 * Handles shortcode registration and PHP render helpers for facets.
 *
 * @package EtchFacets
 */

class EtchFacets_Facet_Renderer {

	/**
	 * Register shortcodes.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'etchfacets_listing', [ $this, 'render_listing_shortcode' ] );
		add_shortcode( 'etchfacets_facet', [ $this, 'render_facet_shortcode' ] );
		add_shortcode( 'etchfacets_map', [ $this, 'render_map_shortcode' ] );
		add_shortcode( 'etchfacets_reset', [ $this, 'render_reset_shortcode' ] );
		add_shortcode( 'etchfacets_results_count', [ $this, 'render_results_count_shortcode' ] );
	}

	/**
	 * Render the [etchfacets_listing] shortcode.
	 *
	 * Outputs a listing container with query config as a data attribute
	 * and an initial server-rendered loop for progressive enhancement.
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Shortcode content (unused).
	 * @return string The rendered HTML.
	 */
	public function render_listing_shortcode( $atts, ?string $content = null ): string {
		$atts = shortcode_atts( [
			'post_type'      => 'post',
			'posts_per_page' => 12,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'class'          => '',
			'id'             => 'main',
		], $atts, 'etchfacets_listing' );

		$post_type      = sanitize_key( $atts['post_type'] );
		$posts_per_page = absint( $atts['posts_per_page'] );
		$posts_per_page = min( max( $posts_per_page, 1 ), 100 );
		$orderby        = sanitize_key( $atts['orderby'] );
		$order          = in_array( strtoupper( $atts['order'] ), [ 'ASC', 'DESC' ], true )
			? strtoupper( $atts['order'] )
			: 'DESC';
		$extra_class    = sanitize_html_class( $atts['class'] );
		$template_id    = sanitize_key( $atts['id'] );

		$query_config = [
			'post_type'      => $post_type,
			'posts_per_page' => $posts_per_page,
			'orderby'        => $orderby,
			'order'          => $order,
		];

		// Run the initial query for progressive enhancement.
		$query = new WP_Query( $query_config );

		$classes = 'etchfacets-template';
		if ( $extra_class ) {
			$classes .= ' ' . $extra_class;
		}

		$html  = '<div class="' . esc_attr( $classes ) . '"';
		$html .= ' data-etchfacets-query="' . esc_attr( wp_json_encode( $query_config ) ) . '"';
		$html .= ' data-etchfacets-id="' . esc_attr( $template_id ) . '">';

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();

				$post_id = get_the_ID();
				$html   .= '<article class="etchfacets-item" id="post-' . intval( $post_id ) . '">';
				$html   .= '<h2>' . esc_html( get_the_title() ) . '</h2>';

				if ( has_post_thumbnail() ) {
					$html .= '<div class="etchfacets-thumbnail">' . get_the_post_thumbnail( $post_id, 'medium' ) . '</div>';
				}

				$html .= '<div class="etchfacets-excerpt">' . wp_kses_post( get_the_excerpt() ) . '</div>';
				$html .= '</article>';
			}
			wp_reset_postdata();
		} else {
			$html .= '<div class="etchfacets-no-results">' . esc_html__( 'No results found.', 'etchfacets' ) . '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the [etchfacets_facet] shortcode.
	 *
	 * Outputs a facet container with the appropriate UI type and data attributes.
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Shortcode content (unused).
	 * @return string The rendered HTML.
	 */
	public function render_facet_shortcode( $atts, ?string $content = null ): string {
		$atts = shortcode_atts( [
			'name'        => '',
			'source'      => '',
			'logic'       => 'or',
			'type'        => 'checkboxes',
			'label'       => '',
			'show_counts' => 'true',
			'class'       => '',
			'post_type'   => '',
			'hide_empty'  => 'true',
		], $atts, 'etchfacets_facet' );

		$name        = sanitize_key( $atts['name'] );
		$source      = sanitize_text_field( $atts['source'] );
		$logic       = sanitize_key( $atts['logic'] );
		$type        = sanitize_key( $atts['type'] );
		$label       = sanitize_text_field( $atts['label'] );
		$show_counts = filter_var( $atts['show_counts'], FILTER_VALIDATE_BOOLEAN );
		$extra_class = sanitize_html_class( $atts['class'] );
		$post_type   = sanitize_key( $atts['post_type'] );
		$hide_empty  = filter_var( $atts['hide_empty'], FILTER_VALIDATE_BOOLEAN );

		if ( ! $name || ! $source ) {
			return '';
		}

		$classes = 'etchfacets-facet';
		if ( $extra_class ) {
			$classes .= ' ' . $extra_class;
		}

		$html  = '<div class="' . esc_attr( $classes ) . '"';
		$html .= ' data-etchfacet="' . esc_attr( $name ) . '"';
		$html .= ' data-etchfacet-source="' . esc_attr( $source ) . '"';
		$html .= ' data-etchfacet-logic="' . esc_attr( $logic ) . '">';

		if ( $label ) {
			$html .= '<h3 class="etchfacets-facet-label">' . esc_html( $label ) . '</h3>';
		}

		switch ( $type ) {
			case 'search':
				$html .= $this->render_search_input();
				break;

			case 'range':
				$html .= $this->render_range_inputs( $source );
				break;

			case 'dropdown':
				$html .= $this->render_dropdown( $source, $name, $show_counts, $post_type, $hide_empty );
				break;

			case 'radio':
				$html .= $this->render_choices( $source, $show_counts, 'radio', $name, $post_type, $hide_empty );
				break;

			case 'checkboxes':
			default:
				$html .= $this->render_choices( $source, $show_counts, 'checkbox', $name, $post_type, $hide_empty );
				break;
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the [etchfacets_map] shortcode.
	 *
	 * Outputs a map facet container. The Google Maps viewport becomes a live
	 * "geo" filter and posts with the configured lat/lng meta are plotted as
	 * markers. Requires a Google Maps API key (Settings → EtchFacets).
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Shortcode content (unused).
	 * @return string The rendered HTML.
	 */
	public function render_map_shortcode( $atts, ?string $content = null ): string {
		$atts = shortcode_atts( [
			'name'       => 'map',
			'lat_key'    => '_ef_lat',
			'lng_key'    => '_ef_lng',
			'center'     => '0,0',
			'zoom'       => '11',
			'height'     => '480',
			'class'      => '',
		], $atts, 'etchfacets_map' );

		$name        = sanitize_key( $atts['name'] );
		$lat_key     = sanitize_text_field( $atts['lat_key'] );
		$lng_key     = sanitize_text_field( $atts['lng_key'] );
		$center      = sanitize_text_field( $atts['center'] );
		$zoom        = (int) $atts['zoom'];
		$height      = (int) $atts['height'];
		$extra_class = sanitize_html_class( $atts['class'] );

		if ( ! $name || ! $lat_key || ! $lng_key ) {
			return '';
		}

		$source  = 'geo:' . $lat_key . ',' . $lng_key;
		$classes = 'etchfacets-map';
		if ( $extra_class ) {
			$classes .= ' ' . $extra_class;
		}

		$html  = '<div class="' . esc_attr( $classes ) . '"';
		$html .= ' data-etchfacet="' . esc_attr( $name ) . '"';
		$html .= ' data-etchfacet-source="' . esc_attr( $source ) . '"';
		$html .= ' data-etchfacet-center="' . esc_attr( $center ) . '"';
		$html .= ' data-etchfacet-zoom="' . esc_attr( (string) $zoom ) . '"';
		$html .= ' data-etchfacet-min-height="' . esc_attr( (string) $height ) . '"';
		$html .= ' style="width:100%;height:' . esc_attr( (string) $height ) . 'px"></div>';

		return $html;
	}

	/**
	 * Render the [etchfacets_reset] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string The rendered HTML.
	 */
	public function render_reset_shortcode( $atts ): string {
		$atts = shortcode_atts( [
			'text' => 'Reset Filters',
		], $atts, 'etchfacets_reset' );

		$text = sanitize_text_field( $atts['text'] );

		return '<button class="etchfacets-reset" type="button">' . esc_html( $text ) . '</button>';
	}

	/**
	 * Render the [etchfacets_results_count] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string The rendered HTML.
	 */
	public function render_results_count_shortcode( $atts ): string {
		return '<span class="etchfacets-results-count" data-etchfacets-total></span>';
	}

	/**
	 * Render a search input for the search facet type.
	 *
	 * @return string The search input HTML.
	 */
	private function render_search_input(): string {
		return '<input type="search" placeholder="' . esc_attr__( 'Search...', 'etchfacets' ) . '" class="etchfacets-search-input">';
	}

	/**
	 * Render min/max number inputs for the range facet type.
	 *
	 * @param string $source The facet source (e.g., "meta_range:price").
	 * @return string The range inputs HTML.
	 */
	private function render_range_inputs( string $source ): string {
		global $wpdb;

		$min = 0;
		$max = 0;

		// Parse meta key from source.
		$parts = explode( ':', $source, 2 );
		if ( count( $parts ) === 2 && in_array( $parts[0], [ 'meta', 'meta_range' ], true ) ) {
			$meta_key = sanitize_key( $parts[1] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$range = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT MIN( CAST( meta_value AS DECIMAL(20,2) ) ) AS min_val, MAX( CAST( meta_value AS DECIMAL(20,2) ) ) AS max_val FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
					$meta_key
				)
			);

			if ( $range ) {
				$min = (int) floor( (float) $range->min_val );
				$max = (int) ceil( (float) $range->max_val );
			}
		}

		$html  = '<div class="etchfacets-range-inputs">';
		$html .= '<input type="number" class="etchfacet-min" placeholder="' . esc_attr__( 'Min', 'etchfacets' ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '">';
		$html .= '<span class="etchfacets-range-separator">—</span>';
		$html .= '<input type="number" class="etchfacet-max" placeholder="' . esc_attr__( 'Max', 'etchfacets' ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '">';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render checkbox or radio choices for a facet.
	 *
	 * @param string $source      The facet source (e.g., "taxonomy:category").
	 * @param bool   $show_counts Whether to show counts.
	 * @param string $input_type  The input type: 'checkbox' or 'radio'.
	 * @param string $facet_name  The facet name (used for radio input grouping).
	 * @param string $post_type   Optional. Scope taxonomy/meta choices to this post type.
	 * @param bool   $hide_empty  Whether to omit zero-count taxonomy terms.
	 * @return string The choices HTML.
	 */
	private function render_choices( string $source, bool $show_counts, string $input_type, string $facet_name, string $post_type = '', bool $hide_empty = true ): string {
		$choices = $this->get_choices( $source, $post_type, $hide_empty );
		$html    = '';

		foreach ( $choices as $choice ) {
			$html .= '<label class="etchfacets-' . esc_attr( $input_type ) . '">';
			$html .= '<input type="' . esc_attr( $input_type ) . '"';
			$html .= ' value="' . esc_attr( $choice['value'] ) . '"';
			if ( $input_type === 'radio' ) {
				$html .= ' name="' . esc_attr( $facet_name ) . '"';
			}
			$html .= '> ' . esc_html( $choice['label'] );

			if ( $show_counts ) {
				$html .= ' <span class="etchfacet-count">(' . intval( $choice['count'] ) . ')</span>';
			}

			$html .= '</label>';
		}

		return $html;
	}

	/**
	 * Render a dropdown select for a facet.
	 *
	 * @param string $source      The facet source.
	 * @param string $facet_name  The facet name.
	 * @param bool   $show_counts Whether to show counts.
	 * @param string $post_type   Optional. Scope taxonomy/meta choices to this post type.
	 * @param bool   $hide_empty  Whether to omit zero-count taxonomy terms.
	 * @return string The dropdown HTML.
	 */
	private function render_dropdown( string $source, string $facet_name, bool $show_counts, string $post_type = '', bool $hide_empty = true ): string {
		$choices = $this->get_choices( $source, $post_type, $hide_empty );

		$html  = '<select name="' . esc_attr( $facet_name ) . '">';
		$html .= '<option value="">' . esc_html__( 'All', 'etchfacets' ) . '</option>';

		foreach ( $choices as $choice ) {
			$label = esc_html( $choice['label'] );
			if ( $show_counts ) {
				$label .= ' (' . intval( $choice['count'] ) . ')';
			}
			$html .= '<option value="' . esc_attr( $choice['value'] ) . '">' . $label . '</option>';
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * Get available choices for a facet source.
	 *
	 * For taxonomy sources, retrieves terms via get_terms().
	 * For meta sources, queries distinct meta values from the database.
	 *
	 * Public so EtchFacets_Choices_Rest can reuse the same lookup logic for
	 * the native-Etch-component REST endpoint instead of duplicating it.
	 *
	 * @param string $source     The facet source (e.g., "taxonomy:category", "meta:color").
	 * @param string $post_type  Optional. Scope taxonomy/meta choices to this post type. Empty
	 *                           string preserves the legacy global (unscoped) lookup.
	 * @param bool   $hide_empty Whether to omit zero-count taxonomy terms. Ignored for meta sources.
	 * @return array Array of choices, each with 'value', 'label', and 'count' keys.
	 */
	public function get_choices( string $source, string $post_type = '', bool $hide_empty = true ): array {
		$parts = explode( ':', $source, 2 );
		if ( count( $parts ) !== 2 ) {
			return [];
		}

		$source_type = $parts[0];
		$source_key  = $parts[1];

		switch ( $source_type ) {
			case 'taxonomy':
				return $this->get_taxonomy_choices( sanitize_key( $source_key ), $post_type, $hide_empty );

			case 'meta':
				return $this->get_meta_choices( sanitize_key( $source_key ), $post_type );

			default:
				return [];
		}
	}

	/**
	 * Get choices from a taxonomy.
	 *
	 * A taxonomy can be shared across multiple post types (e.g. `category` on
	 * both `post` and a custom CPT). With no `$post_type`, this falls back to
	 * a plain `get_terms()` lookup — global across every post type using the
	 * taxonomy, matching this plugin's original behavior. With `$post_type`
	 * set, term presence and counts are scoped to posts of that type only, via
	 * direct SQL (get_terms() has no post-type-scoped hide_empty option).
	 *
	 * @param string $taxonomy   The taxonomy name.
	 * @param string $post_type  Optional. Scope to this post type.
	 * @param bool   $hide_empty Whether to omit terms with zero matching posts.
	 * @return array Array of choices.
	 */
	private function get_taxonomy_choices( string $taxonomy, string $post_type = '', bool $hide_empty = true ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		if ( '' === $post_type ) {
			$terms = get_terms( [
				'taxonomy'   => $taxonomy,
				'hide_empty' => $hide_empty,
			] );

			if ( is_wp_error( $terms ) ) {
				return [];
			}

			$choices = [];
			foreach ( $terms as $term ) {
				$choices[] = [
					'value' => $term->slug,
					'label' => $term->name,
					'count' => $term->count,
				];
			}

			return $choices;
		}

		global $wpdb;

		/**
		 * Filter the post statuses counted toward a post-type-scoped taxonomy
		 * facet's term counts.
		 *
		 * @param string[] $statuses  Post statuses to include. Default ['publish'].
		 * @param string   $taxonomy  Taxonomy being queried.
		 * @param string   $post_type Post type the choices are scoped to.
		 */
		$statuses = apply_filters( 'etchfacets/taxonomy_choices/post_status', [ 'publish' ], $taxonomy, $post_type );
		$statuses = array_map( 'sanitize_key', (array) $statuses );
		if ( empty( $statuses ) ) {
			$statuses = [ 'publish' ];
		}
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$having              = $hide_empty ? 'HAVING COUNT(p.ID) > 0' : '';

		// The post_type/post_status constraints live in the LEFT JOIN's ON
		// clause, not WHERE — putting them in WHERE would turn this into an
		// INNER JOIN and drop zero-count terms even when hide_empty is false.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.slug AS value, t.name AS label, COUNT(p.ID) AS count
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
			LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = %s AND p.post_status IN ({$status_placeholders})
			GROUP BY t.term_id
			{$having}
			ORDER BY t.name ASC",
			array_merge( [ $taxonomy, $post_type ], $statuses )
		) );

		if ( empty( $results ) ) {
			return [];
		}

		return array_map( function ( $row ) {
			return [
				'value' => $row->value,
				'label' => $row->label,
				'count' => (int) $row->count,
			];
		}, $results );
	}

	/**
	 * Get choices from post meta values.
	 *
	 * A meta key can be shared across multiple post types (e.g. `color` on
	 * both `product` and `event`). With `$post_type` set, distinct values are
	 * scoped to posts of that type only.
	 *
	 * @param string $meta_key  The meta key to query.
	 * @param string $post_type Optional. Scope to this post type.
	 * @return array Array of choices.
	 */
	private function get_meta_choices( string $meta_key, string $post_type = '' ): array {
		global $wpdb;

		if ( '' === $post_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_value, COUNT(*) AS count FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_status = 'publish' GROUP BY meta_value ORDER BY meta_value ASC",
					$meta_key
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_value, COUNT(*) AS count FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_status = 'publish' AND p.post_type = %s GROUP BY meta_value ORDER BY meta_value ASC",
					$meta_key,
					$post_type
				)
			);
		}

		if ( ! $results ) {
			return [];
		}

		$choices = [];
		foreach ( $results as $row ) {
			$choices[] = [
				'value' => $row->meta_value,
				'label' => $row->meta_value,
				'count' => (int) $row->count,
			];
		}

		return $choices;
	}
}

/**
 * Render a facet via PHP.
 *
 * A global helper function for rendering facets directly in theme templates.
 *
 * @param string $name    The facet name.
 * @param string $source  The facet source (e.g., "taxonomy:category").
 * @param array  $options Optional. Additional options: type, logic, label, show_counts, class,
 *                        post_type, hide_empty.
 * @return void
 */
function etchfacets_display( string $name, string $source, array $options = [] ): void {
	$renderer = new EtchFacets_Facet_Renderer();

	$atts = array_merge( [
		'name'        => $name,
		'source'      => $source,
		'type'        => 'checkboxes',
		'logic'       => 'or',
		'label'       => '',
		'show_counts' => 'true',
		'class'       => '',
		'post_type'   => '',
		'hide_empty'  => 'true',
	], $options );

	echo $renderer->render_facet_shortcode( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output is escaped within render_facet_shortcode.
}
