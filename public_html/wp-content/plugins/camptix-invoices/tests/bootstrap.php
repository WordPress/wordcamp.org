<?php
/**
 * PHPUnit bootstrap for the CampTix Invoices suite, in the repo-wide harness.
 *
 * Only loads the plugin file. CampTix itself is loaded by its own bootstrap, which has to stay last in
 * phpunit-bootstrap.php because it includes Core's.
 */

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugin that we'll need to be active for the tests.
 */
function camptix_invoices_manually_load_plugin() {
	require_once WP_PLUGIN_DIR . '/camptix-invoices/camptix-invoices.php';
}
tests_add_filter( 'muplugins_loaded', 'camptix_invoices_manually_load_plugin' );
