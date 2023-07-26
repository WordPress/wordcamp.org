<?php

namespace WordCamp\SpeakerFeedback\Tests;

use WordCamp\SpeakerFeedback;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 */
function manually_load_plugin() {
	require_once SUT_WPMU_PLUGIN_DIR . '/3-helpers-misc.php';

	// Switch to a non-main site so that `wc-post-types` won't return early.
	// Site 1 isn't the main site for WordCamp.org, `WORDCAMP_ROOT_BLOG_ID` is.
	switch_to_blog( 1 );
	// Needed for registering post types.
	require_once WP_PLUGIN_DIR . '/wc-post-types/wc-post-types.php';
	restore_current_blog();

	// Initialize the plugin.
	require_once dirname( __DIR__ )  . '/wordcamp-speaker-feedback.php';

	SpeakerFeedback\load();
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
