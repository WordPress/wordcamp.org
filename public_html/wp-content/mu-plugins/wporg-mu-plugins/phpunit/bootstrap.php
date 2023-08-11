<?php
/**
 * PHPUnit bootstrap file
 *
 * Derived from Gutenberg.
 */

// Require composer dependencies.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Detect where to load the WordPress tests environment from.
if ( false !== getenv( 'WP_TESTS_DIR' ) ) {
	$_test_root = getenv( 'WP_TESTS_DIR' );
} else {
	$_test_root = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

// Give access to tests_add_filter() function.
require_once $_test_root . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-content/mu-plugins/loader.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Adds a wp_die handler for use during tests.
 *
 * If bootstrap.php triggers wp_die, it will not cause the script to fail. This
 * means that tests will look like they passed even though they should have
 * failed. So we throw an exception if WordPress dies during test setup. This
 * way the failure is observable.
 *
 * @param string|WP_Error $message The error message.
 *
 * @throws Exception When a `wp_die()` occurs.
 */
function fail_if_died( $message ) {
	if ( is_wp_error( $message ) ) {
		$message = $message->get_error_message();
	}

	throw new Exception( 'WordPress died: ' . $message );
}
tests_add_filter( 'wp_die_handler', 'fail_if_died' );

// Start up the WP testing environment.
require_once $_test_root . '/includes/bootstrap.php';

// Use existing behavior for wp_die during actual test execution.
remove_filter( 'wp_die_handler', 'fail_if_died' );
