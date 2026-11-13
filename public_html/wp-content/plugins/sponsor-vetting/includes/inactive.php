<?php
/**
 * The Inactive status: a vetting request dropped without a decision, so nothing is left pending on it.
 *
 * A daily job moves companies out of Compliance Requested once the sponsor hasn't replied in COMPLIANCE_REPLY_DAYS
 * and no sponsorship waiting on the request has an event still to come. Going Inactive cancels what was pending.
 * Requesting vetting again for one of those camp sponsors reopens it, which puts the company back in the queue (see
 * queue_company()) or approves it if the company has been vetted since, and a reply from the sponsor puts the
 * company back in Compliance Requested.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

use WordPressdotorg\MU_Plugins\Utilities\FreeScout;

defined( 'WPINC' ) || die();

/**
 * Schedules the daily check on Central.
 *
 * Only on admin requests: the event recurs once scheduled, and vetters are in Central's admin every day.
 */
function schedule_inactive_check(): void {
	if ( is_central() && ! wp_next_scheduled( 'sv_check_inactive' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sv_check_inactive' );
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\schedule_inactive_check' );

/**
 * Moves each company in Compliance Requested to Inactive once its sponsor hasn't replied in COMPLIANCE_REPLY_DAYS
 * and none of its pending sponsorships has an event still to come.
 *
 * A company whose sponsor replied is waiting on a reviewer, and one approved in Good Faith still owes its changes,
 * so both stay. The conversation is read from FreeScout before the company moves, for a reply the hourly check
 * hasn't flagged yet and for a reviewer's message that restarted the wait.
 */
function deactivate_stalled_companies(): void {
	if ( ! is_central() ) {
		return;
	}

	$company_ids = get_posts(
		array(
			'post_type'      => COMPANY_POST_TYPE,
			'post_status'    => 'sv-compliance-req',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	$cutoff = time() - COMPLIANCE_REPLY_DAYS * DAY_IN_SECONDS;

	foreach ( $company_ids as $company_id ) {
		if ( get_post_meta( $company_id, '_sv_sponsor_replied', true ) || get_post_meta( $company_id, '_sv_good_faith_due', true ) ) {
			continue;
		}

		if ( get_compliance_requested_at( $company_id ) >= $cutoff ) {
			continue;
		}

		$pending = get_pending_sponsorships( $company_id );

		foreach ( $pending as $sponsorship_id ) {
			if ( ! event_has_ended( (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true ) ) ) {
				continue 2;
			}
		}

		if ( is_conversation_active( $company_id, $cutoff ) ) {
			continue;
		}

		$was_system = acting_as_system();
		acting_as_system( true );

		try {
			wp_update_post(
				array(
					'ID'          => $company_id,
					'post_status' => 'sv-inactive',
				)
			);

			if ( 'sv-inactive' !== get_post_status( $company_id ) ) {
				continue;
			}

			if ( $pending ) {
				/* translators: %d: The number of days. */
				$note = _n( 'Moved to Inactive automatically: the sponsor hasn’t replied to its compliance request in %d day, and the events it was being vetted for are over.', 'Moved to Inactive automatically: the sponsor hasn’t replied to its compliance request in %d days, and the events it was being vetted for are over.', COMPLIANCE_REPLY_DAYS, 'wordcamporg' );
			} else {
				/* translators: %d: The number of days. */
				$note = _n( 'Moved to Inactive automatically: the sponsor hasn’t replied to its compliance request in %d day, and no sponsorship is waiting on it.', 'Moved to Inactive automatically: the sponsor hasn’t replied to its compliance request in %d days, and no sponsorship is waiting on it.', COMPLIANCE_REPLY_DAYS, 'wordcamporg' );
			}

			add_note( $company_id, sprintf( $note, COMPLIANCE_REPLY_DAYS ) );
		} finally {
			acting_as_system( $was_system );
		}
	}
}
add_action( 'sv_check_inactive', __NAMESPACE__ . '\deactivate_stalled_companies' );

/**
 * When a company was last asked for its changes: its latest compliance request, or its last move into Compliance
 * Requested if that's newer. Runs on Central.
 *
 * The newer of the two, as imported requests carry the Airtable row's last change rather than when they were sent.
 * A reviewer's later message in the conversation counts too, which is_conversation_active() reads from FreeScout.
 *
 * @param int $company_id The company.
 * @return int A timestamp, or 0 if neither was recorded.
 */
function get_compliance_requested_at( int $company_id ): int {
	$times = array_merge(
		wp_list_pluck( get_conversations( $company_id, 'compliance' ), 'time' ),
		wp_list_pluck( wp_list_filter( get_post_meta( $company_id, '_sv_status_change', false ), array( 'to' => 'sv-compliance-req' ) ), 'time' )
	);

	return $times ? (int) max( $times ) : 0;
}

/**
 * Whether the company's latest compliance conversation is still going: the sponsor wrote last, which flags the
 * reply, or a reviewer wrote since the cutoff, which restarts the sponsor's wait.
 *
 * Counts FreeScout being unreachable too, so a company isn't moved on without its conversation being read.
 *
 * @param int $company_id The company.
 * @param int $cutoff     The time a reviewer's message has to be newer than.
 * @return bool
 */
function is_conversation_active( int $company_id, int $cutoff ): bool {
	$latest = get_latest_conversation( $company_id, 'compliance' );

	if ( ! $latest ) {
		return false;
	}

	$conversation = FreeScout::get( (int) $latest['id'] );

	if ( is_wp_error( $conversation ) ) {
		return true;
	}

	if ( sponsor_wrote_last( $conversation ) ) {
		update_post_meta( $company_id, '_sv_sponsor_replied', time() );

		return true;
	}

	return get_last_message_time( $conversation, 'message' ) >= $cutoff;
}

/**
 * Moves each Inactive company whose sponsor has replied to its compliance request back to Compliance Requested
 * and reopens the sponsorships that going Inactive canceled.
 *
 * Only a company that went Inactive from Compliance Requested, and only for a reply written since: a reviewer who
 * dropped the request had the earlier ones to read. The company is flagged for a reviewer unless one has answered
 * the reply in FreeScout already. A sponsorship whose event is over stays canceled, as the company turning
 * Compliant would approve it and email an event that has already happened.
 */
function reactivate_replied_companies(): void {
	if ( ! is_central() ) {
		return;
	}

	$company_ids = get_posts(
		array(
			'post_type'      => COMPANY_POST_TYPE,
			'post_status'    => 'sv-inactive',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $company_ids as $company_id ) {
		$dropped_at = get_compliance_dropped_at( $company_id );
		$latest     = $dropped_at ? get_latest_conversation( $company_id, 'compliance' ) : array();

		if ( ! $latest ) {
			continue;
		}

		$conversation = FreeScout::get( (int) $latest['id'] );

		if ( is_wp_error( $conversation ) || get_last_message_time( $conversation, 'customer' ) <= $dropped_at ) {
			continue;
		}

		$was_system = acting_as_system();
		acting_as_system( true );

		try {
			returning_from_inactive( $company_id );

			wp_update_post(
				array(
					'ID'          => $company_id,
					'post_status' => 'sv-compliance-req',
				)
			);

			if ( 'sv-compliance-req' !== get_post_status( $company_id ) ) {
				continue;
			}

			if ( sponsor_wrote_last( $conversation ) ) {
				update_post_meta( $company_id, '_sv_sponsor_replied', time() );
			}

			add_note( $company_id, __( 'Back in Compliance Requested automatically: the sponsor replied to its compliance request.', 'wordcamporg' ) );

			$canceled = get_posts(
				array(
					'post_type'      => SPONSORSHIP_POST_TYPE,
					'post_status'    => 'svs-canceled',
					'post_parent'    => $company_id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			foreach ( $canceled as $sponsorship_id ) {
				if ( ! event_has_ended( (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true ) ) ) {
					reopen_for_inactive( $sponsorship_id, __( 'Reopened: the sponsor replied to the compliance request.', 'wordcamporg' ) );
				}
			}
		} finally {
			returning_from_inactive( 0 );
			acting_as_system( $was_system );
		}
	}
}
add_action( 'sv_check_inactive', __NAMESPACE__ . '\reactivate_replied_companies' );

/**
 * When a company went Inactive, if that was from Compliance Requested. Runs on Central.
 *
 * Restoring it from the trash isn't taken for the move.
 *
 * @param int $company_id The company.
 * @return int A timestamp, or 0 if it went Inactive from another status.
 */
function get_compliance_dropped_at( int $company_id ): int {
	$moves = array_filter(
		get_post_meta( $company_id, '_sv_status_change', false ),
		static fn( array $change ): bool => 'sv-inactive' === $change['to'] && 'trash' !== $change['from']
	);
	$move  = $moves ? end( $moves ) : array();

	return 'sv-compliance-req' === ( $move['from'] ?? '' ) ? (int) $move['time'] : 0;
}

/**
 * Tracks the company a sponsor's reply is bringing back from Inactive, so only reactivate_replied_companies() can
 * move one straight to Compliance Requested.
 *
 * Kept in memory, not in the request or the database, so no form field or meta value can unlock it.
 *
 * @param int|null $company_id The company coming back, 0 when done, or null to read.
 * @return int The company coming back, or 0.
 */
function returning_from_inactive( ?int $company_id = null ): int {
	static $current = 0;

	if ( null !== $company_id ) {
		$current = $company_id;
	}

	return $current;
}

/**
 * Cancels a company's pending sponsorships once it's Inactive, however it got there.
 *
 * Nothing is left waiting on an Inactive company: a sponsorship left pending would read "In review" to its
 * organizers indefinitely. The cancellations are put down to whoever made the company Inactive: a reviewer, or the
 * daily job as the system. Restoring a company from the trash isn't a new decision.
 *
 * @param int           $post_id     The post ID.
 * @param \WP_Post      $post        The post.
 * @param bool          $update      Whether this is an update.
 * @param \WP_Post|null $post_before The post before the save, if it existed.
 */
function cancel_inactive_sponsorships( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
	if ( COMPANY_POST_TYPE !== $post->post_type || 'sv-inactive' !== $post->post_status || in_array( $post_before?->post_status, array( 'sv-inactive', 'trash' ), true ) ) {
		return;
	}

	foreach ( get_pending_sponsorships( $post->ID ) as $sponsorship_id ) {
		wp_update_post(
			array(
				'ID'          => $sponsorship_id,
				'post_status' => 'svs-canceled',
			)
		);

		update_post_meta( $sponsorship_id, '_sv_canceled_inactive', 1 );
		add_note( $sponsorship_id, __( 'Canceled automatically: its company is Inactive.', 'wordcamporg' ) );
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\cancel_inactive_sponsorships', 10, 4 );

/**
 * Whether a sponsorship was canceled because its company went Inactive, rather than by a reviewer.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return bool
 */
function is_canceled_for_inactive( int $sponsorship_id ): bool {
	return 'svs-canceled' === get_post_status( $sponsorship_id ) && (bool) get_post_meta( $sponsorship_id, '_sv_canceled_inactive', true );
}

/**
 * Reopens a sponsorship canceled because its company went Inactive, when vetting is requested for it again or the
 * sponsor replies.
 *
 * Runs on Central.
 *
 * @param int    $sponsorship_id The sponsorship.
 * @param string $note           Why it's reopened, for its history.
 * @return bool Whether it was reopened.
 */
function reopen_for_inactive( int $sponsorship_id, string $note ): bool {
	if ( ! is_canceled_for_inactive( $sponsorship_id ) ) {
		return false;
	}

	wp_update_post(
		array(
			'ID'          => $sponsorship_id,
			'post_status' => 'svs-pending',
		)
	);

	add_note( $sponsorship_id, $note );

	return 'svs-pending' === get_post_status( $sponsorship_id );
}

/**
 * Forgets why a sponsorship was canceled once it's reopened, so a reviewer canceling it later isn't undone by the
 * next request.
 *
 * @param string   $new_status The new status.
 * @param string   $old_status The previous status.
 * @param \WP_Post $post       The record.
 */
function forget_inactive_cancellation( string $new_status, string $old_status, \WP_Post $post ): void {
	if ( SPONSORSHIP_POST_TYPE === $post->post_type && 'svs-canceled' === $old_status && 'svs-canceled' !== $new_status && 'trash' !== $new_status ) {
		delete_post_meta( $post->ID, '_sv_canceled_inactive' );
	}
}
add_action( 'transition_post_status', __NAMESPACE__ . '\forget_inactive_cancellation', 10, 3 );

/**
 * Gets a company's pending sponsorships. Runs on Central.
 *
 * @param int $company_id The company.
 * @return int[]
 */
function get_pending_sponsorships( int $company_id ): array {
	return get_posts(
		array(
			'post_type'      => SPONSORSHIP_POST_TYPE,
			'post_status'    => 'svs-pending',
			'post_parent'    => $company_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
}

/**
 * Whether a camp's event is over: canceled, or its end date, or its start date for a one-day event, is before today.
 *
 * Every `wordcamp` post for the site has to be over, in case it has more than one. An event without a date, or
 * without a `wordcamp` post, isn't taken for over. Runs on Central.
 *
 * @param int $blog_id The camp site.
 * @return bool
 */
function event_has_ended( int $blog_id ): bool {
	$events = $blog_id ? get_posts(
		array(
			'post_type'      => 'wordcamp',
			'post_status'    => 'any',
			'meta_key'       => '_site_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- An exact match on one site.
			'meta_value'     => $blog_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- An exact match on one site.
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	) : array();

	foreach ( $events as $event ) {
		if ( 'wcpt-cancelled' === $event->post_status ) {
			continue;
		}

		$end = (int) get_post_meta( $event->ID, 'End Date (YYYY-mm-dd)', true ) ?: (int) get_post_meta( $event->ID, 'Start Date (YYYY-mm-dd)', true );

		if ( ! $end || gmdate( 'Y-m-d', $end ) >= gmdate( 'Y-m-d' ) ) {
			return false;
		}
	}

	return (bool) $events;
}
