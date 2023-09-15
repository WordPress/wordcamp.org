<?php
/**
 * Creates the Organizer Debrief Survey email in the Camptix email queue.
 */

namespace WordCamp\OrganizerSurvey\DebriefSurvey\Email;

defined( 'WPINC' ) || die();

use function WordCamp\OrganizerSurvey\DebriefSurvey\Page\{get_survey_page_url};

/**
 * Constants.
 */
const EMAIL_KEY_ID = 'organizer_survey_email';

/**
 * Return the email ID.
 *
 * @return mixed
 */
function get_email_id() {
	return get_option( EMAIL_KEY_ID );
}

/**
 * Returns content for the reminder email.
 *
 * @return string
 */
function get_email_content() {
	$wordcamp_name   = get_wordcamp_name();
	$closing_date    = date_i18n( 'Y-m-d', strtotime('+2 weeks') );
	$survey_page_url = get_survey_page_url();

	$email  = "Hi [first_name],\r\n\r\n";
	$email .= sprintf( "%s is over, thank you for lead organizing it! \r\n\r\n", esc_html( $wordcamp_name ) );
	$email .= "As a community-led event, feedback is really important to us. It helps us improve our events and to keep providing high quality content.\r\n\r\n";
	$email .= sprintf( "Please take a moment to answer our <a href='%s'>post-event survey</a> and help us to do amazing WordPress events!\r\n", esc_url( $survey_page_url ) );
	$email .= "(If you can't open the link, copy and paste the following URL)\r\n";
	$email .= $survey_page_url . "\r\n\r\n";
	$email .= sprintf( "Please complete the survey by %s.\r\n\r\n", $closing_date );
	$email .= "Please also note that all responses will be kept confidential, and we will not share your personal information with any third parties.\r\n\r\n";
	$email .= "Thank you in advance, we really appreciate your time!\r\n\r\n";
	$email .= "Best regards,\r\n";
	$email .= "Organising Team,\r\n";
	$email .= esc_html( $wordcamp_name );

	return $email;
}

/**
 * Adds email to camptix email queue.
 */
function add_email() {
	$email_id = get_option( EMAIL_KEY_ID );
	if ( $email_id ) {
		return;
	}

	$email_id = wp_insert_post(
		array(
			'post_title'   => __( 'Organizer Survey (event debrief)', 'wordcamporg' ),
			'post_content' => get_email_content(),
			'post_status'  => 'draft',
			'post_type'    => 'tix_email',
		)
	);

	if ( $email_id > 0 ) {
		update_post_meta( $email_id, 'tix_email_recipients_backup', array() );
		update_option( EMAIL_KEY_ID, $email_id );
	}
}

/**
 * Turns on the email by changing its status to 'pending'.
 *
 * The Camptix email queue will send the email.
 *
 * @return int|WP_Error
 */
function queue_survey_email( $email_id ) {
	return wp_update_post( array(
		'ID'            => $email_id,
		'post_status'   => 'pending',
	) );
}

/**
 * Delete the email and associated meta data.
 */
function delete_email() {
	$email_id = get_email_id();
	// Clean up any associated attendees to the email.
	delete_post_meta( $email_id, 'tix_email_recipient_id' );
	delete_post_meta( $email_id, 'tix_email_recipients_backup' );
	wp_delete_post( $email_id, true );
	delete_option( EMAIL_KEY_ID );
}
