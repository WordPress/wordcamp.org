<?php

namespace CampTix\Tests;

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
 * Load the plugins that we'll need to be active for the tests
 */
function manually_load_plugin() {
	require_once dirname( __DIR__ ) . '/camptix.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );

/**
 * Enable the Require Login addon, like `camptix-tweaks` does on every WordCamp site.
 *
 * Addons must be registered before `camptix_init`, so this can't be done from inside a test.
 */
function load_addons( $addons ) {
	$addons['require-login'] = dirname( __DIR__ ) . '/addons/require-login.php';

	return $addons;
}
tests_add_filter( 'camptix_default_addons', __NAMESPACE__ . '\load_addons' );

require $core_tests_directory . '/includes/bootstrap.php';
