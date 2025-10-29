<?php

// Require composer dependencies.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

const WORDCAMP_NETWORK_ID   = 1;
const WORDCAMP_ROOT_BLOG_ID = 5;
const EVENTS_NETWORK_ID     = 2;
const EVENTS_ROOT_BLOG_ID   = 47;
const CAMPUS_NETWORK_ID     = 3;
const CAMPUS_ROOT_BLOG_ID   = 47;
const SITE_ID_CURRENT_SITE  = WORDCAMP_NETWORK_ID;
const BLOG_ID_CURRENT_SITE  = WORDCAMP_ROOT_BLOG_ID;

define( 'WP_PLUGIN_DIR', __DIR__ . '/public_html/wp-content/plugins' );
define( 'SUT_WP_CONTENT_DIR', __DIR__ . '/public_html/wp-content/' ); // WP_CONTENT_DIR will be in `WP_TESTS_DIR`.
define( 'SUT_WPMU_PLUGIN_DIR', SUT_WP_CONTENT_DIR . '/mu-plugins' ); // WPMU_PLUGIN_DIR will be in `WP_TESTS_DIR`.

$core_tests_directory = getenv( 'WP_TESTS_DIR' );

if ( ! $core_tests_directory ) {
	$core_tests_directory = rtrim( sys_get_temp_dir(), '/\\' ) . '/wp/wordpress-tests-lib';
	// Necessary for the CampTix tests.
	putenv( "WP_TESTS_DIR=$core_tests_directory" );
}

if ( ! $core_tests_directory ) {
	echo "Could not find $core_tests_directory/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	return;
}

// Give access to tests_add_filter() function.
require_once( $core_tests_directory . '/includes/functions.php' );

/*
 * Load individual plugin bootstrappers
 *
 * There may eventually be cases where these conflict with one another (e.g., some need to run in context of
 * wp-admin while others need to run in front-end context), but it works for now. If they ever do conflict, then
 * that's probably a smell that we shouldn't be using PHPUnit for integration tests, though.
 *
 * If we don't want to migrate to Selenium etc, then another option might be using a PHPUnit listener to load the
 * bootstrap for a particular suite before the suite loads (see https://stackoverflow.com/a/30170762/450127). It's
 * not clear if that would properly isolate them from each other, and allow multiple independent contexts, though.
 */
require_once( WP_PLUGIN_DIR . '/wordcamp-organizer-reminders/tests/bootstrap.php' );
require_once WP_PLUGIN_DIR . '/wcpt/tests/bootstrap.php';
require_once( WP_PLUGIN_DIR . '/wordcamp-remote-css/tests/bootstrap.php' );
require_once WP_PLUGIN_DIR . '/wordcamp-speaker-feedback/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/wordcamp-payments-network/tests/bootstrap.php';
require_once SUT_WPMU_PLUGIN_DIR . '/tests/bootstrap.php';

/*
 * This has to be the last plugin bootstrapper, because it includes the Core test bootstrapper, which would
 * short-circuits any other plugin bootstrappers than run after it. We can remove that when we remove CampTix
 * from the w.org directory and make it a wordcamp.org-only plugin.
 */
require_once( WP_PLUGIN_DIR . '/camptix/tests/bootstrap.php' );

require_once( $core_tests_directory . '/includes/bootstrap.php' );

/*
 * Include any custom TestCase classes or other PHPUnit utilities.
 *
 * This has to be done after Core's bootstrapper finished, so that PHPUnit classes will be available.
 */
require_once( __DIR__ . '/phpunit-database-testcase.php' );


/**
 * If a site creation attempts to occur with a specific blog_id, force it.
 *
 * WordCamp operates on a number of assumptions that require specific blog_ids to be used.
 * This is a hacky hack to ensure that the blog_ids are always what we expect them to be.
 *
 * @see WordCamp\Tests\Database_TestCase::wpSetUpBeforeClass()
 */
function normalize_site_data( $data ) {
	if (
		// Nothing specified.
		! isset( $data['blog_id'] ) ||
		// Site exists, don't mess with it.. This will likely cause test failures.
		get_site( $data['blog_id'] )
	) {
		return $data;
	}

	// Filter the WPDB::update() call to include the `blog_id` field..
	add_filter(
		'query',
		$callback = static function ( $query ) use ( $data, &$callback ) {
			global $wpdb;

			if ( str_starts_with( $query, "INSERT INTO `{$wpdb->blogs}`" ) ) {
				$blog_id = intval( $data['blog_id'] );
				$query   = preg_replace(
					"/(INSERT INTO `{$wpdb->blogs}`)\s*\((.+)\) VALUES \(/",
					'$1 (`blog_id`, $2 ) VALUES ( ' . $blog_id . ', ',
					$query
				);

				// Unhook, we've done our job.
				remove_filter( 'query', $callback );
			}

			return $query;
		}
	);

	return $data;
}
tests_add_filter( 'wp_normalize_site_data', __NAMESPACE__ . '\normalize_site_data', 10, 2 );
