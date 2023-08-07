<?php

namespace WordCamp\Budgets_Dashboard\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 */
function manually_load_plugin() {
	// @todo switch to `require_once` once it's accessible in all local environments.
	// @link https://github.com/WordPress/wordcamp.org/issues/769
	include_once SUT_WP_CONTENT_DIR . '/mu-plugins-private/wporg-mu-plugins/pub-sync/utilities/class-export-csv.php';

	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/wordcamp-budgets.php';
	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/payment-request.php';
	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/encryption.php';
	require_once WP_PLUGIN_DIR . '/wordcamp-payments/bootstrap.php';

	require_once dirname( __DIR__ )  . '/includes/payment-requests-dashboard.php';
	require_once dirname( __DIR__ )  . '/includes/wordcamp-budgets-dashboard.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
