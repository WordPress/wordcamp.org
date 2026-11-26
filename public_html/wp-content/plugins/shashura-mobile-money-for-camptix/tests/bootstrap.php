<?php

namespace Shasmomo\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

$core_tests_directory = getenv( 'WP_TESTS_DIR' );

if ( ! $core_tests_directory ) {
	return;
}

require_once $core_tests_directory . '/includes/functions.php';

/**
 * Load the plugin before CampTix initializes its addons.
 */
function manually_load_plugin() {
	require_once dirname( __DIR__ ) . '/shashura-mobile-money-for-camptix.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );

/**
 * Load the payment method class directly so tests can instantiate it on demand.
 */
function manually_load_gateway() {
	if ( class_exists( '\CampTix_Payment_Method' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-shashura-payment-method.php';
	}
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_gateway', 20 );
