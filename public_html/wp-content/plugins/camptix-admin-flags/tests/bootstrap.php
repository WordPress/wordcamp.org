<?php
/**
 * PHPUnit bootstrap for the CampTix Admin Flags suite, in the repo-wide harness.
 *
 * Only registers the add-on with CampTix's loader. CampTix itself is loaded by its
 * own bootstrap, which has to stay last in phpunit-bootstrap.php because it
 * includes Core's.
 */

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugin that we'll need to be active for the tests.
 */
function camptix_admin_flags_manually_load_plugin() {
	require_once WP_PLUGIN_DIR . '/camptix/tests/trait-wordcamp-root-blog.php';
	require_once WP_PLUGIN_DIR . '/camptix-admin-flags/camptix-admin-flags.php';
}
tests_add_filter( 'muplugins_loaded', 'camptix_admin_flags_manually_load_plugin' );
