<?php

namespace WordCamp\Gutenberg_Tweaks;
use WCOR_Reminder, WordCamp\Budgets\Sponsor_Invoices;

defined( 'WPINC' ) || die();

add_filter( 'classic_editor_network_default_settings', __NAMESPACE__ . '\classic_editor_default_settings' );
add_filter( 'classic_editor_enabled_editors_for_post_type', __NAMESPACE__ . '\disable_editors_by_post_type', 10, 2 );


/**
 * Configure the default settings for the Classic Editor
 */
function classic_editor_default_settings( $defaults ) {
	$defaults['editor']      = 'block';
	$defaults['allow-users'] = true;

	return $defaults;
}

/**
 * Disable editors in post types that don't support them.
 *
 * @param array  $editors
 * @param string $post_type
 *
 * @return mixed
 */
function disable_editors_by_post_type( $editors, $post_type ) {
	// Define post type slug constants.
	require_once( WP_PLUGIN_DIR . '/wordcamp-organizer-reminders/wcor-reminder.php' );
	require_once( WP_PLUGIN_DIR . '/wcpt/wcpt-event/class-event-loader.php' );
	require_once( WP_PLUGIN_DIR . '/wcpt/wcpt-wordcamp/wordcamp-loader.php' );

		// make sure there aren't any side-effects, these shouldn't execute any code, only define things

	/*
	 * All of our first-party custom post types should be added here as soon as they fully support Gutenberg.
	 * Ideally the only post types that we have to support in both editors should be `post` and `page`.
	 */
	$disabled_in_classic = array( WCOR_Reminder::MANUAL_POST_TYPE_SLUG );

	/*
	 * These have custom interfaces/interactions that haven't been ported to Gutenberg yet. Over time, we should
	 * port them to Gutenberg. Once a CPT is ported, it should be disabled in the Classic Editor so that we only
	 * have to support the functionality in Gutenberg.
	 */
	$disabled_in_gutenberg = array(
		WCPT_POST_TYPE_ID, WCPT_MEETUP_SLUG,
		WCP_Payment_Request::POST_TYPE, MES_Sponsor::POST_TYPE_SLUG,
		WordCamp\Budgets\Sponsor_Invoices\POST_TYPE,
		WordCamp\Budgets\Reimbursement_Requests\POST_TYPE,
	);
		// todo include them where needed, test to make sure no fatals

	if ( in_array( $post_type, $disabled_in_classic ) ) {
		$editors['classic_editor'] = false;
	}

	if ( in_array( $post_type, $disabled_in_gutenberg ) ) {
		$editors['block_editor'] = false;
	}

	return $editors;
}
