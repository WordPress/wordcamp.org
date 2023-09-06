<?php
/**
 * Creates the Organizer Survey email in TODO.
 */

namespace WordCamp\OrganizerSurvey\Email;

defined( 'WPINC' ) || die();

use function WordCamp\OrganizerSurvey\Page\{get_survey_page_url};

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

	// TODO

	return $email;
}


/**
 * Adds email to TODO email queue.
 */
function add_email() {
	// TODO

	// $email_id = get_option( EMAIL_KEY_ID );
	// if ( $email_id ) {
	// 	return;
	// }

	// $email_id = wp_insert_post(
	// 	array(
	// 		'post_title'   => __( '[first_name], tell us what you thought: Post-event survey', 'wordcamporg' ),
	// 		'post_content' => get_email_content(),
	// 		'post_status'  => 'draft', // Must be 'draft' to avoid processing.
	// 		'post_type'    => 'tix_email',
	// 	)
	// );

	// if ( $email_id > 0 ) {
	// 	update_option( EMAIL_KEY_ID, $email_id );
	// }
}

/**
 * Turns on the email by changing its status to 'pending'.
 *
 * The Camptix email queue will send the email.
 *
 * @return int|WP_Error
 */
function queue_survey_email( $email_id ) {
	// TODO

	// return wp_update_post( array(
	// 	'ID'            => $email_id,
	// 	'post_status'   => 'pending',
	// ) );
}

/**
 * Delete the email and associated meta data.
 */
function delete_email() {
	// TODO

	// $post_id = get_email_id();
	// wp_delete_post( $post_id, true );
	// delete_option( EMAIL_KEY_ID );

	// // Clean up any associated organizer to the email.
	// delete_post_meta( $post_id, 'tix_email_recipient_id' );
	// delete_post_meta( $post_id, 'tix_email_recipients_backup' );
}
