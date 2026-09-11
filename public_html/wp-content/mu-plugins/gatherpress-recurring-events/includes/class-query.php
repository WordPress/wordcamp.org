<?php
/**
 * Occurrence-aware front-end event queries.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use WeakMap;
use WP_Post;
use WP_Query;

defined( 'WPINC' ) || die();

final class Query {

	/** @var WeakMap<WP_Post, object>|null Occurrence context keyed by the exact cloned post object. */
	private static ?WeakMap $contexts = null;

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

		/**
		 * GatherPress writes the WHERE comparison through `$wpdb->prepare( '%i.%i' )`, which backtick-quotes both
		 * identifiers, but builds ORDER BY by plain concatenation. Accept either form so both clauses get the
		 * occurrence date.
		 */
		$pattern = '/(?<!\w)`?' . preg_quote( $core_table, '/' ) . '`?\.`?(datetime_(?:start|end)_gmt)`?(?!\w)/';
		$replace = static fn( array $matches ): string => "COALESCE(gpre_occ_query.{$matches[1]}, {$core_table}.{$matches[1]})";

		$clauses['where']   = preg_replace_callback( $pattern, $replace, $clauses['where'] );
		$clauses['orderby'] = preg_replace_callback( $pattern, $replace, $clauses['orderby'] );

		// Disable post-queries caching so occurrence queries never serve stale or mismatched post ID lists.
		$query->set( 'cache_results', false );

		$order = strtoupper( (string) ( $query->get( 'order' ) ?: 'ASC' ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = str_ends_with( trim( strtoupper( $clauses['orderby'] ) ), 'DESC' ) ? 'DESC' : 'ASC';
		}

		if ( ! empty( $clauses['orderby'] ) ) {
			$clauses['orderby'] .= ", COALESCE(gpre_occ_query.occurrence_id, 0) {$order}, {$wpdb->posts}.ID {$order}";
		}

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
			'/^SELECT\s+(?:SQL_CALC_FOUND_ROWS\s+)?(?:DISTINCT\s+)?.+?\s+FROM\s+/is',
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

		self::$contexts ??= new WeakMap();

		foreach ( $posts as $index => $post ) {
			if ( empty( $rows[ $index ]->recurrence_id ) ) {
				continue;
			}

			$posts[ $index ]                    = clone $post;
			$posts[ $index ]->gpre_occurrence   = $rows[ $index ];
			self::$contexts[ $posts[ $index ] ] = $rows[ $index ];
		}

		return $posts;
	}

	/**
	 * Activates occurrence context as the Query Loop advances.
	 *
	 * @param WP_Post       $post  Current post.
	 * @param WP_Query|null $query Post query instance when invoked by the_post action.
	 */
	public static function activate( WP_Post $post, ?WP_Query $query = null ): void {
		if ( $query && ! $query->get( 'gpre_occurrence_query' ) ) {
			return;
		}

		if ( isset( $post->gpre_occurrence ) ) {
			Context::set( $post->gpre_occurrence );
			return;
		}

		if ( null !== self::$contexts && isset( self::$contexts[ $post ] ) ) {
			Context::set( self::$contexts[ $post ] );
			return;
		}

		if ( $query && $query->get( 'gpre_occurrence_query' ) ) {
			Context::set( null );
		}
	}

	/**
	 * Deactivates occurrence context when the Query Loop finishes.
	 *
	 * @param WP_Query|null $query Post query instance when invoked by loop_end action.
	 */
	public static function deactivate( ?WP_Query $query = null ): void {
		if ( ! $query || $query->get( 'gpre_occurrence_query' ) ) {
			Context::set( null );
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
