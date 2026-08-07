<?php
/**
 * Occurrence-aware front-end event queries.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use WP_Post;
use WP_Query;

defined( 'WPINC' ) || die();

final class Query {

	private static array $contexts = array();

	/**
	 * Joins projected occurrences into GatherPress archive queries.
	 *
	 * @param array    $clauses SQL clauses.
	 * @param WP_Query $query   Post query.
	 * @return array Filtered SQL clauses.
	 */
	public static function clauses( array $clauses, WP_Query $query ): array {
		$type = self::type( $query );
		if ( ! $type || 'ids' === $query->get( 'fields' ) || is_admin() ) {
			return $clauses;
		}

		global $wpdb;
		$core_table       = $wpdb->prefix . 'gatherpress_events';
		$occurrence_table = Database::occurrences_table();

		if ( ! str_contains( $clauses['join'], $occurrence_table ) ) {
			$clauses['join'] .= $wpdb->prepare(
				' LEFT JOIN %i gpre_occ_query ON ' . $wpdb->posts . ".ID = gpre_occ_query.series_post_id AND {$wpdb->posts}.post_type = 'gatherpress_event'",
				$occurrence_table
			);
		}

		$start_expression   = "COALESCE(gpre_occ_query.datetime_start_gmt, {$core_table}.datetime_start_gmt)";
		$end_expression     = "COALESCE(gpre_occ_query.datetime_end_gmt, {$core_table}.datetime_end_gmt)";
		$clauses['where']   = str_replace( "{$core_table}.datetime_start_gmt", $start_expression, $clauses['where'] );
		$clauses['where']   = str_replace( "{$core_table}.datetime_end_gmt", $end_expression, $clauses['where'] );
		$clauses['orderby'] = str_replace( "{$core_table}.datetime_start_gmt", $start_expression, $clauses['orderby'] );

		$query->set( 'gpre_occurrence_query', $type );
		return $clauses;
	}

	/**
	 * Associates each duplicate series post with its exact SQL occurrence row.
	 *
	 * @param WP_Post[] $posts Queried posts.
	 * @param WP_Query  $query Post query.
	 * @return WP_Post[] Cloned posts carrying request-side occurrence context.
	 */
	public static function posts( array $posts, WP_Query $query ): array {
		if ( ! $query->get( 'gpre_occurrence_query' ) || ! $posts ) {
			return $posts;
		}

		global $wpdb;
		$request = trim( (string) $query->request );
		$request = preg_replace(
			'/^SELECT\s+(?:SQL_CALC_FOUND_ROWS\s+)?(?:DISTINCT\s+)?[^\n]+?\s+FROM\s+/i',
			'SELECT ' . $wpdb->posts . '.ID, gpre_occ_query.* FROM ',
			$request,
			1
		);

		if ( ! is_string( $request ) || ! str_contains( $request, 'gpre_occ_query.*' ) ) {
			return $posts;
		}

		// The statement is the already-prepared WP_Query request with only its SELECT list changed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $request );
		if ( count( $rows ) !== count( $posts ) ) {
			return $posts;
		}

		foreach ( $posts as $index => $post ) {
			if ( empty( $rows[ $index ]->recurrence_id ) ) {
				continue;
			}

			$posts[ $index ]                                     = clone $post;
			self::$contexts[ spl_object_id( $posts[ $index ] ) ] = $rows[ $index ];
		}

		return $posts;
	}

	/**
	 * Activates occurrence context as the Query Loop advances.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function activate( WP_Post $post ): void {
		if ( self::$contexts ) {
			Context::set( self::$contexts[ spl_object_id( $post ) ] ?? null );
		}
	}

	/**
	 * Determines whether a query is a GatherPress temporal archive query.
	 *
	 * @param WP_Query $query Post query.
	 * @return string Upcoming, past, or an empty string.
	 */
	private static function type( WP_Query $query ): string {
		$type = (string) $query->get( 'gatherpress_event_query' );
		return in_array( $type, array( 'upcoming', 'past' ), true ) ? $type : '';
	}
}
