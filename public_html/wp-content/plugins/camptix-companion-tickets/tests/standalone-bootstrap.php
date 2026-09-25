<?php
/**
 * Standalone PHPUnit bootstrap for running ONLY the Companion Tickets suite.
 *
 * The repo-wide phpunit-bootstrap.php loads every plugin's test bootstrap,
 * some of which require third-party plugins (e.g. Jetpack) that aren't present
 * in every local checkout. This bootstrap loads just CampTix core + this addon
 * so the suite can run in isolation. CI uses the integrated harness.
 *
 * Usage (inside the phpunit_wp container, from /app):
 *   phpunit -c public_html/wp-content/plugins/camptix-companion-tickets/phpunit-standalone.xml.dist
 *
 * NOTE: intentionally NOT namespaced, so the get_wordcamp_post() shim below is
 * declared in the global namespace where CampTix calls it.
 */

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/*
 * CampTix calls this WordCamp.org helper (normally provided by the wcpt /
 * wc-post-types plugins) during ticket/attendee save, via is_wordcamp_closed().
 * In this isolated CampTix-only run those plugins aren't loaded, so provide a
 * no-op meaning "no WordCamp post" — is_wordcamp_closed() handles false safely.
 */
if ( ! function_exists( 'get_wordcamp_post' ) ) {
	/**
	 * Test shim for the WordCamp.org helper (see the note above). Returns false
	 * so is_wordcamp_closed() treats this run as "no WordCamp post".
	 *
	 * @return false
	 */
	function get_wordcamp_post() {
		return false;
	}
}

$core_tests_directory = getenv( 'WP_TESTS_DIR' );
if ( ! $core_tests_directory ) {
	$core_tests_directory = '/tmp/wp/wordpress-tests-lib';
}

require_once $core_tests_directory . '/includes/functions.php';

/**
 * Load CampTix core, the admin-flags addon (the auto-flag feature's display
 * layer — loading it makes those code paths testable), then this addon.
 */
function camptix_companion_standalone_load_plugins() {
	require_once dirname( __DIR__, 2 ) . '/camptix/camptix.php';
	require_once dirname( __DIR__, 2 ) . '/camptix-admin-flags/camptix-admin-flags.php';
	require_once dirname( __DIR__ ) . '/camptix-companion-tickets.php';
}
tests_add_filter( 'muplugins_loaded', 'camptix_companion_standalone_load_plugins' );

require $core_tests_directory . '/includes/bootstrap.php';
