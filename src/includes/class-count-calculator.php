<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculates per-choice counts for each facet.
 */
class EtchFacets_Count_Calculator {

	/**
	 * @var EtchFacets_Query_Builder
	 */
	private EtchFacets_Query_Builder $query_builder;

	/**
	 * @param EtchFacets_Query_Builder $query_builder Query builder instance.
	 */
	public function __construct( EtchFacets_Query_Builder $query_builder ) {
		$this->query_builder = $query_builder;
	}

	/**
	 * Calculate counts for all facets.
	 *
	 * For each facet, builds a query excluding that facet's selections,
	 * then counts how many matching posts have each possible value.
	 *
	 * @param array $facets    Current selections: ['facet_name' => ['value1', ...], ...]
	 * @param array $sources   Source definitions: ['facet_name' => 'taxonomy:category', ...]
	 * @param array $logic     Logic definitions: ['facet_name' => 'or', ...]
	 * @param array $base_args Base WP_Query args.
	 * @return array ['facet_name' => [['value' => 'slug', 'label' => 'Label', 'count' => 5], ...], ...]
	 */
	public function calculate_all( array $facets, array $sources, array $logic, array $base_args ): array {
		$counts       = [];
		$all_post_ids = null; // Lazy — only computed if a meta_range facet is present.

		foreach ( $sources as $facet_name => $source_string ) {
			$source = EtchFacets_Query_Builder::parse_source( $source_string );

			// Geo and sort facets have no enumerable choices to count.
			if ( in_array( $source['type'], [ 'geo', 'sort' ], true ) ) {
				$counts[ $facet_name ] = [];
				continue;
			}

			// A range facet's own bounds always span the ENTIRE post type,
			// ignoring every active facet — including its own. Unlike
			// checkbox/select counts (which SHOULD narrow to "what would
			// toggling this give you, given the other active facets"), a
			// slider's draggable extent has to stay fixed: if it narrowed
			// along with the other facets, clearing/resetting it would land
			// on a different min/max depending on what else was active,
			// instead of always returning to the same handles.
			if ( 'meta_range' === $source['type'] ) {
				if ( null === $all_post_ids ) {
					$all_post_ids = $this->get_all_post_ids( $base_args );
				}
				$counts[ $facet_name ] = $this->calculate_meta_range( $source['value'], $all_post_ids );
				continue;
			}

			// Viewport (geo) facets DO narrow other facets' counts, same as any
			// other active facet — get_post_ids_excluding() already excludes
			// only $facet_name itself, so a geo selection elsewhere in $facets
			// naturally carries through.
			$post_ids = $this->get_post_ids_excluding( $facet_name, $facets, $sources, $logic, $base_args );

			switch ( $source['type'] ) {
				case 'taxonomy':
					$counts[ $facet_name ] = $this->calculate_taxonomy_counts( $source['value'], $post_ids );
					break;

				case 'meta':
					$counts[ $facet_name ] = $this->calculate_meta_counts( $source['value'], $post_ids );
					break;

				default:
					$counts[ $facet_name ] = [];
					break;
			}
		}

		return $counts;
	}

	/**
	 * Get post IDs from a query that excludes a specific facet.
	 *
	 * @param string $facet_name Facet to exclude.
	 * @param array  $facets     All facet selections.
	 * @param array  $sources    Source definitions.
	 * @param array  $logic      Logic definitions.
	 * @param array  $base_args  Base query args.
	 * @return array Array of post IDs.
	 */
	private function get_post_ids_excluding( string $facet_name, array $facets, array $sources, array $logic, array $base_args ): array {
		$args = $this->query_builder->build_query_args_excluding( $facet_name, $facets, $sources, $logic, $base_args );

		$args['fields']         = 'ids';
		$args['posts_per_page'] = -1;
		$args['no_found_rows']  = true;

		$query = new WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Get every post ID for the base query, with no facet selections applied
	 * at all — the full, unfiltered post type.
	 *
	 * @param array $base_args Base query args (post_type, any base tax/meta query).
	 * @return array Array of post IDs.
	 */
	private function get_all_post_ids( array $base_args ): array {
		$args                   = $base_args;
		$args['fields']         = 'ids';
		$args['posts_per_page'] = -1;
		$args['no_found_rows']  = true;

		$query = new WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Calculate term counts for a taxonomy among a set of posts.
	 *
	 * NOTE: this query has no post_type guard of its own — it relies entirely
	 * on $post_ids already being post_type-scoped by the caller ($post_ids is
	 * built from $base_args, which always carries post_type; see
	 * class-ajax-handler.php::sanitize_query_context()). If a future caller
	 * ever passes unscoped $post_ids, counts here will span every post type
	 * sharing this taxonomy.
	 *
	 * Uses a LEFT JOIN from every term in the taxonomy, rather than an INNER
	 * JOIN starting from $post_ids's term relationships — a term with zero
	 * matches among $post_ids (e.g. one only ever used by posts of a
	 * different post type, for a taxonomy shared across post types) must
	 * still come back with count 0 instead of being omitted from the result
	 * entirely. Omitting it meant the frontend could never learn a choice had
	 * dropped to zero: updateCounts() in etchfacets.js only touches choices
	 * present in this response, so an absent one kept whatever count/format
	 * it had at initial render, and the etchfacet-ghost/etchfacet-hidden
	 * zero-count styling could never engage for it either.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $post_ids Post IDs to count within.
	 * @return array Array of ['value' => slug, 'label' => name, 'count' => N].
	 */
	private function calculate_taxonomy_counts( string $taxonomy, array $post_ids ): array {
		if ( empty( $post_ids ) ) {
			return [];
		}

		// This query matches directly against wp_term_taxonomy.taxonomy by
		// raw string, with no awareness of whether $taxonomy is a currently
		// registered WP taxonomy. Left unguarded, a facet source with a wrong
		// or mis-cased taxonomy name (get_taxonomy_choices() requires an exact,
		// case-sensitive taxonomy_exists() match — MySQL's default collation
		// doesn't) can still find real term_relationships rows and report a
		// plausible-looking nonzero count here, even though the same name will
		// never work as an actual tax_query — WP_Query's tax_query also
		// requires taxonomy_exists() and simply returns zero posts for a name
		// it doesn't recognize. That combination — a real-looking count that
		// then filters to nothing — is exactly what's confusing to debug from
		// the frontend, so refuse to compute a count for it at all.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					'EtchFacets: skipped counting taxonomy "%s" — not a registered taxonomy (check for a case mismatch or typo in this facet\'s source attribute against the real registered slug).',
					$taxonomy
				) );
			}
			return [];
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// The object_id-IN constraint lives in the LEFT JOIN's ON clause, not
		// WHERE — putting it in WHERE would turn this back into an INNER JOIN
		// and drop zero-count terms.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.slug AS value, t.name AS label, COUNT(tr.object_id) AS count
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
			LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tr.object_id IN ({$id_placeholders})
			GROUP BY t.term_id
			ORDER BY count DESC",
			array_merge( [ $taxonomy ], $post_ids )
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
	 * Calculate meta value counts for a meta key among a set of posts.
	 *
	 * @param string $meta_key Meta key to count.
	 * @param array  $post_ids Post IDs to count within.
	 * @return array Array of ['value' => meta_value, 'label' => meta_value, 'count' => N].
	 */
	private function calculate_meta_counts( string $meta_key, array $post_ids ): array {
		if ( empty( $post_ids ) ) {
			return [];
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_value AS value, COUNT(*) AS count
			FROM {$wpdb->postmeta}
			WHERE meta_key = %s
			AND post_id IN ({$id_placeholders})
			AND meta_value != ''
			GROUP BY meta_value
			ORDER BY count DESC",
			array_merge( [ $meta_key ], $post_ids )
		) );

		if ( empty( $results ) ) {
			return [];
		}

		return array_map( function ( $row ) {
			return [
				'value' => $row->value,
				'label' => $row->value,
				'count' => (int) $row->count,
			];
		}, $results );
	}

	/**
	 * Calculate min/max range for a numeric meta key among a set of posts.
	 *
	 * @param string $meta_key Meta key to get range for.
	 * @param array  $post_ids Post IDs to calculate within.
	 * @return array ['min' => N, 'max' => N] or empty array.
	 */
	private function calculate_meta_range( string $meta_key, array $post_ids ): array {
		if ( empty( $post_ids ) ) {
			return [];
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_row( $wpdb->prepare(
			"SELECT MIN(CAST(meta_value AS SIGNED)) AS min_val, MAX(CAST(meta_value AS SIGNED)) AS max_val
			FROM {$wpdb->postmeta}
			WHERE meta_key = %s
			AND post_id IN ({$id_placeholders})
			AND meta_value != ''",
			array_merge( [ $meta_key ], $post_ids )
		) );

		if ( empty( $result ) || null === $result->min_val ) {
			return [];
		}

		return [
			'min' => (int) $result->min_val,
			'max' => (int) $result->max_val,
		];
	}
}
