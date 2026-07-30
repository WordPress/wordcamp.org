<?php

namespace WordPressdotorg\Events_2023\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the theme files that we'll need to be active for the tests.
 */
function manually_load_plugin() {
	require_once dirname( __DIR__ ) . '/inc/events-query.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
