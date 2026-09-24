<?php

namespace WordCamp\Forms_To_Drafts\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 *
 * The gate reads the JWT-signed source through Jetpack's contact-form classes,
 * so Jetpack has to be loaded before the plugin under test.
 */
function manually_load_plugins() {
	require_once SUT_WPMU_PLUGIN_DIR . '/3-helpers-misc.php';
	require_once dirname( dirname( __DIR__ ) ) . '/jetpack/jetpack.php';
	require_once dirname( __DIR__ ) . '/wordcamp-forms-to-drafts.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins' );
