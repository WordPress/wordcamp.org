<?php

namespace WordCamp\QBO\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 */
function manually_load_plugin() {
	require_once SUT_WPMU_PLUGIN_DIR . '/1-logger.php';

	// Needed for `get_wordcamp_post()`.
	require_once SUT_WPMU_PLUGIN_DIR . '/4-helpers-wcpt.php';

	// Provides `WordCamp\QuickBooks\Client`. Without a stored token it returns an empty OAuth header and
	// realm ID instead of making requests, which is all the payload tests need.
	require_once SUT_WPMU_PLUGIN_DIR . '/quickbooks/quickbooks.php';

	require_once dirname( __DIR__ ) . '/wordcamp-qbo.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
