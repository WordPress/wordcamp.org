<?php
/**
 * RSVP cleanup for members who leave a group.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Leave_Cleanup;

use WordCamp\Groups\Frontend\My_Events;

defined( 'WPINC' ) || die();

/**
 * Cancel a departing member's RSVPs to dates that haven't happened yet.
 *
 * Leaving a group used to leave the RSVPs behind (#2022): the event page
 * went on counting the person as attending while "My upcoming events"
 * stopped showing them the event, so the two views disagreed and the
 * attendee count included someone who had gone.
 *
 * Only future dates are cancelled. Past ones are attendance history --
 * what the member actually did, and what the events-attended list (#2089)
 * reads -- so leaving a group is not a way to erase having been there.
 *
 * Cancelling is `wp_delete_comment()` plus a cache flush on the event,
 * which is precisely what GatherPress's own `no_status` path does
 * (`Rsvp\Storage::save()`), rather than a second way of unsaying an RSVP.
 * Note that with a trash configured -- the default -- that call trashes the
 * comment rather than deleting it, so the seat is released (every query
 * that counts an RSVP reads approved comments only) while the row stays
 * recoverable, and the recurring-events extension's `deleted_comment`
 * cleanup of the occurrence mapping runs when the trash is emptied rather
 * than now. A mapping row outliving its comment reads to nothing: the
 * mapping is only ever looked up for comment IDs that are still approved.
 *
 * @param int $user_id Member leaving the group.
 * @return int Number of RSVPs cancelled.
 */
function cancel_future_rsvps( int $user_id ): int {
	if ( ! $user_id || ! class_exists( '\GatherPress\Core\Rsvp\Rsvp' ) || ! class_exists( '\GatherPress\Core\Rsvp\Cache' ) ) {
		return 0;
	}

	$events_by_comment = get_rsvp_comments( $user_id );

	if ( empty( $events_by_comment ) ) {
		return 0;
	}

	$recurrence_ids = My_Events\get_comment_recurrence_ids( array_keys( $events_by_comment ) );
	$event_ids      = array_values( array_unique( $events_by_comment ) );
	$series         = My_Events\get_series_datetimes( $event_ids );
	$occurrences    = My_Events\get_upcoming_occurrences( $event_ids );
	$now            = current_time( 'mysql', true );

	$cancelled = 0;
	$touched   = array();

	foreach ( $events_by_comment as $comment_id => $event_id ) {
		if ( ! is_future_rsvp( $event_id, $recurrence_ids[ $comment_id ] ?? '', $series, $occurrences, $now ) ) {
			continue;
		}

		if ( wp_delete_comment( $comment_id ) ) {
			$touched[ $event_id ] = $event_id;
			++$cancelled;
		}
	}

	foreach ( $touched as $event_id ) {
		\GatherPress\Core\Rsvp\Cache::delete( $event_id );
	}

	return $cancelled;
}

/**
 * Whether an RSVP belongs to a date that hasn't finished yet.
 *
 * Deliberately not `My_Events\filter_to_upcoming()`: that stands the
 * series' *next* date in for a candidate carrying no occurrence, which is
 * the right call when deciding what to show a member and the wrong one
 * here -- it would read an RSVP to a finished date as upcoming and delete
 * a piece of attendance history.
 *
 * The cost of that is one case this does not catch: an RSVP to a recurring
 * series carrying no occurrence -- one predating the recurring-events
 * extension, since it maps every RSVP it sees -- falls back to the series'
 * stored date, which is its *first*. A series that started before today
 * keeps such an RSVP even though later dates are still to come. Erring
 * that way is the point: an uncancelled seat is visible and fixable, a
 * deleted attendance record is neither.
 *
 * @param int                  $event_id      Event the RSVP is on.
 * @param string               $recurrence_id Occurrence the RSVP was made on, if any.
 * @param array<int, object>   $series        Series datetimes keyed by event ID.
 * @param array<int, object[]> $occurrences   Upcoming occurrences keyed by event ID, then recurrence ID.
 * @param string               $now           Current GMT datetime, MySQL format.
 * @return bool
 */
function is_future_rsvp( int $event_id, string $recurrence_id, array $series, array $occurrences, string $now ): bool {
	// A mapped RSVP is pinned to one date. Absent from the upcoming set
	// means finished, cancelled, or no longer projected -- none of which
	// is a seat to give back.
	if ( '' !== $recurrence_id ) {
		return isset( $occurrences[ $event_id ][ $recurrence_id ] );
	}

	$row = $series[ $event_id ] ?? null;

	return $row && $row->datetime_end_gmt >= $now;
}

/**
 * The member's RSVPs on this group site.
 *
 * Unlike `My_Events\get_attending_candidates()` this is uncapped and keeps
 * every status: a waiting-list place has to be given up as much as an
 * attending one, and a member with more than a hundred RSVPs should not
 * keep the overflow.
 *
 * @param int $user_id Member to resolve RSVPs for.
 * @return array<int, int> Event ID keyed by RSVP comment ID.
 */
function get_rsvp_comments( int $user_id ): array {
	$comments = get_comments(
		array(
			'user_id' => $user_id,
			'type'    => \GatherPress\Core\Rsvp\Rsvp::COMMENT_TYPE,
			'status'  => 'approve',
			'number'  => 0,
		)
	);

	$events_by_comment = array();

	foreach ( $comments as $comment ) {
		$events_by_comment[ (int) $comment->comment_ID ] = (int) $comment->comment_post_ID;
	}

	return $events_by_comment;
}
