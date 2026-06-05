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
