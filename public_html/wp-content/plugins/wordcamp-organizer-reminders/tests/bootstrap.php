<?php

namespace WordCamp\Organizer_Reminders\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 */
function manually_load_plugin() {
	// Needed for `get_wordcamp_site_id()`.
	require_once SUT_WPMU_PLUGIN_DIR . '/4-helpers-wcpt.php';

	require_once dirname( __DIR__ )            . '/bootstrap.php';
	require_once dirname( dirname( __DIR__ ) ) . '/multi-event-sponsors/bootstrap.php';
	require_once dirname( dirname( __DIR__ ) ) . '/wcpt/wcpt-event/class-event-loader.php';
	require_once dirname( dirname( __DIR__ ) ) . '/wcpt/wcpt-wordcamp/wordcamp-loader.php';
	require_once dirname( dirname( __DIR__ ) ) . '/wcpt/wcpt-functions.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
