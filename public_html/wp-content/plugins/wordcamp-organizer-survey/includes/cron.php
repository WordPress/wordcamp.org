<?php

namespace WordCamp\OrganizerSurvey\Cron;

defined( 'WPINC' ) || die();

use function WordCamp\OrganizerSurvey\Email\{get_email_id, queue_survey_email};
use function WordCamp\OrganizerSurvey\Page\{disable_page, publish_survey_page};

/**
 * Constants.
 */
const DAYS_AFTER_TO_SEND = 2;

/**
 * Actions & hooks
 */
add_action( 'init', __NAMESPACE__ . '\schedule_jobs' );
add_action( 'wc_organizer_survey_email', __NAMESPACE__ . '\queue_organizer_survey' );
add_action( 'wc_organizer_disable_survey', __NAMESPACE__ . '\disable_organizer_survey' );


/**
 * Add cron jobs to the schedule.
 */
function schedule_jobs() {
	if ( ! wp_next_scheduled( 'wc_organizer_survey_email' ) ) {
		wp_schedule_event( time(), 'daily', 'wc_organizer_survey_email' );
	}
}

/**
 * Get Wordcamp lead organizer.
 *
 * @return int[]
 */
function get_wordcamp_organizer_id() {
	// TODO
}

/**
 * Associates lead organizer to email.
 */
function associate_organizer_to_email( $email_id ) {
	$failed_to_add      = array();
	$successfully_added = array();
	$recipient_id       = get_wordcamp_organizer_id();

	if ( empty( $recipients ) ) {
		log( 'No valid recipients', $email_id, null );
		return;
	}

	// TODO
}

/**
 * Returns true if an emailed will be sent or is queued.
 *
 * @return bool
 */
function is_email_already_sent_or_queued( $email_id ) {
	// $email = get_post( $email_id );
	// return 'publish' === $email->post_status || 'pending' === $email->post_status;
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

	$end_date = $wordcamp_post->meta['End Date (YYYY-mm-dd)'][0];

	if ( ! isset( $end_date ) ) {
		log( 'WordCamp missing end date', $email_id, $wordcamp_post );
		return false;
	}

	$date            = new \DateTime("@$end_date ");
	$current_date    = new \DateTime();
	$interval        = $current_date->diff($date);
	$days_difference = $interval->days;

	return DAYS_AFTER_TO_SEND <= $days_difference;
}

/**
 * Turns off the survey to avoid spam.
 */
function disable_organizer_survey() {
	disable_page();
}

/**
 * TODO: Just send email
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

	$page_published = publish_survey_page();

	if ( is_wp_error( $page_published ) ) {
		log( 'Error publishing survey page:', $email_id, $page_published );
	}

	associate_organizer_to_email( $email_id );

	$email_status = queue_survey_email( $email_id );

	if ( is_wp_error( $email_status ) ) {
		log( 'Failed updating email status', $email_id, $email_status );
	} else {
		log( 'Email status change to `pending`.', $email_id );
	}

	if ( ! wp_next_scheduled( 'wc_organizer_disable_survey' ) ) {
		$next_time = strtotime( '+2 weeks' . wp_timezone_string() );
		wp_schedule_single_event( $next_time, 'wc_organizer_disable_survey' );
	}
}
