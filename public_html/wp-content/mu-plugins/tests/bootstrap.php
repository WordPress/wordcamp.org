<?php

namespace WordCamp\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests
 */
function manually_load_plugins() {
	// Needed for checking subrole capabilities. The ID is 1 because there's only one site in the test instance.
	define( 'BLOG_ID_CURRENT_SITE', 1 );

	require_once dirname( __DIR__ ) . '/wcorg-json-api.php';
	require_once dirname( __DIR__ ) . '/wcorg-subroles.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins' );
