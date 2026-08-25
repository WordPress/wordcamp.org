<?php

namespace WordCamp\Budgets\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugin for testing.
 */
function manually_load_plugin() {
	// Register the post types needed for tests.
	register_post_type( 'wcp_payment_request' );
	register_post_type( 'wcb_reimbursement' );

	require_once dirname( __DIR__ ) . '/includes/payment-request.php';
	require_once dirname( __DIR__ ) . '/includes/reimbursement-request.php';
	require_once dirname( __DIR__ ) . '/includes/privacy.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
