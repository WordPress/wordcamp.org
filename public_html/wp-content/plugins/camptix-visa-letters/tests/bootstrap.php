<?php
/**
 * PHPUnit bootstrap for the CampTix Visa Letters suite, in the repo-wide harness.
 *
 * Only registers the add-on with CampTix's loader. CampTix itself is loaded by its
 * own bootstrap -- which has to stay last in phpunit-bootstrap.php because it
 * includes Core's -- and wcpt provides the real get_wordcamp_post() that attendee
 * saves depend on.
 *
 * `wordcamp-docs` is deliberately NOT loaded here: the add-on has to behave when
 * its PDF generator is absent, and that is asserted by the suite.
 */

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugin that we'll need to be active for the tests.
 */
function camptix_visa_letters_manually_load_plugin() {
	require_once WP_PLUGIN_DIR . '/camptix/tests/trait-wordcamp-root-blog.php';
	require_once WP_PLUGIN_DIR . '/camptix-visa-letters/tests/trait-visa-letter-fixtures.php';
	require_once WP_PLUGIN_DIR . '/camptix-visa-letters/camptix-visa-letters.php';
}
tests_add_filter( 'muplugins_loaded', 'camptix_visa_letters_manually_load_plugin' );
