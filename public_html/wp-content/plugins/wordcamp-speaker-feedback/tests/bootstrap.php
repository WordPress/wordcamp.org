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
	require_once WP_MU_PLUGIN_DIR . '/3-helpers-misc.php';

	// Initialize the plugin.
	require_once dirname( __DIR__ )  . '/wordcamp-speaker-feedback.php';

	SpeakerFeedback\load();
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
