<?php

namespace WordCamp\AttendeeSurvey\Cron;

defined( 'WPINC' ) || die();

use function WordCamp\AttendeeSurvey\Email\{get_email_id, publish_survey_email};
use function WordCamp\AttendeeSurvey\Page\{publish_survey_page};

/**
 * Constants.
 */
const DAYS_AFTER_TO_SEND = 2;

/**
 * Actions & hooks
 */
add_action( 'init', __NAMESPACE__ . '\schedule_jobs' );
add_action( 'wc_attendee_survey_email', __NAMESPACE__ . '\send_attendee_survey' );

/**
 * Add cron jobs to the schedule.
 *
 * @return void
 */
function schedule_jobs() {
	if ( ! wp_next_scheduled( 'wc_attendee_survey_email' ) ) {
		wp_schedule_event( time(), 'hourly', 'wc_attendee_survey_email' );
	}
}

/**
 * Get Wordcamp attendees.
 *
 * @return array
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
 * Returns the date when we should look for closed WordCamps.
 */
function get_date_since_closed() {
	return date( 'Y-m-d', strtotime( '-2 days' ) );
}

/**
 * Associates attendees to emails.
 */
function associate_attendee_to_email( $email_id ) {
	$failed_to_add      = array();
	$successfully_added = array();
	$recipients         = get_wordcamp_attendees_id();

	if ( empty( $recipients ) ) {
		return;
	}

	$existing_post_meta = get_post_meta( $email_id, 'tix_email_recipient_id' );

	// Associate attendee to tix_email as a recipient.
	foreach ( $recipients as $recipient_id ) {
		if ( ! in_array( $recipient_id, $existing_post_meta, true ) ) {
			$result = add_post_meta( $email_id, 'tix_email_recipient_id', $recipient_id );

			if ( ! $result ) {
				$failed_to_add[] = $recipient_id;
			} else {
				$successfully_added[] = $recipient_id;
			}
		}
	}

	if ( ! empty( $failed_to_add ) ) {
		do_action( 'camptix_log_raw', 'Failed to add recipients:', $email_id, $failed_to_add, 'notify');
	}

	if ( ! empty( $successfully_added ) ) {
		// Copied from camptix.php.
		update_post_meta( $email_id, 'tix_email_recipients_backup', $successfully_added );

		do_action( 'camptix_log_raw', 'Successfully added recipients:', $email_id, $successfully_added, 'notify');
	}
}

/**
 * Returns true if we sent an email for this wordcamp.
 */
function email_already_sent_or_queued( $email_id ) {
	$email = get_post( $email_id );
	return 'publish' === $email->post_status || 'pending' === $email->post_status;
}

/**
 * Return true if it is time to send the email.
 */
function is_time_to_send_email( $email_id ) {
	$wordcamp_post = get_wordcamp_post();

	if ( ! isset( $wordcamp_post->ID ) ) {
		do_action( 'camptix_log_raw', 'Couldn\'t retrieve wordcamp post id', $email_id, null, 'notify');
		return false;
	}

	$end_date = $wordcamp_post->meta['End Date (YYYY-mm-dd)'][0];

	if ( ! isset( $end_date ) ) {
		do_action( 'camptix_log_raw', 'WordCamp doesn\'t have end date', $email_id, $wordcamp_post, 'notify');
		return false;
	}

	$date           = new \DateTime("@$end_date ");
	$currentDate    = new \DateTime();
	$interval       = $currentDate->diff($date);
	$daysDifference = $interval->days;

	return DAYS_AFTER_TO_SEND === $daysDifference;
}


/**
 * Associates recipients to email and changes its status to be picked up
 * by the camptix email cron job `tix_scheduled_every_ten_minutes`.
 *
 * @return void
 */
function send_attendee_survey() {
	$email_id = get_email_id();

	if ( empty( $email_id ) ) {
		return;
	}

	// check to make sure we didn't already send an email for this wordcamp.
	if ( email_already_sent_or_queued( $email_id ) ) {
		return;
	}

	if ( ! is_time_to_send_email( $email_id ) ) {
		return;
	}

	$page_published = publish_survey_page();

	if ( is_wp_error( $page_published ) ) {
		do_action( 'camptix_log_raw', 'No page to send to', $email_id, $page_published, 'notify');
	}

	associate_attendee_to_email( $email_id );

	$email_status = publish_survey_email( $email_id );

	if ( is_wp_error( $email_status ) ) {
		do_action( 'camptix_log_raw', 'Failed updating email status', $email_id, $email_status, 'notify');
	} else {
		do_action( 'camptix_log_raw', 'Email status change to `pending`.', $email_id, null, 'notify');
	}
}
