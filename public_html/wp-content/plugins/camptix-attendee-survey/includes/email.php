<?php
/**
 * Creates the Attendee Survey email in the Camptix email queue.
 */

namespace CampTix\AttendeeSurvey\Email;

defined( 'WPINC' ) || die();

use function CampTix\AttendeeSurvey\Page\{get_survey_page_url};

/**
 * Constants.
 */
const EMAIL_KEY_ID = 'attendee_survey_email';

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

	$email  = "Hi [first_name] [last_name],\r\n\r\n";
	$email .= sprintf(
		/* translators: %s: wordcamp name. */
		__( "%s is over, thank you to everyone who joined us! \r\n\r\n", 'wordcamporg' ),
		esc_html( $wordcamp_name )
	);
	$email .= __( "As a community-led event, feedback is really important to us. It helps us improve our events and to keep providing high quality content.\r\n\r\n", 'wordcamporg' );
	$email .= sprintf(
		__(
			/* translators: Please leave %1$s and %2$s untranslated. They represent the opening and closing anchor tags, respectively. */
			"Please take a moment to answer our %1\$spost-event survey%2\$s and help us to do amazing WordPress events!\r\n",
			'wordcamporg'
		),
		'<a href="' . esc_url( $survey_page_url ) . '">',
		'</a>'
	);
	$email .= __( "(If you can't open the link, copy and paste the following URL)\r\n", 'wordcamporg' );
	$email .= $survey_page_url . "\r\n\r\n";
	$email .= sprintf(
		/* translators: %s: closing date. */
		__( "Please complete the survey by %s.\r\n\r\n", 'wordcamporg' ),
		$closing_date
	);
	$email .= __( "Please also note that all responses will be kept confidential, and we will not share your personal information with any third parties.\r\n\r\nThank you in advance, we really appreciate your time!\r\n\r\nBest regards,\r\nOrganising Team,\r\n", 'wordcamporg' );
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
			'post_title'   => __( '[first_name], tell us what you thought: Post-event survey', 'wordcamporg' ),
			'post_content' => get_email_content(),
			'post_status'  => 'draft', // Must be 'draft' to avoid processing.
			'post_type'    => 'tix_email',
		)
	);

	if ( $email_id > 0 ) {
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
	$post_id = get_email_id();
	wp_delete_post( $post_id, true );
	delete_option( EMAIL_KEY_ID );

	// Clean up any associated attendees to the email.
	delete_post_meta( $post_id, 'tix_email_recipient_id' );
	delete_post_meta( $post_id, 'tix_email_recipients_backup' );
}
