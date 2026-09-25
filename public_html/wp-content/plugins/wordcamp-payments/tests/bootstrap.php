<?php

namespace WordCamp\Budgets\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the parts of the plugin that the tests exercise.
 *
 * The budget CPTs themselves are registered by `wordcamp-payments-network/tests/bootstrap.php`, which runs
 * first; this adds the privacy layer that guards their attachments, and the class holding the file handling
 * those guards back up. Neither has side effects at load, so nothing is instantiated here.
 */
function manually_load_plugin() {
	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/privacy.php';
	require_once WP_PLUGIN_DIR . '/wordcamp-payments/includes/wordcamp-budgets.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin', 20 );
