<?php

namespace WordCamp\Participation_Notifier\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load helpers and the participation notifier for unit tests.
 *
 * WORDCAMP_ENVIRONMENT is `local` in the test harness, so the plugin file defines
 * the class without instantiating it or requiring Profiles helpers.
 */
function manually_load_plugin() {
	require_once SUT_WPMU_PLUGIN_DIR . '/4-helpers-wcpt.php';
	require_once dirname( __DIR__ ) . '/wordcamp-participation-notifier.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
