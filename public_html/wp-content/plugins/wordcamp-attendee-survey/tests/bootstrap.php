<?php

namespace WordCamp\AttendeeSurvey\Tests;

use WordCamp\AttendeeSurvey;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 */
function manually_load_plugin() {

	// Initialize the plugin.
	require_once dirname( __DIR__ )  . '/wordcamp-attendee-survey.php';

	AttendeeSurvey\load();
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
