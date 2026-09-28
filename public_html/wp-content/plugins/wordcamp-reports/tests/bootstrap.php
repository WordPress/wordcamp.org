<?php
/**
 * PHPUnit bootstrapper for the WordCamp Reports plugin.
 *
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugin that we'll need to be active for the tests.
 *
 * The plugin registers its report exporters on `plugins_loaded`, which has not
 * fired yet at `muplugins_loaded`, so loading it here leaves that registration
 * intact -- which matters, because the registration is what makes the exporters
 * reachable in the first place.
 *
 * @return void
 */
function manually_load_plugin() {
	require_once dirname( __DIR__ ) . '/index.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
