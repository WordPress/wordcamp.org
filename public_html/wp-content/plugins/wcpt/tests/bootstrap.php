<?php

namespace WordCamp\WCPT\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests
 */
function manually_load_plugins() {
	require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/sunrise.php';
	require_once dirname( __DIR__ ) . '/wcpt-wordcamp/wordcamp-new-site.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins' );
