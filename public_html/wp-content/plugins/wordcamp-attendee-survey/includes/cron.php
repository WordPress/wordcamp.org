<?php

namespace WordCamp\AttendeeSurvey\Cron;

defined( 'WPINC' ) || die();

//add_action( 'transition_post_status', __NAMESPACE__ . '\schedule_jobs' );
add_action( 'wc_attendee_survey_email', __NAMESPACE__ . '\remind_attendees' );

/**
 * Add cron jobs to the schedule.
 *
 * @return void
 */
function schedule_jobs() {
	if ( ! wp_next_scheduled( 'wc_attendee_survey_email' ) ) {
		wp_schedule_event( time(), 'daily', 'wc_attendee_survey_email' );
	}
}

/**
 * Get Wordcamp attendees.
 *
 * @return array
 */
function get_wordcamp_attendees() {
	$attendees = get_posts( array(
		'post_type' => 'tix_attendee',
		'posts_per_page' => -1,
		'post_status' => array( 'publish' ),
		'fields' => 'ids',
		'orderby' => 'ID',
		'order' => 'ASC',
		'cache_results' => false,
	) );

	return $attendees;
}

/**
 * Get Wordcamp Survey Page ID
 *
 * @return int
 */
function get_wordcamp_attendee_survey_page_id() {
	$survey_page_id = get_option( 'attendee_survey_page' );

	return $survey_page_id;
}

/**
 * Get all WordCamps survey urls
 *
 * @return array
 */
function get_wordcamp_attendee_survey_url( $wordcamp_id ) {
	$survey_page_id = get_wordcamp_attendee_survey_page_id( $wordcamp_id );
	$survey_url     = get_permalink( $survey_page_id );

	return $survey_url;
}

/**
 * Returns the date when we should look for closed WordCamps.
 */
function get_date_since_closed() {
	return date( 'Y-m-d', strtotime( '-2 days' ) );
}

/**
 * Sends email to attendee.
 */
function send_attendee_email() {

}

/**
 * Send email to attendees who attended a WordCamp 2 days ago.
 *
 * @return void
 */
function remind_attendees() {

	$wordcamps = get_wordcamps( array(
		'status' => 'wcpt-closed',
		'end_date' => get_date_since_closed(),
	) );

	foreach ( $wordcamps as $wordcamp ) {
		$attendees = get_wordcamp_attendees( $wordcamp->ID );

		// we ay be able to push this to `tix_email` which will get picked up by the queue.
		foreach ( $attendees as $attendee ) {
			$email         = $attendee->email;
			$name          = $attendee->first_name . ' ' . $attendee->last_name;
			$wordcamp_name = $wordcamp->post_title;
			$wordcamp_url  = get_wordcamp_url( $wordcamp->ID );
			$survey_url    = get_wordcamp_attendee_survey_url( $wordcamp->ID );
		}
	}
}

remind_attendees();



// $email_id = wp_insert_post( array(
// 'post_type' => 'tix_email',
// 'post_status' => 'pending',
// 'post_title' => $subject,
// 'post_content' => $body,
// ) );

// // Add recipients as post meta.
// if ( $email_id ) {
// add_settings_error( 'camptix', 'none', sprintf( __( 'Your e-mail job has been queued for %s recipients.', 'wordcamporg' ), count( $recipients ) ), 'updated' );
// $this->log( sprintf( 'Created e-mail job with %s recipients.', count( $recipients ) ), $email_id, null, 'notify' );

// foreach ( $recipients as $recipient_id )
// add_post_meta( $email_id, 'tix_email_recipient_id', $recipient_id );

// update_post_meta( $email_id, 'tix_email_recipients_backup', $recipients ); // for logging purposes
// unset( $recipients );
// }
