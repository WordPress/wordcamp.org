<?php
/**
 * Creates the Organizer Debrief Survey email in the Camptix email queue.
 */

namespace WordCamp\OrganizerSurvey\DebriefSurvey\Email;

defined( 'WPINC' ) || die();

/**
 * Constants.
 */
const EMAIL_KEY_ID = 'organizer_debrief_survey_email';

/**
 * Return the email ID.
 *
 * @return mixed
 */
function get_email_id() {
	return get_option( EMAIL_KEY_ID );
}

/**
 * Return lead organizer email.
 *
 *  @return string
 */
function get_lead_organizer_email() {
	$meta = get_wordcamp_post()->meta;
	return $meta['Email Address'][0];
}

/**
 * Return lead organizer full name.
 *
 * @return string
 */
function get_lead_organizer_full_name() {
	$meta = get_wordcamp_post()->meta;
	return $meta['Organizer Name'][0];
}

/**
 * Return WordCamp location.
 *
 * @return string
 */
function get_wordcamp_location() {
	$meta = get_wordcamp_post()->meta;
	return $meta['Location'][0];
}


/**
 * Return a token to put into the survey URL.
 *
 * @return string
 */
function get_encryption_token() {
	$lead_organizer_email = get_lead_organizer_email();
	return hash_hmac( 'sha1', $lead_organizer_email, ORGANIZER_SURVEY_ACCESS_TOKEN_KEY );
}

/**
 * Returns content for the reminder email.
 *
 * @return string
 */
function get_email_content() {
	$wordcamp_name   = get_wordcamp_name();
	$survey_page_url = 'https://central.wordcamp.test/organizer-survey-event-debrief/?t=' . get_encryption_token()
					 . '&e=' . base64_encode( get_lead_organizer_email() );

	$email  = "Howdy [email],\r\n\r\n";
	$email .= sprintf( "Congratulations on completing %s! We hope you had a great time, and that you'll soon get some well-deserved rest\r\n\r\n", esc_html( $wordcamp_name ) );

	$email .= "We'd love to hear how you feel the event went. What were your proudest moments and your greatest disappointments?\r\n";
	$email .= sprintf( "We've created an Organizer Survey, so we can get all the details of how things went with your event. <strong>Please fill it out this form within 10 days:</strong> %s\r\n\r\n", esc_url( $survey_page_url ) );

	$email .= "<strong>Event Budget</strong>\r\n";
	$email .= 'Please update your working budget on your event dashboard. If you ran your money outside of WordPress Community Support, PBC, please also balance your budget spreadsheet and prepare your Transparency Report.';
	$email .= "If there are any issues, please reach out to us at support@wordcamp.org to schedule a budget close-out meeting. Please complete these steps within the next two weeks.\r\n\r\n";

	$email .= "<strong>Event Recording (if any)</strong>\r\n";
	$email .= sprintf( "If you haven't yet done so, please review the submission guidelines before beginning to edit your videos (or before your videographers starts editing): %s\r\n\r\n", esc_url( 'http://blog.wordpress.tv/submission-guidelines/' ) );
	$email .= sprintf( "To submit your video for publication to WordPress.tv, just upload them at this page: %s\r\n\r\n", esc_url( 'http://wordpress.tv/submit-video/' ) );
	$email .= 'Our intrepid team of video moderators will review the videos and schedule them for publication. Our intrepid team of video moderators will review the videos and schedule them for publication. ';
	$email .= sprintf( "If your content is in a language other than English, please see if you can recruit someone from your community to join the WordPress TV moderators' team and review your videos: %s\r\n\r\n", esc_url( 'http://wordpress.tv/apply-to-be-a-wordpress-tv-moderator/' ) );

	$email .= "<strong>Event Recap</strong>\r\n";
	$email .= "Finally, if you've published a recap on your site, please let us know, so we can reblog it on the WordCamp Central blog.\r\n\r\n";

	$email .= sprintf( "Thanks again for all you've done to grow the WordPress community in %s!\r\n\r\n", get_wordcamp_location() );
	$email .= "Best wishes,\r\n";
	$email .= 'Your friendly WordCamp Central crew';

	return $email;
}

/**
 * Adds email to camptix email queue.
 */
function add_email() {
	$email_id = get_option( EMAIL_KEY_ID );
	if ( $email_id || ! get_wordcamp_post() ) {
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
