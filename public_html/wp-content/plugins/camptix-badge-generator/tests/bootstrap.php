<?php
/**
 * PHPUnit bootstrap for the CampTix Badge Generator suite, in the repo-wide harness.
 */

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugin that we'll need to be active for the tests.
 */
function camptix_badge_generator_manually_load_plugin() {
	require_once WP_PLUGIN_DIR . '/camptix/tests/trait-wordcamp-root-blog.php';
	require_once WP_PLUGIN_DIR . '/camptix-badge-generator/bootstrap.php';
}
tests_add_filter( 'muplugins_loaded', 'camptix_badge_generator_manually_load_plugin' );
