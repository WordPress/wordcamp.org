<?php

namespace WordCamp\Coming_Soon_Page\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 */
function manually_load_plugins() {
	require_once dirname( __DIR__ ) . '/classes/wccsp-settings.php';
	require_once dirname( __DIR__ ) . '/classes/wccsp-customizer.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins' );
