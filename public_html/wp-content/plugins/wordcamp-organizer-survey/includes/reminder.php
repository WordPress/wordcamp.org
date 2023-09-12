<?php
/**
 * Creates the Organizer Debrief Survey reminder in Organizer Reminders.
 */

namespace WordCamp\OrganizerSurvey\Reminder;

defined( 'WPINC' ) || die();

use function WordCamp\OrganizerSurvey\DebriefSurvey\{get_survey_page_url};

/**
 * Constants.
 */
const REMINDER_KEY_ID = 'organizer_survey_reminder';

/**
 * Return the reminder ID.
 *
 * @return mixed
 */
function get_reminder_id() {
	return get_option( REMINDER_KEY_ID );
}

/**
 * Returns content for the reminder.
 *
 * @return string
 */
function get_reminder_content() {
	$wordcamp_name   = get_wordcamp_name();
	$closing_date    = date_i18n( 'Y-m-d', strtotime('+2 weeks') );
	$survey_page_url = get_survey_page_url();

	$reminder  = "Hi [first_name] [last_name],\r\n\r\n";
	$reminder .= sprintf( "%s is over, thank you for lead organizing it! \r\n\r\n", esc_html( $wordcamp_name ) );
	$reminder .= "As a community-led event, feedback is really important to us. It helps us improve our events and to keep providing high quality content.\r\n\r\n";
	$reminder .= sprintf( "Please take a moment to answer our <a href='%s'>post-event survey</a> and help us to do amazing WordPress events!\r\n", esc_url( $survey_page_url ) );
	$reminder .= "(If you can't open the link, copy and paste the following URL)\r\n";
	$reminder .= $survey_page_url . "\r\n\r\n";
	$reminder .= sprintf( "Please complete the survey by %s.\r\n\r\n", $closing_date );
	$reminder .= "Please also note that all responses will be kept confidential, and we will not share your personal information with any third parties.\r\n\r\n";
	$reminder .= "Thank you in advance, we really appreciate your time!\r\n\r\n";
	$reminder .= "Best regards,\r\n";
	$reminder .= "Organising Team,\r\n";
	$reminder .= esc_html( $wordcamp_name );

	return $reminder;
}


/**
 * Adds reminder to Organizer Reminders queue.
 */
function add_reminder() {
	$reminder_id = get_option( REMINDER_KEY_ID );

	if ( $reminder_id ) {
		return;
	}

	$reminder_id = wp_insert_post(
		array(
			'post_title'   => __( '[first_name], tell us what you thought: Post-event survey', 'wordcamporg' ),
			'post_content' => get_reminder_content(),
			'post_type'    => 'organizer-reminder',
			'post_status'  => 'publish',
		)
	);

	if ( $reminder_id && ! is_wp_error( $reminder_id ) ) {
		update_post_meta( $reminder_id, 'wcor_send_where', array( 'wcor_send_custom' ) );
		update_post_meta( $reminder_id, 'wcor_send_custom_address', 'renyot@gmail.com' );
		update_post_meta( $reminder_id, 'wcor_send_when', 'wcor_send_after' );
		update_post_meta( $reminder_id, 'wcor_send_days_after', 2 );
	}

	if ( $reminder_id > 0 ) {
		update_option( REMINDER_KEY_ID, $reminder_id );
	}
}

/**
 * Delete the reminder.
 */
function delete_reminder() {
	$post_id = get_reminder_id();
	wp_delete_post( $post_id, true );
	delete_option( REMINDER_KEY_ID );
}
