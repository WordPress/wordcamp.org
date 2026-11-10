<?php
/**
 * The Inactive status: a vetting request dropped without a decision, so nothing is left pending on it.
 *
 * A daily job moves companies out of Compliance Requested once no sponsorship waiting on the request has an event
 * still to come. The next vetting request for an Inactive company puts it back in the queue (see queue_company()).
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

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
 * Moves each company in Compliance Requested to Inactive once none of its pending sponsorships has an event still
 * to come, or it has none.
 *
 * A company whose sponsor replied is waiting on a reviewer, and one approved in Good Faith still owes its changes,
 * so both stay.
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

	foreach ( $company_ids as $company_id ) {
		if ( get_post_meta( $company_id, '_sv_sponsor_replied', true ) || get_post_meta( $company_id, '_sv_good_faith_due', true ) ) {
			continue;
		}

		$pending = get_pending_sponsorships( $company_id );

		foreach ( $pending as $sponsorship_id ) {
			if ( ! event_has_ended( (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true ) ) ) {
				continue 2;
			}
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

			add_note(
				$company_id,
				$pending
					? __( 'Inactive automatically: the events of its pending sponsorships have ended.', 'wordcamporg' )
					: __( 'Inactive automatically: no sponsorship is waiting on its compliance request.', 'wordcamporg' )
			);
		} finally {
			acting_as_system( $was_system );
		}
	}
}
add_action( 'sv_check_inactive', __NAMESPACE__ . '\deactivate_stalled_companies' );

/**
 * Cancels a company's pending sponsorships once it's Inactive, however it got there.
 *
 * Nothing is left waiting on an Inactive company: a sponsorship left pending would read "In review" to its
 * organizers indefinitely. Restoring a company from the trash isn't a new decision.
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

	$was_system = acting_as_system();
	acting_as_system( true );

	try {
		foreach ( get_pending_sponsorships( $post->ID ) as $sponsorship_id ) {
			wp_update_post(
				array(
					'ID'          => $sponsorship_id,
					'post_status' => 'svs-canceled',
				)
			);

			add_note( $sponsorship_id, __( 'Canceled automatically: its company is Inactive.', 'wordcamporg' ) );
		}
	} finally {
		acting_as_system( $was_system );
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\cancel_inactive_sponsorships', 10, 4 );

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
 * Whether a camp's event is over: its end date, or its start date for a one-day event, is before today.
 *
 * An event without a date, or without a `wordcamp` post, isn't taken for over. Runs on Central.
 *
 * @param int $blog_id The camp site.
 * @return bool
 */
function event_has_ended( int $blog_id ): bool {
	$event_ids = $blog_id ? get_posts(
		array(
			'post_type'      => 'wordcamp',
			'post_status'    => 'any',
			'meta_key'       => '_site_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- An exact match on one site.
			'meta_value'     => $blog_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- An exact match on one site.
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	) : array();

	if ( ! $event_ids ) {
		return false;
	}

	$end = (int) get_post_meta( $event_ids[0], 'End Date (YYYY-mm-dd)', true ) ?: (int) get_post_meta( $event_ids[0], 'Start Date (YYYY-mm-dd)', true );

	return $end && gmdate( 'Y-m-d', $end ) < gmdate( 'Y-m-d' );
}
