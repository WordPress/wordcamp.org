<?php
/**
 * Creates the Attendee Survey email in the Camptix email queue.
 */

namespace WordCamp\AttendeeSurvey\Email;

defined( 'WPINC' ) || die();

use function WordCamp\AttendeeSurvey\Page\{get_survey_page_url};

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
	return sprintf(
		__( 'Thanks for participating in the event. Here is the url: %s', 'wordcamporg' ),
	get_survey_page_url()  );
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
			'post_title'   => __( 'Attendee Survey Email', 'wordcamporg' ),
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
