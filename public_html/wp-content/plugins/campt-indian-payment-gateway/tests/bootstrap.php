<?php

namespace CampTix_Indian_Payments\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

$core_tests_directory = getenv( 'WP_TESTS_DIR' );

if ( ! $core_tests_directory ) {
	echo "\nPlease set the WP_TESTS_DIR environment variable to the folder where WordPress' PHPUnit tests live --";
	echo "\ne.g., export WP_TESTS_DIR=/srv/www/wordpress-develop/tests/phpunit\n";

	return;
}

require_once $core_tests_directory . '/includes/functions.php';

/**
 * Load the plugin, and the gateway class the tests exercise.
 *
 * The gateway is normally required by `load_payment_methods()` on
 * `camptix_load_addons`, which only fires once CampTix has been set up. The
 * tests instantiate the class directly instead of going through the addon
 * registry, so load it here -- at a later priority than CampTix's own
 * bootstrapper, which is what defines the `CampTix_Payment_Method` parent.
 */
function manually_load_plugin() {
	require_once dirname( __DIR__ ) . '/campt-indian-payment-gateway.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );

/**
 * Load the Instamojo gateway class.
 */
function manually_load_gateway() {
	require_once dirname( __DIR__ ) . '/inc/instamojo/class-camptix-payment-method-instamojo.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_gateway', 20 );
