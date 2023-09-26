<?php

namespace CampTix\AttendeeSurvey\Cron;

defined( 'WPINC' ) || die();

use CampTix_Plugin;

use function CampTix\AttendeeSurvey\Email\{get_email_id, queue_survey_email};
use function CampTix\AttendeeSurvey\Page\{disable_page, publish_survey_page};

/**
 * Constants.
 */
const DAYS_AFTER_TO_SEND = 2;

/**
 * Actions & hooks
 */
add_action( 'init', __NAMESPACE__ . '\schedule_jobs' );
add_action( 'wc_attendee_survey_email', __NAMESPACE__ . '\queue_attendee_survey' );
add_action( 'wc_attendee_disable_survey', __NAMESPACE__ . '\disable_attendee_survey' );


/**
 * Logs a message to the CampTix email log.
 */
function log( $message, $post_id, $data = null ) {
	/* @var CampTix_Plugin $camptix */
	global $camptix;

	$camptix->log( $message, $post_id, $data );
}

/**
 * Add cron jobs to the schedule.
 */
function schedule_jobs() {
	if ( ! wp_next_scheduled( 'wc_attendee_survey_email' ) ) {
		wp_schedule_event( time(), 'daily', 'wc_attendee_survey_email' );
	}
}

/**
 * Get Wordcamp attendees.
 *
 * @return int[]
 */
function get_wordcamp_attendees_id() {
	return get_posts( array(
		'post_type' => 'tix_attendee',
		'posts_per_page' => -1,
		'post_status' => 'publish',
		'fields' => 'ids',
		'orderby' => 'ID',
		'order' => 'ASC',
		'cache_results' => false,
	) );
}

/**
 * Associates attendees to emails.
 */
function associate_attendee_to_email( $email_id ) {
	$failed_to_add      = array();
	$successfully_added = array();
	$recipients         = get_wordcamp_attendees_id();

	if ( empty( $recipients ) ) {
		log( 'No valid recipients', $email_id, null );
		return;
	}

	// Associate attendee to tix_email as a recipient.
	foreach ( $recipients as $recipient_id ) {
		$result = add_post_meta( $email_id, 'tix_email_recipient_id', $recipient_id );

		if ( ! $result ) {
			$failed_to_add[] = $recipient_id;
		} else {
			$successfully_added[] = $recipient_id;
		}
	}

	if ( ! empty( $failed_to_add ) ) {
		log( 'Failed to add recipients:', $email_id, $failed_to_add );
	}

	if ( ! empty( $successfully_added ) ) {
		// Copied from camptix.php.
		update_post_meta( $email_id, 'tix_email_recipients_backup', $successfully_added );

		log( 'Successfully added recipients:', $email_id, $successfully_added );
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
		log( 'WordCamp missing end date', $email_id, $wordcamp_post );
		return false;
	}

	$send_date = $end_date + ( DAYS_AFTER_TO_SEND * DAY_IN_SECONDS );

	return gmdate( 'Y-m-d', $send_date ) === current_time( 'Y-m-d', true );
}

/**
 * Turns off the survey to avoid spam.
 */
function disable_attendee_survey() {
	disable_page();
}

/**
 * Associates recipients to email and changes its status to be picked up
 * by the camptix email cron job `tix_scheduled_every_ten_minutes`.
 */
function queue_attendee_survey() {
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

	$page_published = publish_survey_page();

	if ( is_wp_error( $page_published ) ) {
		log( 'Error publishing survey page:', $email_id, $page_published );
	}

	associate_attendee_to_email( $email_id );

	$email_status = queue_survey_email( $email_id );

	if ( is_wp_error( $email_status ) ) {
		log( 'Failed updating email status', $email_id, $email_status );
	} else {
		log( 'Email status change to `pending`.', $email_id );
	}

	if ( ! wp_next_scheduled( 'wc_attendee_disable_survey' ) ) {
		$next_time = strtotime( '+2 weeks' . wp_timezone_string() );
		wp_schedule_single_event( $next_time, 'wc_attendee_disable_survey' );
	}

	// Remove the cron job that queues everything.
	wp_clear_scheduled_hook( 'wc_attendee_survey_email' );
}
