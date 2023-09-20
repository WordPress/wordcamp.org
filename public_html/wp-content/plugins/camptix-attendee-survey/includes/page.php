<?php
/**
 * Adds a page which contains the survey.
 */

namespace CampTix\AttendeeSurvey\Page;

defined( 'WPINC' ) || die();

use function CampTix\AttendeeSurvey\{get_survey_page_id};

add_filter( 'pre_trash_post', __NAMESPACE__ . '\prevent_deletion', 10, 2 );
add_filter( 'pre_delete_post', __NAMESPACE__ . '\prevent_deletion', 10, 3 );

/**
 * Constants.
 */
const SURVEY_PAGE_ID = 'attendee_survey_page';


/**
 * Return the page ID.
 *
 * @return mixed
 */
function get_page_id() {
	return get_option( SURVEY_PAGE_ID );
}


/**
 * Prevent deletion of the Survey page, unless force_delete is true.
 *
 * @param bool|null $check Whether to go forward with trashing/deletion.
 * @param WP_Post   $post  Post object.
 * @param bool      $force_delete Whether to bypass the trash, set when deactivating the plugin to clean up.
 */
function prevent_deletion( $check, $post, $force_delete = false ) {
	$survey_page = (int) get_option( SURVEY_PAGE_ID );

	if ( $survey_page === $post->ID ) {
		// Allow it, and delete the option if the page is force-deleted.
		if ( $force_delete ) {
			delete_option( SURVEY_PAGE_ID );
			return $check;
		}

		return false;
	}

	return $check;
}

/**
 * Get the content of the survey page.
 *
 * @return string
 */
function get_page_content() {
	return <<<EOT
	<!-- wp:paragraph -->
	<p>If you recently attended a WordPress event, please take the survey below to help us make them even better in the future.</p>
	<!-- /wp:paragraph -->

	<!-- wp:jetpack/contact-form {"subject":"New feedback received from your website","style":{"spacing":{"padding":{"top":"16px","right":"16px","bottom":"16px","left":"16px"}}}} -->
	<div class="wp-block-jetpack-contact-form" style="padding-top:16px;padding-right:16px;padding-bottom:16px;padding-left:16px"><!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:heading -->
	<h2 class="wp-block-heading"><strong>Part I. Tell us about you!</strong></h2>
	<!-- /wp:heading -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"How long have you been using WordPress?","required":true,"requiredText":"(required)","options":[]} -->
	<!-- wp:jetpack/field-option-radio {"label":"More than 1 year"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"1 year"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"Less than a year"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"I don't use WordPress yet"} /-->
	<!-- /wp:jetpack/field-radio --></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-checkbox-multiple {"label":"Which of these best describes you? ","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-checkbox {"label":"Personal Blogger"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Company Blogger"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Designer"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Junior Developer"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Senior Developer"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Project Manager"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"System Administrator/IT Professional"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Sales/Marketing/PR"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Business Owner"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"WordPress Fan"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Other: (Please specify.)"} /-->
	<!-- /wp:jetpack/field-checkbox-multiple -->

	<!-- wp:jetpack/field-text {"label":"Other","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-textarea {"label":"Please provide link(s) to any WordPress Meetup groups where you are actively participating.","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"Have you attended WordPress events before?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Yes"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"No"} /-->
	<!-- /wp:jetpack/field-radio -->

	<!-- wp:jetpack/field-text {"label":"If yes, please tell us what event, where and when.","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-checkbox-multiple {"label":"Which of the following role(s) have you contributed to in the past?","requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-checkbox {"label":"WordPress event Organizer"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"WordPress event Volunteer"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"WordPress event Sponsor"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"WordPress event Speaker/Trainer/Facilitator"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Other: (Please specify.)"} /-->
	<!-- /wp:jetpack/field-checkbox-multiple -->

	<!-- wp:jetpack/field-text {"label":"If other, please specify","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-checkbox-multiple {"label":"Did this event allow you to experience any of the following roles?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-checkbox {"label":"First-time WordPress event Attendee"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"First-time WordPress event Organizer"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"First-time WordPress event Volunteer"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"First-time WordPress event Sponsor"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"First-time WordPress event Speaker/Trainer/Facilitator"} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Other: (Please specify.)"} /-->
	<!-- /wp:jetpack/field-checkbox-multiple -->

	<!-- wp:jetpack/field-text {"label":"If other, please specify","requiredText":"(required)"} /--></div>
	<!-- /wp:group --></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:heading -->
	<h2 class="wp-block-heading"><strong><strong>Part II. Tell us about the event you attended!</strong></strong></h2>
	<!-- /wp:heading -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"How did you hear about the event?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"How satisfied are you with the event?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Extremely satisfied"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"Satisfied"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"Neutral"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"Dissatisfied"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"Extremely unhappy"} /-->
	<!-- /wp:jetpack/field-radio --></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-checkbox-multiple {"label":"What aspects of the event were valuable to you?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-checkbox {"label":"I was inspired to contribute to WordPress."} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"I got help with a specific WordPress problem."} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"I made new connections."} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"I learned new WordPress skills."} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"I deepened an established relationship."} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"I was inspired to do more with WordPress."} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"I contributed to WordPress."} /-->

	<!-- wp:jetpack/field-option-checkbox {"label":"Other: (Please specify.) "} /-->
	<!-- /wp:jetpack/field-checkbox-multiple -->

	<!-- wp:jetpack/field-text {"label":"If other, please specify","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-textarea {"label":"What changes would you suggest to the event organizers? ","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"Would you like to help contribute to WordPress events in the future?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Yes"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"No"} /-->
	<!-- /wp:jetpack/field-radio -->

	<!-- wp:jetpack/field-email {"label":"If your answer is yes, please enter your email address:","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"How likely are you to recommend the event to others?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Very Likely"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"Likely"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"Neutral"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"Unlikely"} /-->

	<!-- wp:jetpack/field-option-radio {"label":"Very Unlikely"} /-->
	<!-- /wp:jetpack/field-radio --></div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-email {"label":"If you are willing to answer follow-up questions about this survey, please enter your email address here.  (e.g. john@example.com)","requiredText":"(required)","options":[""]} /--></div>
	<!-- /wp:group --></div>
	<!-- /wp:group -->

	<!-- wp:jetpack/button {"element":"button","text":"Send Feedback","lock":{"remove":true}} /--></div>
	<!-- /wp:jetpack/contact-form -->
EOT;
}

/**
 * Turns on the page by changing its status to 'publish'.
 *
 * @return int|WP_Error
 */
function publish_survey_page() {
	return wp_update_post( array(
		'ID'            => get_option( SURVEY_PAGE_ID ),
		'post_status'   => 'publish',
	) );
}

/**
 * Generate a link to the front end feedback UI for a particular session.
 *
 * @return string|bool
 */
function get_survey_page_url() {
	return get_permalink( get_option( SURVEY_PAGE_ID ) );
}


/**
 * Create the Survey page, save ID into an option.
 */
function add_page() {
	$page_id = get_option( SURVEY_PAGE_ID );
	if ( $page_id ) {
		return;
	}

	$page_id = wp_insert_post( array(
		'post_title'   => __( 'Attendee Survey', 'wordcamporg' ),
		/* translators: Page slug for the attendee survey. */
		'post_name'    => __( 'attendee survey', 'wordcamporg' ),
		'post_content' => get_page_content(),
		'post_status'  => 'draft',
		'post_type'    => 'page',
	) );

	if ( $page_id > 0 ) {
		update_option( SURVEY_PAGE_ID, $page_id );
	}
}

/**
 * Turns off the page by changing its status to 'draft'.
 */
function disable_page() {
	$page_id = get_option( SURVEY_PAGE_ID );

	if ( ! $page_id ) {
		return;
	}

	return wp_update_post( array(
		'ID'            => $page_id,
		'post_status'   => 'draft',
	) );
}

/**
 * Delete the Survey page and associated meta data.
 */
function delete_page() {
	$page_id = get_option( SURVEY_PAGE_ID );
	wp_delete_post( $page_id, true );
	delete_option( SURVEY_PAGE_ID );
}
