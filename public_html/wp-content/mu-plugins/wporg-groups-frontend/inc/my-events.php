<?php
/**
 * Resolves the dates a member counts as "mine" for the my-events block.
 *
 * Extracted from the block's `render.php` so the definition of "my events"
 * is testable on its own, and so the template is left with rendering.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\My_Events;

defined( 'WPINC' ) || die();

/**
 * Upcoming dates for a member, soonest first.
 *
 * "Mine" means either of two things, and an organizer is usually both:
 *
 * - dates the member has RSVP'd to as attending,
 * - events the member authored, which on a group site means the ones they
 *   organize.
 *
 * Authorship is unioned in rather than written into the RSVP data, so
 * "attending" keeps meaning that the person said they are coming. That
 * distinction matters because the RSVP data feeds attendee counts, and
 * an organizer who creates an event they will not personally attend is a
 * normal thing on a group with several organizers.
 *
 * A date, not an event, because a recurring series is one post with many
 * dates (#2056). An RSVP belongs to the occurrence it was made on, so that
 * is the date the member is shown, and two RSVPs to the same series are two
 * entries. Anything without an occurrence — every non-recurring event —
 * carries an empty recurrence id and is the post's own date.
 *
 * @param int $user_id Member to resolve dates for.
 *
 * @return array<int, array{event_id: int, recurrence_id: string, start: string}>
 *         Upcoming dates ordered by start time ascending. `start` is local to
 *         the event, matching what GatherPress stores.
 */
function get_upcoming_events( int $user_id ): array {
	if ( ! $user_id ) {
		return array();
	}

	$candidates = array_merge(
		get_attending_candidates( $user_id ),
		get_authored_candidates( $user_id )
	);

	if ( empty( $candidates ) ) {
		return array();
	}

	return filter_to_upcoming( $candidates );
}

/**
 * Dates the member has RSVP'd to as attending.
 *
 * @param int $user_id Member to resolve RSVPs for.
 *
 * @return array<int, array{event_id: int, recurrence_id: string}> Unordered.
 */
function get_attending_candidates( int $user_id ): array {
	$rsvp_comments = get_comments(
		array(
			'user_id' => $user_id,
			'type'    => 'gatherpress_rsvp',
			'status'  => 'approve',
			'number'  => 100,
		)
	);

	if ( empty( $rsvp_comments ) ) {
		return array();
	}

	$comment_event_ids = array();
	foreach ( $rsvp_comments as $rsvp_comment ) {
		$comment_event_ids[ (int) $rsvp_comment->comment_ID ] = (int) $rsvp_comment->comment_post_ID;
	}

	$rsvp_terms = wp_get_object_terms(
		array_keys( $comment_event_ids ),
		'_gatherpress_rsvp_status',
		array( 'fields' => 'all_with_object_id' )
	);

	if ( is_wp_error( $rsvp_terms ) ) {
		return array();
	}

	// Filter to attending RSVPs without re-querying per event.
	$attending = array();
	foreach ( $rsvp_terms as $rsvp_term ) {
		if ( 'attending' !== $rsvp_term->slug ) {
			continue;
		}

		$comment_id = (int) $rsvp_term->object_id;
		$event_id   = $comment_event_ids[ $comment_id ] ?? 0;

		if ( $event_id ) {
			$attending[ $comment_id ] = $event_id;
		}
	}

	$recurrence_ids = get_comment_recurrence_ids( array_keys( $attending ) );
	$candidates     = array();

	foreach ( $attending as $comment_id => $event_id ) {
		$candidates[] = array(
			'event_id'      => $event_id,
			'recurrence_id' => $recurrence_ids[ $comment_id ] ?? '',
		);
	}

	return $candidates;
}

/**
 * Events the member authored.
 *
 * Carries no recurrence id: which of a series' dates to show an organizer is
 * a question for `filter_to_upcoming()`, which can see whether they already
 * RSVP'd to one.
 *
 * @param int $user_id Member to resolve authored events for.
 *
 * @return array<int, array{event_id: int, recurrence_id: string}> Unordered.
 */
function get_authored_candidates( int $user_id ): array {
	$event_ids = get_posts(
		array(
			'post_type'      => 'gatherpress_event',
			'post_status'    => 'publish',
			'author'         => $user_id,
			'fields'         => 'ids',
			'posts_per_page' => 100,
		)
	);

	return array_map(
		static function ( $event_id ): array {
			return array(
				'event_id'      => (int) $event_id,
				'recurrence_id' => '',
			);
		},
		$event_ids
	);
}

/**
 * The occurrence each RSVP was made on, for the RSVPs that have one.
 *
 * Only RSVPs to a recurring series are mapped, so a missing entry means a
 * plain event — or an RSVP older than the recurring-events extension.
 *
 * @param int[] $comment_ids RSVP comment IDs.
 *
 * @return array<int, string> Recurrence ID keyed by comment ID.
 */
function get_comment_recurrence_ids( array $comment_ids ): array {
	global $wpdb;

	if ( empty( $comment_ids ) || ! class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Database' ) ) {
		return array();
	}

	$table        = \WordPressdotorg\GatherPress_Recurring_Events\Database::comments_table();
	$placeholders = implode( ', ', array_fill( 0, count( $comment_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are generated locally.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- As above.
			"SELECT comment_id, recurrence_id FROM {$table} WHERE comment_id IN ( {$placeholders} )",
			$comment_ids
		)
	);

	$recurrence_ids = array();
	foreach ( (array) $rows as $row ) {
		$recurrence_ids[ (int) $row->comment_id ] = (string) $row->recurrence_id;
	}

	return $recurrence_ids;
}

/**
 * Reduce candidates to the dates that have not finished yet, soonest first.
 *
 * Reads the datetime tables directly, as the block already did: the dates
 * live there rather than in post meta that `WP_Query` could order by, and a
 * query per table beats instantiating an event per candidate.
 *
 * @param array<int, array{event_id: int, recurrence_id: string}> $candidates Candidate dates.
 *
 * @return array<int, array{event_id: int, recurrence_id: string, start: string}>
 *         Upcoming dates ordered by start time ascending.
 */
function filter_to_upcoming( array $candidates ): array {
	$unique = array();
	foreach ( $candidates as $candidate ) {
		$unique[ $candidate['event_id'] . '|' . $candidate['recurrence_id'] ] = $candidate;
	}

	$event_ids   = array_values( array_unique( array_column( $unique, 'event_id' ) ) );
	$series      = get_series_datetimes( $event_ids );
	$occurrences = get_upcoming_occurrences( $event_ids );
	$now         = current_time( 'mysql', true );

	$entries = array();

	// RSVP'd occurrences first, so an organizer who RSVP'd to one date of
	// their own series is shown that date rather than it and the series'
	// next one as two separate cards.
	foreach ( $unique as $candidate ) {
		if ( '' === $candidate['recurrence_id'] ) {
			continue;
		}

		$occurrence = $occurrences[ $candidate['event_id'] ][ $candidate['recurrence_id'] ] ?? null;

		// Absent means finished, cancelled, or no longer projected: an RSVP
		// to a date that is no longer happening is not upcoming.
		if ( $occurrence ) {
			$entries[] = array(
				'event_id'      => $candidate['event_id'],
				'recurrence_id' => $candidate['recurrence_id'],
				'start'         => $occurrence->datetime_start,
				'start_gmt'     => $occurrence->datetime_start_gmt,
			);
		}
	}

	$seen = array_column( $entries, 'event_id' );

	foreach ( $unique as $candidate ) {
		$event_id = $candidate['event_id'];

		if ( '' !== $candidate['recurrence_id'] || in_array( $event_id, $seen, true ) ) {
			continue;
		}

		$row = $series[ $event_id ] ?? null;

		if ( $row && $row->datetime_end_gmt >= $now ) {
			$entries[] = array(
				'event_id'      => $event_id,
				'recurrence_id' => '',
				'start'         => $row->datetime_start,
				'start_gmt'     => $row->datetime_start_gmt,
			);
			continue;
		}

		/*
		 * A recurring series stores only its first date in GatherPress's
		 * table, so once that one passes the row above says the series is
		 * over while it runs for months yet (#2056). Its next date stands
		 * in: the member has no RSVP pinning them to a particular one.
		 */
		$upcoming = $occurrences[ $event_id ] ?? array();
		$next     = $upcoming ? reset( $upcoming ) : null;

		if ( $next ) {
			$entries[] = array(
				'event_id'      => $event_id,
				'recurrence_id' => $next->recurrence_id,
				'start'         => $next->datetime_start,
				'start_gmt'     => $next->datetime_start_gmt,
			);
		}
	}

	usort(
		$entries,
		static function ( array $a, array $b ): int {
			return array( $a['start_gmt'], $a['event_id'] ) <=> array( $b['start_gmt'], $b['event_id'] );
		}
	);

	return array_map(
		static function ( array $entry ): array {
			unset( $entry['start_gmt'] );

			return $entry;
		},
		$entries
	);
}

/**
 * The date GatherPress stores for each event.
 *
 * For a recurring series that is its first date, which is why
 * `filter_to_upcoming()` falls back to the occurrence table.
 *
 * @param int[] $event_ids Event post IDs.
 *
 * @return array<int, object> Row keyed by event ID.
 */
function get_series_datetimes( array $event_ids ): array {
	global $wpdb;

	if ( empty( $event_ids ) ) {
		return array();
	}

	$table        = sprintf( \GatherPress\Core\Event\Event::TABLE_FORMAT, $wpdb->prefix );
	$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are generated locally.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- As above.
			"SELECT post_id, datetime_start, datetime_start_gmt, datetime_end_gmt FROM {$table} WHERE post_id IN ( {$placeholders} )",
			$event_ids
		)
	);

	$series = array();
	foreach ( (array) $rows as $row ) {
		$series[ (int) $row->post_id ] = $row;
	}

	return $series;
}

/**
 * Every projected date of these events that has not finished, soonest first.
 *
 * Cancelled dates are left out: "My upcoming events" is a list of what the
 * member is going to, and a cancelled date is not that.
 *
 * @param int[] $event_ids Event post IDs.
 *
 * @return array<int, array<string, object>> Rows keyed by event ID, then by
 *         recurrence ID, each event's dates in ascending order.
 */
function get_upcoming_occurrences( array $event_ids ): array {
	global $wpdb;

	if ( empty( $event_ids ) || ! class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Database' ) ) {
		return array();
	}

	$table        = \WordPressdotorg\GatherPress_Recurring_Events\Database::occurrences_table();
	$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
	$query_args   = array_merge( $event_ids, array( current_time( 'mysql', true ) ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are generated locally.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- As above.
			"SELECT series_post_id, recurrence_id, datetime_start, datetime_start_gmt FROM {$table} WHERE series_post_id IN ( {$placeholders} ) AND datetime_end_gmt >= %s AND status <> 'cancelled' ORDER BY datetime_start_gmt ASC",
			$query_args
		)
	);

	$occurrences = array();
	foreach ( (array) $rows as $row ) {
		$occurrences[ (int) $row->series_post_id ][ (string) $row->recurrence_id ] = $row;
	}

	return $occurrences;
}

/**
 * The URL a member's date should link to.
 *
 * @param int    $event_id      Event post ID.
 * @param string $recurrence_id Occurrence the date belongs to, if any.
 *
 * @return string Permalink, or the occurrence's own URL for a series.
 */
function get_entry_url( int $event_id, string $recurrence_id ): string {
	if ( '' !== $recurrence_id && class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Context' ) ) {
		return \WordPressdotorg\GatherPress_Recurring_Events\Context::occurrence_url( $event_id, $recurrence_id );
	}

	return (string) get_permalink( $event_id );
}
