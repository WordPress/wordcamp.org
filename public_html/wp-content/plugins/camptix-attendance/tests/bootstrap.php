<?php
/**
 * PHPUnit bootstrap for the CampTix Attendance suite, in the repo-wide harness.
 *
 * Only registers the addon with CampTix's loader. CampTix itself is loaded by its
 * own bootstrap — which has to stay last in phpunit-bootstrap.php because it
 * includes Core's — and wcpt provides the real get_wordcamp_post() that the
 * standalone bootstrap has to shim.
 */

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugin that we'll need to be active for the tests.
 */
function camptix_attendance_manually_load_plugin() {
	require_once WP_PLUGIN_DIR . '/camptix/tests/trait-wordcamp-root-blog.php';
	require_once WP_PLUGIN_DIR . '/camptix-attendance/camptix-attendance.php';
}
tests_add_filter( 'muplugins_loaded', 'camptix_attendance_manually_load_plugin' );
