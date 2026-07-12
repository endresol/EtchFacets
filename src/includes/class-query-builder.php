<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates facet selections into WP_Query arguments.
 */
class EtchFacets_Query_Builder {

	/**
	 * Build WP_Query args from facet selections.
	 *
	 * @param array $facets  Associative array: ['facet_name' => ['value1', 'value2'], ...]
	 * @param array $sources Associative array: ['facet_name' => 'taxonomy:category', ...]
	 * @param array $logic   Associative array: ['facet_name' => 'or', ...] (default 'or')
	 * @param array $base_args Base WP_Query args (post_type, posts_per_page, etc.)
	 * @return array Complete WP_Query args.
	 */
	public function build_query_args( array $facets, array $sources, array $logic, array $base_args ): array {
		$args       = $base_args;
		$tax_query  = [];
		$meta_query = [];

		foreach ( $facets as $facet_name => $values ) {
			if ( empty( $values ) || ! isset( $sources[ $facet_name ] ) ) {
				continue;
			}

			$source     = self::parse_source( $sources[ $facet_name ] );
			$facet_logic = isset( $logic[ $facet_name ] ) ? strtolower( $logic[ $facet_name ] ) : 'or';

			$values = array_map( 'sanitize_text_field', $values );

			switch ( $source['type'] ) {
				case 'taxonomy':
					$tax_query[] = $this->build_taxonomy_clause( $source['value'], $values, $facet_logic );
					break;

				case 'meta':
					$meta_clauses = $this->build_meta_clause( $source['value'], $values, $facet_logic );
					foreach ( $meta_clauses as $clause ) {
						$meta_query[] = $clause;
					}
					break;

				case 'meta_range':
					$meta_query[] = $this->build_meta_range_clause( $source['value'], $values );
					break;

				case 'geo':
					$geo_clause = $this->build_geo_clause( $source['value'], $values );
					if ( ! empty( $geo_clause ) ) {
						$meta_query[] = $geo_clause;
					}
					break;

				case 'search':
					$args['s'] = sanitize_text_field( $values[0] );
					break;

				case 'author':
					$args['author__in'] = array_map( 'intval', $values );
					break;

				case 'date':
					$args['date_query'] = $this->build_date_clause( $values );
					break;

				case 'post_type':
					$args['post_type'] = array_map( 'sanitize_key', $values );
					break;

				case 'sort':
					[ $orderby, $order ] = $this->parse_sort_value( $values[0] );
					if ( $orderby ) {
						$args['orderby'] = $orderby;
						$args['order']   = $order;
					}
					break;
			}
		}

		if ( ! empty( $tax_query ) ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			$args['tax_query'] = $tax_query;
		}

		if ( ! empty( $meta_query ) ) {
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}
			$args['meta_query'] = $meta_query;
		}

		return $args;
	}

	/**
	 * Build WP_Query args excluding one facet (used for count calculation).
	 *
	 * @param string $exclude_facet Facet name to exclude.
	 * @param array  $facets        Associative array of all facet selections.
	 * @param array  $sources       Source definitions.
	 * @param array  $logic         Logic definitions.
	 * @param array  $base_args     Base query args.
	 * @return array WP_Query args without the excluded facet.
	 */
	public function build_query_args_excluding( string $exclude_facet, array $facets, array $sources, array $logic, array $base_args ): array {
		$filtered_facets = $facets;
		unset( $filtered_facets[ $exclude_facet ] );

		return $this->build_query_args( $filtered_facets, $sources, $logic, $base_args );
	}

	/**
	 * Parse a source string like "taxonomy:category" into its components.
	 *
	 * @param string $source Source string in "type:value" format.
	 * @return array ['type' => string, 'value' => string]
	 */
	public static function parse_source( string $source ): array {
		$parts = explode( ':', $source, 2 );

		return [
			'type'  => sanitize_key( $parts[0] ),
			'value' => isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '',
		];
	}

	/**
	 * Whether a source string describes a geo (map viewport) facet.
	 *
	 * @param string $source Source string in "type:value" format.
	 * @return bool True if the source type is "geo".
	 */
	public static function is_geo_source( string $source ): bool {
		return 'geo' === self::parse_source( $source )['type'];
	}

	/**
	 * Map a "sort" facet's selected value to WP_Query orderby/order.
	 *
	 * @param string $value Sort value, e.g. "date_desc", "title_asc", "relevance".
	 * @return array{0: string, 1: string} [ orderby, order ]. orderby is '' for
	 *                                     an unrecognized value (no-op).
	 */
	private function parse_sort_value( string $value ): array {
		$map = [
			'date_desc'  => [ 'date', 'DESC' ],
			'date_asc'   => [ 'date', 'ASC' ],
			'title_asc'  => [ 'title', 'ASC' ],
			'title_desc' => [ 'title', 'DESC' ],
			'relevance'  => [ 'relevance', 'DESC' ],
		];

		return $map[ $value ] ?? [ '', '' ];
	}

	/**
	 * Build a tax_query clause for a taxonomy facet.
	 *
	 * @param string $taxonomy   Taxonomy name.
	 * @param array  $values     Selected term slugs.
	 * @param string $facet_logic 'or' or 'and'.
	 * @return array Tax query clause.
	 */
	private function build_taxonomy_clause( string $taxonomy, array $values, string $facet_logic ): array {
		return [
			'taxonomy' => $taxonomy,
			'field'    => 'slug',
			'terms'    => $values,
			'operator' => 'and' === $facet_logic ? 'AND' : 'IN',
		];
	}

	/**
	 * Build meta_query clause(s) for a meta facet.
	 *
	 * @param string $meta_key   Meta key.
	 * @param array  $values     Selected meta values.
	 * @param string $facet_logic 'or' or 'and'.
	 * @return array Array of meta query clauses.
	 */
	private function build_meta_clause( string $meta_key, array $values, string $facet_logic ): array {
		if ( 'and' === $facet_logic ) {
			$clauses = [];
			foreach ( $values as $value ) {
				$clauses[] = [
					'key'     => $meta_key,
					'value'   => $value,
					'compare' => '=',
				];
			}
			return $clauses;
		}

		return [
			[
				'key'     => $meta_key,
				'value'   => count( $values ) > 1 ? $values : $values[0],
				'compare' => count( $values ) > 1 ? 'IN' : '=',
			],
		];
	}

	/**
	 * Build a meta_query clause for a meta range facet.
	 *
	 * @param string $meta_key Meta key.
	 * @param array  $values   Exactly 2 values: [min, max].
	 * @return array Meta query clause.
	 */
	private function build_meta_range_clause( string $meta_key, array $values ): array {
		return [
			'key'     => $meta_key,
			'value'   => [ (int) $values[0], (int) $values[1] ],
			'type'    => 'NUMERIC',
			'compare' => 'BETWEEN',
		];
	}

	/**
	 * Build a bounding-box meta_query clause for a geo (map viewport) facet.
	 *
	 * Source value is "lat_key,lng_key"; values are 4 floats:
	 * [swLat, swLng, neLat, neLng]. Handles antimeridian crossing
	 * (when the south-west longitude is greater than the north-east one).
	 *
	 * @param string $coord_keys Comma-separated "lat_key,lng_key".
	 * @param array  $values     Bounds: [swLat, swLng, neLat, neLng].
	 * @return array Meta query clause, or empty array if invalid.
	 */
	private function build_geo_clause( string $coord_keys, array $values ): array {
		// Require exactly four numeric values.
		if ( count( $values ) !== 4 || count( array_filter( $values, 'is_numeric' ) ) !== 4 ) {
			return [];
		}

		$keys    = array_map( 'trim', explode( ',', $coord_keys ) );
		$lat_key = $keys[0] ?? '';
		$lng_key = isset( $keys[1] ) ? $keys[1] : '';

		if ( '' === $lat_key || '' === $lng_key ) {
			return [];
		}

		[ $sw_lat, $sw_lng, $ne_lat, $ne_lng ] = array_map( 'floatval', $values );

		// Reject out-of-range coordinates.
		if ( $sw_lat < -90 || $sw_lat > 90 || $ne_lat < -90 || $ne_lat > 90 ) {
			return [];
		}
		if ( $sw_lng < -180 || $sw_lng > 180 || $ne_lng < -180 || $ne_lng > 180 ) {
			return [];
		}

		$lat_clause = [
			'key'     => $lat_key,
			'value'   => [ min( $sw_lat, $ne_lat ), max( $sw_lat, $ne_lat ) ],
			'type'    => 'DECIMAL(10,6)',
			'compare' => 'BETWEEN',
		];

		if ( $sw_lng <= $ne_lng ) {
			$lng_clause = [
				'key'     => $lng_key,
				'value'   => [ $sw_lng, $ne_lng ],
				'type'    => 'DECIMAL(10,6)',
				'compare' => 'BETWEEN',
			];
		} else {
			// Viewport crosses the antimeridian: match either side of ±180°.
			$lng_clause = [
				'relation' => 'OR',
				[ 'key' => $lng_key, 'value' => [ $sw_lng, 180.0 ], 'type' => 'DECIMAL(10,6)', 'compare' => 'BETWEEN' ],
				[ 'key' => $lng_key, 'value' => [ -180.0, $ne_lng ], 'type' => 'DECIMAL(10,6)', 'compare' => 'BETWEEN' ],
			];
		}

		return [
			'relation' => 'AND',
			$lat_clause,
			$lng_clause,
		];
	}

	/**
	 * Build a date_query clause from date values.
	 *
	 * @param array $values Date values: [after, before].
	 * @return array Date query array.
	 */
	private function build_date_clause( array $values ): array {
		$date_query = [];

		if ( isset( $values[0] ) && '' !== $values[0] ) {
			$date_query['after'] = sanitize_text_field( $values[0] );
		}

		if ( isset( $values[1] ) && '' !== $values[1] ) {
			$date_query['before'] = sanitize_text_field( $values[1] );
		}

		return [ $date_query ];
	}
}
