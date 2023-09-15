<?php
/**
 * Adds a page which contains the debrief survey.
 */

namespace WordCamp\OrganizerSurvey\DebriefSurvey\Page;

defined( 'WPINC' ) || die();

add_filter( 'pre_trash_post', __NAMESPACE__ . '\prevent_deletion', 10, 2 );
add_filter( 'pre_delete_post', __NAMESPACE__ . '\prevent_deletion', 10, 3 );

/**
 * Constants.
 */
const SURVEY_PAGE_ID = 'organizer_debrief_survey';


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
	<p>If you recently organized a WordPress event, please take the survey below to share your feedback about your experience.</p>
	<!-- /wp:paragraph -->
	
	<!-- wp:jetpack/contact-form {"subject":"New feedback received from your website","style":{"spacing":{"padding":{"top":"16px","right":"16px","bottom":"16px","left":"16px"}}}} -->
	<div class="wp-block-jetpack-contact-form" style="padding-top:16px;padding-right:16px;padding-bottom:16px;padding-left:16px"><!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"What event did you organize?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:heading -->
	<h2 class="wp-block-heading"><strong><strong><strong>Part I. <strong>General Information</strong></strong></strong></strong></h2>
	<!-- /wp:heading -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"How many tickets were sold?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"How many people actually attended?","required":true,"requiredText":"(required)"} /-->
	
	<!-- wp:jetpack/field-text {"label":"How many people attended your event as Attendees?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"How many people attended your event as Organizers?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"How many people attended your event as Volunteers?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"How many people attended your event as Sponsors?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"How many people attended your event as Speakers/Trainers/Facilitators?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"Did you livestream your event?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Yep!"} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"Nope!"} /-->
	<!-- /wp:jetpack/field-radio -->
	
	<!-- wp:jetpack/field-text {"label":"If you live streamed your event, what service did you use, and how many viewers did you have?","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"Have the videos and slides been uploaded for wordpress.tv yet?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Yes, they have all been uploaded."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"Some, but not all, we are still working on it."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"We have not started uploading videos and slides yet."} /-->
	<!-- /wp:jetpack/field-radio -->
	
	<!-- wp:jetpack/field-text {"label":"If they haven't been uploaded yet, is there something specific holding up the process?","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-checkbox-multiple {"label":"How was the Code of Conduct publicized at your event? (check all that apply) ","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-checkbox {"label":"It was on the website, linked from main navigation."} /-->
	
	<!-- wp:jetpack/field-option-checkbox {"label":"It was on the website, linked from other pages."} /-->
	
	<!-- wp:jetpack/field-option-checkbox {"label":"It was mentioned in pre-event emails."} /-->
	
	<!-- wp:jetpack/field-option-checkbox {"label":"It was mentioned in opening remarks."} /-->
	
	<!-- wp:jetpack/field-option-checkbox {"label":"It was printed on individual collateral (like name badges, programs, etc)."} /-->
	
	<!-- wp:jetpack/field-option-checkbox {"label":"It was printed on signs."} /-->
	
	<!-- wp:jetpack/field-option-checkbox {"label":"Other: (Please specify.)"} /-->
	<!-- /wp:jetpack/field-checkbox-multiple -->
	
	<!-- wp:jetpack/field-text {"label":"Other","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"Were there any reports of Code of Conduct violations at your event?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group --></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:heading -->
	<h2 class="wp-block-heading"><strong><strong>Part II. <strong>Budget</strong></strong></strong></h2>
	<!-- /wp:heading -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"How did you wind up in terms of budget?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"There is a surplus."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"We broke even."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"We lost money."} /-->
	<!-- /wp:jetpack/field-radio -->
	
	<!-- wp:jetpack/field-text {"label":"If there was a budget surplus/deficit, what was the amount?","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"Have all of your vendors been paid?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Yes, all of them."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"No, we still have more payments that we need to request."} /-->
	<!-- /wp:jetpack/field-radio -->
	
	<!-- wp:jetpack/field-text {"label":"If there are outstanding payments, what's the holdup?","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"Which expenses were unanticipated, or cost more than you expected?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"Have payments been received from all your sponsors?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Yes, all of them."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"No, but we have invoiced them all."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"No, we still have to invoice some late-addition sponsors."} /-->
	<!-- /wp:jetpack/field-radio -->
	
	<!-- wp:jetpack/field-text {"label":"If you are still waiting for any sponsor payments, which sponsors are they?","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"Did you receive in-kind donations or contributions? If yes, what are they?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group --></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:heading -->
	<h2 class="wp-block-heading"><strong><strong>Part III. </strong></strong>Opinions</h2>
	<!-- /wp:heading -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"What do you think went really well at your event?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-textarea {"label":"What could have gone better at your event? (This could include logistical things like printing name badges late, bigger things like overall schedule planning, or anything else that you've learned from this year.)","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"Would you do the event again?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Yes."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"No."} /-->
	<!-- /wp:jetpack/field-radio -->
	
	<!-- wp:jetpack/field-text {"label":"If not, why?","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"What were the goals that you wanted to reach by organizing this event?","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"Have you reached those goals","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Yes."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"No."} /-->
	<!-- /wp:jetpack/field-radio -->
	
	<!-- wp:jetpack/field-text {"label":"If not, what blocked you from reaching them? What can be improved for the next time?","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-checkbox-multiple {"label":"After completing the event, do you consider your event to be (check all that apply):","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-checkbox {"label":"Doable (less human and financial resources)"} /-->
	
	<!-- wp:jetpack/field-option-checkbox {"label":"Replicable (by other communities in different parts of the world)"} /-->
	
	<!-- wp:jetpack/field-option-checkbox {"label":"Scalable (can be changed in size or scale)"} /-->
	
	<!-- wp:jetpack/field-option-checkbox {"label":"Desirable (seen as useful, important or necessary by the community)"} /-->
	<!-- /wp:jetpack/field-checkbox-multiple --></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-radio {"label":"Did you have enough support from WordCamp Central?","required":true,"requiredText":"(required)"} -->
	<!-- wp:jetpack/field-option-radio {"label":"Yes."} /-->
	
	<!-- wp:jetpack/field-option-radio {"label":"No."} /-->
	<!-- /wp:jetpack/field-radio --></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-textarea {"label":"If there were things that made planning this event harder or easier, let us know!","requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-text {"label":"Have you written a recap post of the event yet? If so, please provide the URL so we can reblog it on WordCamp Central website.","required":true,"requiredText":"(required)"} /--></div>
	<!-- /wp:group -->
	
	<!-- wp:group {"layout":{"type":"constrained"}} -->
	<div class="wp-block-group"><!-- wp:jetpack/field-textarea {"label":"Is there anything else we should know about how your event went?","requiredText":"(required)"} /--></div>
	<!-- /wp:group --></div>
	<!-- /wp:group -->
	
	<!-- wp:paragraph -->
	<p>Thank you so much for sharing your feedback and providing an excellent experience to your community, we are very grateful for your contribution to the WordPress open source project!</p>
	<!-- /wp:paragraph -->
	
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
		'post_title'   => __( 'Organizer Survey (event debrief)', 'wordcamporg' ),
		'post_name'    => __( 'organizer debrief survey', 'wordcamporg' ),
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
