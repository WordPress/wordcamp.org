<?php

namespace WordCamp\Budgets_Dashboard\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 */
function manually_load_plugin() {
	require_once SUT_WP_CONTENT_DIR . '/mu-plugins-private/wporg-mu-plugins/pub-sync/utilities/class-export-csv.php';

	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/wordcamp-budgets.php';
	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/payment-request.php';
	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/reimbursement-request.php';
	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/sponsor-invoice.php';
	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/encryption.php';

	// Registers the `wcp_payment_request` post type on `init`. Without this,
	// the dashboard tests create posts of that type before it's registered,
	// which trips a "map_meta_cap called incorrectly" notice. Store it in the
	// same global the plugin uses at runtime so tests can invoke its methods.
	//
	// `reimbursement-request.php` and `sponsor-invoice.php` register their post
	// types and status filters on `require`, so tests can exercise all three
	// budget CPTs.
	$GLOBALS['wcp_payment_request'] = new \WCP_Payment_Request();

	require_once dirname( __DIR__ )  . '/includes/payment-requests-dashboard.php';
	require_once dirname( __DIR__ )  . '/includes/wordcamp-budgets-dashboard.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
