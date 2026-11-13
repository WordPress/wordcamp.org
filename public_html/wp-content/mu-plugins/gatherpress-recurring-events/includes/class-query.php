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

	/** @var array<int, array<string, object>> Occurrence rows keyed by series post ID, then recurrence ID. */
	private static array $occurrences = array();

	/** @var WeakMap<WP_Query, array{0: ?object}>|null Context that was active when each occurrence loop started. */
	private static ?WeakMap $outer_contexts = null;

	/**
	 * Joins projected occurrences into GatherPress archive queries.
	 *
	 * @param array    $clauses SQL clauses.
	 * @param WP_Query $query   Post query.
	 * @return array Filtered SQL clauses.
	 */
	public static function clauses( array $clauses, WP_Query $query ): array {
		$type = self::type( $query );
		if ( ! $type || is_admin() ) {
			return $clauses;
		}

		global $wpdb;
		$core_table       = $wpdb->prefix . 'gatherpress_events';
		$occurrence_table = Database::occurrences_table();

		/**
		 * GatherPress writes the WHERE comparison through `$wpdb->prepare( '%i.%i' )`, which backtick-quotes both
		 * identifiers, but builds ORDER BY by plain concatenation. Accept either form so both clauses get the
		 * occurrence date.
		 */
		$pattern = '/(?<!\w)`?' . preg_quote( $core_table, '/' ) . '`?\.`?(datetime_(?:start|end)_gmt)`?(?!\w)/';
		$replace = static fn( array $matches ): string => "COALESCE(gpre_occ_query.{$matches[1]}, {$core_table}.{$matches[1]})";

		// A list of IDs can't say which date each stands for, so a series is listed once, by its nearest date that
		// isn't cancelled. A protected series is placed by its first date, the only one it shows without its password.
		if ( 'ids' === $query->get( 'fields' ) ) {
			if ( str_contains( $clauses['join'], 'gpre_occ_query' ) || ! preg_match( $pattern, $clauses['where'], $compared ) ) {
				return $clauses;
			}

			$upcoming = 'upcoming' === $type;
			$nearest  = $upcoming ? 'MIN' : 'MAX';
			$compare  = $upcoming ? '>=' : '<';

			// The column, function, and operator are fixed strings chosen above, not request input.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN (
					SELECT gpre_occ.series_post_id,
						{$nearest}(gpre_occ.datetime_start_gmt) AS datetime_start_gmt,
						{$nearest}(gpre_occ.datetime_end_gmt) AS datetime_end_gmt
					FROM %i gpre_occ
					INNER JOIN {$wpdb->posts} gpre_series ON gpre_series.ID = gpre_occ.series_post_id AND gpre_series.post_password = ''
					WHERE gpre_occ.status <> 'cancelled' AND gpre_occ.{$compared[1]} {$compare} %s
					GROUP BY gpre_occ.series_post_id
				) gpre_occ_query ON {$wpdb->posts}.ID = gpre_occ_query.series_post_id",
				$occurrence_table,
				current_time( 'mysql', true )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$clauses['where']   = preg_replace_callback( $pattern, $replace, $clauses['where'] );
			$clauses['orderby'] = preg_replace_callback( $pattern, $replace, $clauses['orderby'] );

			return $clauses;
		}

		if ( ! str_contains( $clauses['join'], $occurrence_table ) ) {
			$clauses['join'] .= $wpdb->prepare(
				' LEFT JOIN %i gpre_occ_query ON ' . $wpdb->posts . ".ID = gpre_occ_query.series_post_id AND {$wpdb->posts}.post_type = 'gatherpress_event'",
				$occurrence_table
			);
		}

		$clauses['where']   = preg_replace_callback( $pattern, $replace, $clauses['where'] );
		$clauses['orderby'] = preg_replace_callback( $pattern, $replace, $clauses['orderby'] );

		// Disable post-queries caching so occurrence queries never serve stale or mismatched post ID lists.
		// Occurrence rows carry per-row recurrence data that a cached post ID list cannot represent.
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

		foreach ( $posts as $index => $post ) {
			$recurrence_id = (string) ( $rows[ $index ]->recurrence_id ?? '' );
			if ( '' === $recurrence_id ) {
				continue;
			}

			/*
			 * The post carries only the recurrence ID, not the row. Core casts posts
			 * to arrays of strings, e.g. `is_attachment( $post )` in `get_post_class()`,
			 * and an object property there is a fatal error.
			 */
			$posts[ $index ]                  = clone $post;
			$posts[ $index ]->gpre_occurrence = $recurrence_id;

			self::$occurrences[ $post->ID ][ $recurrence_id ] = $rows[ $index ];
		}

		return $posts;
	}

	/**
	 * Activates occurrence context as the Query Loop advances.
	 *
	 * Core's Query Loop block resets post data when it finishes, which fires
	 * `the_post` for the main query's first post even though that query isn't
	 * looping. On an events archive that would bring back a date after
	 * `deactivate()` cleared it, so only a running loop counts.
	 *
	 * @param WP_Post       $post  Current post.
	 * @param WP_Query|null $query Post query instance when invoked by the_post action.
	 */
	public static function activate( WP_Post $post, ?WP_Query $query = null ): void {
		if ( $query && ( ! $query->in_the_loop || ! $query->get( 'gpre_occurrence_query' ) ) ) {
			return;
		}

		if ( isset( $post->gpre_occurrence, self::$occurrences[ $post->ID ][ $post->gpre_occurrence ] ) ) {
			Context::set( self::$occurrences[ $post->ID ][ $post->gpre_occurrence ] );
			return;
		}

		if ( $query && $query->get( 'gpre_occurrence_query' ) ) {
			Context::set( null );
		}
	}

	/**
	 * Remembers the context that was active before an occurrence loop starts.
	 *
	 * A loop of other events on a date's own page (e.g. "More events from
	 * this group") would otherwise leave that page without its date once the
	 * loop ends. Block themes render the whole template before `wp_head`, so
	 * the RSVP script data enqueued there would lose the recurrence ID.
	 *
	 * @param WP_Query $query Post query instance.
	 */
	public static function remember( WP_Query $query ): void {
		if ( ! $query->get( 'gpre_occurrence_query' ) ) {
			return;
		}

		self::$outer_contexts ??= new WeakMap();

		self::$outer_contexts[ $query ] = array( Context::get() );
	}

	/**
	 * Deactivates occurrence context when the Query Loop finishes, restoring
	 * whatever was active before it started.
	 *
	 * @param WP_Query|null $query Post query instance when invoked by loop_end action.
	 */
	public static function deactivate( ?WP_Query $query = null ): void {
		if ( $query && ! $query->get( 'gpre_occurrence_query' ) ) {
			return;
		}

		$outer = null;

		if ( $query && null !== self::$outer_contexts && isset( self::$outer_contexts[ $query ] ) ) {
			$outer = self::$outer_contexts[ $query ][0];
			unset( self::$outer_contexts[ $query ] );
		}

		Context::set( $outer );
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
