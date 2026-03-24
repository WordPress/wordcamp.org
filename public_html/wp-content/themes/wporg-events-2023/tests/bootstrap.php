<?php

namespace WordPressdotorg\Events_2023\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the theme files needed for tests.
 */
function manually_load_theme() {
	require_once dirname( __DIR__ ) . '/inc/events-query.php';
}

tests_add_filter( 'after_setup_theme', __NAMESPACE__ . '\manually_load_theme' );
