<?php

namespace WordCamp\OrganizerSurvey\DebriefSurvey\Cron;

defined( 'WPINC' ) || die();

use CampTix_Plugin;

use function WordCamp\OrganizerSurvey\DebriefSurvey\Email\{
	get_email_id,
	queue_survey_email,
	get_lead_organizer_email,
	get_lead_organizer_full_name
};

/**
 * Constants.
 */
const DAYS_AFTER_TO_SEND = 2;
const TEMP_ATTENDEE_ID   = 'organizer_debrief_survey_temp_attendee';

/**
 * Actions & hooks
 */
add_action( 'init', __NAMESPACE__ . '\schedule_jobs' );
add_action( 'wc_organizer_debrief_survey_email', __NAMESPACE__ . '\queue_organizer_survey' );

/**
 * Logs a message to the CampTix email log.
 */
function log( $message, $post_id, $data = null ) {
	/* @var CampTix_Plugin $camptix */
	global $camptix;

	$camptix->log( $message, $post_id, $data );
}

/**
 * Get Wordcamp attendee ID.
 *
 * @return int
 */
function get_wordcamp_attendees_id() {
	return get_option( TEMP_ATTENDEE_ID );
}

/**
 * Add cron jobs to the schedule.
 */
function schedule_jobs() {
	if ( ! wp_next_scheduled( 'wc_organizer_debrief_survey_email' ) ) {
		wp_schedule_event( time(), 'daily', 'wc_organizer_debrief_survey_email' );
	}
}

/**
 * Add temporary attendee since email can only be sent to items in attendee tracker.
 */
function add_temp_attendee() {
	if ( ! get_wordcamp_attendees_id() ) {
		$attendees_id = wp_insert_post( array(
			'post_title' => 'Organizer debrief survey',
			'post_name' => 'organizer-debrief-survey',
			'post_type' => 'tix_attendee',
			'post_status' => 'publish',
		) );

		if ( $attendees_id ) {
			update_post_meta( $attendees_id, 'tix_email', get_lead_organizer_email() );
			update_post_meta( $attendees_id, 'tix_receipt_email', get_lead_organizer_email() );
			update_post_meta( $attendees_id, 'tix_first_name', get_lead_organizer_full_name() );

			update_option( TEMP_ATTENDEE_ID, $attendees_id );
			log( 'Successfully added attendee:', get_email_id(), $attendees_id );
		} else {
			log( 'Failed to add attendee:', get_email_id(), $attendees_id );
		}
	}
}

/**
 * Associates attendee to emails.
 */
function associate_attendee_to_email( $email_id ) {
	$recipient_id = get_wordcamp_attendees_id();

	if ( empty( $recipient_id ) ) {
		log( 'No valid recipients', $email_id, null );
		return;
	}

	// Associate attendee to tix_email as a recipient.
	$result = add_post_meta( $email_id, 'tix_email_recipient_id', $recipient_id );

	if ( ! $result ) {
		log( 'Failed to add recipients:', $email_id, $recipient_id );
	} else {
		update_post_meta( $email_id, 'tix_email_recipients_backup', (array) $recipient_id );
		log( 'Successfully added recipients:', $email_id, $recipient_id );
	}
}

/**
 * Returns true if an emailed will be sent or is queued.
 *
 * @return bool
 */
function is_email_already_sent_or_queued( $email_id ) {
	$email = get_post( $email_id );
	return 'publish' === $email->post_status || 'pending' === $email->post_status;
}

/**
 * Returns true if it is time to send the email.
 *
 * @return bool
 */
function is_time_to_send_email( $email_id ) {
	$blog_id       = get_current_blog_id();
	$wordcamp_post = get_wordcamp_post( $blog_id );

	if ( ! $wordcamp_post ) {
		log( 'Couldn\'t retrieve wordcamp for blog id:', $email_id, $blog_id );
		return false;
	}

	if ( 'wcpt-closed' !== $wordcamp_post->post_status ) {
		return false;
	}

	$end_date = $wordcamp_post->meta['End Date (YYYY-mm-dd)'][0];

	if ( empty( $end_date ) ) {
		// Some sites that are only 1-day events will only have the start date.
		$end_date = $wordcamp_post->meta['Start Date (YYYY-mm-dd)'][0];
	}

	$send_date = $end_date + ( DAYS_AFTER_TO_SEND * DAY_IN_SECONDS );

	return gmdate( 'Y-m-d', $send_date ) === current_time( 'Y-m-d', true );
}

/**
 * Delete the temporary attendee.
 */
function delete_temp_attendee() {
	$attendee_id = get_wordcamp_attendees_id();
	wp_delete_post( $attendee_id, true );
	delete_option( TEMP_ATTENDEE_ID );
}

/**
 * Associates recipients to email and changes its status to be picked up
 * by the camptix email cron job `tix_scheduled_every_ten_minutes`.
 */
function queue_organizer_survey() {
	$email_id = get_email_id();

	if ( empty( $email_id ) ) {
		return;
	}

	// check to make sure we didn't already send an email for this wordcamp.
	if ( is_email_already_sent_or_queued( $email_id ) ) {
		return;
	}

	if ( ! is_time_to_send_email( $email_id ) ) {
		return;
	}

	add_temp_attendee();
	associate_attendee_to_email( $email_id );

	$email_status = queue_survey_email( $email_id );

	if ( is_wp_error( $email_status ) ) {
		log( 'Failed updating email status', $email_id, $email_status );
	} else {
		log( 'Email status change to `pending`.', $email_id );
	}

	// Remove the cron job that queues everything.
	wp_clear_scheduled_hook( 'wc_organizer_debrief_survey_email' );
}
