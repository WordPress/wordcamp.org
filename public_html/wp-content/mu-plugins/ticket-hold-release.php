<?php
/**
 * CampTix Ticket Hold System
 *
 * Counts draft attendees in the remaining ticket calculation to prevent
 * overselling during external payment gateway processing.
 *
 * Also releases abandoned drafts after 5 minutes to free held tickets.
 *
 * @package CampTixBD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subtract draft attendees from remaining ticket count
 *
 * CampTix core only counts 'publish' and 'pending' as purchased.
 * This filter adds 'draft' to the count so tickets are held during checkout.
 *
 * @param int   $remaining        Current remaining count.
 * @param int   $post_id          Ticket post ID.
 * @param mixed $via_reservation  Reservation token or false.
 * @param int   $quantity         Total ticket quantity.
 * @param array $reservations     Active reservations.
 *
 * @return int Adjusted remaining count.
 */
function camptix_bd_hold_draft_tickets( $remaining, $post_id, $via_reservation, $quantity, $reservations ) {
	$draft_count = camptix_bd_get_draft_attendee_count( $post_id, $via_reservation );

	return $remaining - $draft_count;
}
add_filter( 'camptix_get_remaining_tickets', 'camptix_bd_hold_draft_tickets', 10, 5 );

/**
 * Count draft attendees for a specific ticket
 *
 * @param int   $ticket_id       Ticket post ID.
 * @param mixed $via_reservation Reservation token or false.
 *
 * @return int Number of draft attendees.
 */
function camptix_bd_get_draft_attendee_count( $ticket_id, $via_reservation = false ) {
	$meta_query = array(
		array(
			'key'   => 'tix_ticket_id',
			'value' => $ticket_id,
		),
	);

	if ( $via_reservation ) {
		$meta_query[] = array(
			'key'   => 'tix_reservation_token',
			'value' => $via_reservation,
		);
	}

	$query = new WP_Query( array(
		'post_type'      => 'tix_attendee',
		'post_status'    => 'draft',
		'posts_per_page' => -1,
		'meta_query'     => $meta_query,
		'fields'         => 'ids',
	) );

	return $query->found_posts;
}

/**
 * Release abandoned draft attendees after 5 minutes
 *
 * Finds draft attendees that haven't been updated in 5 minutes
 * and changes their status to 'timeout', freeing the held tickets.
 */
function camptix_bd_release_abandoned_drafts() {
	$timeout_minutes = 5;
	$cutoff_time     = gmdate( 'Y-m-d H:i:s', time() - ( $timeout_minutes * MINUTE_IN_SECONDS ) );

	$abandoned = new WP_Query( array(
		'post_type'      => 'tix_attendee',
		'post_status'    => 'draft',
		'posts_per_page' => 100,
		'date_query'     => array(
			array(
				'column'   => 'post_modified_gmt',
				'before'   => $cutoff_time,
				'inclusive' => true,
			),
		),
		'fields' => 'ids',
	) );

	if ( ! $abandoned->have_posts() ) {
		return;
	}

	$released = 0;

	foreach ( $abandoned->posts as $attendee_id ) {
		// Double-check it's still a draft (race condition safety).
		$current_status = get_post_status( $attendee_id );
		if ( 'draft' !== $current_status ) {
			continue;
		}

		// Check if this attendee has a payment in progress.
		$payment_token = get_post_meta( $attendee_id, 'tix_payment_token', true );
		if ( empty( $payment_token ) ) {
			continue;
		}

		// Change status to timeout.
		wp_update_post( array(
			'ID'          => $attendee_id,
			'post_status' => 'timeout',
		) );

		$released++;
	}

	if ( $released > 0 ) {
		error_log( sprintf(
			'CampTix BD: Released %d abandoned draft attendee(s) after %d minutes',
			$released,
			$timeout_minutes
		) );
	}
}
add_action( 'camptix_bd_release_abandoned_drafts', 'camptix_bd_release_abandoned_drafts' );

/**
 * Schedule the release cron job if not already scheduled
 */
function camptix_bd_schedule_release_cron() {
	if ( ! wp_next_scheduled( 'camptix_bd_release_abandoned_drafts' ) ) {
		wp_schedule_event( time(), 'every_two_minutes', 'camptix_bd_release_abandoned_drafts' );
	}
}
add_action( 'wp_loaded', 'camptix_bd_schedule_release_cron' );

/**
 * Register custom cron interval: every 2 minutes
 *
 * @param array $schedules Existing cron schedules.
 *
 * @return array Modified schedules.
 */
function camptix_bd_cron_schedules( $schedules ) {
	$schedules['every_two_minutes'] = array(
		'interval' => 2 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 2 Minutes', 'bd-payments-camptix' ),
	);

	return $schedules;
}
add_filter( 'cron_schedules', 'camptix_bd_cron_schedules' );

/**
 * Clean up cron job on plugin deactivation
 */
function camptix_bd_cleanup_cron() {
	$timestamp = wp_next_scheduled( 'camptix_bd_release_abandoned_drafts' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'camptix_bd_release_abandoned_drafts' );
	}
}
register_deactivation_hook( __FILE__, 'camptix_bd_cleanup_cron' );
