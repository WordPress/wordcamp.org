<?php

// Require composer dependencies.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/*
 * Several suites (group-site-provisioning, network-messaging, etc.) exercise
 * code paths that intentionally call `WordCamp\Logger\log()` -- a thin
 * wrapper around `error_log()` -- to test that failures get logged. With no
 * `error_log` destination configured, PHP writes those to stderr, which CI
 * captures inline with the test output. Route them to a file instead so the
 * `Running unit tests` step only shows PHPUnit's own output.
 *
 * This redirects every `error_log()` call in the process, not just the
 * intentional `WordCamp\Logger\log()` ones -- but ordinary PHP warnings/
 * notices raised during a test are already turned into failures by
 * `convertWarningsToExceptions`/`convertNoticesToExceptions` in
 * phpunit.xml.dist, so they never reach `error_log()` in the first place.
 * What can still land in this file is either an expected logger entry, or
 * something that bypassed PHPUnit's handler (e.g. a fatal error). The
 * shutdown function below re-reads the file once the run ends and fails the
 * build if it contains anything that isn't a recognized `WordCamp\Logger`
 * entry, so a real error can't silently accumulate in a file CI never
 * publishes.
 */
$wordcamp_phpunit_error_log = sys_get_temp_dir() . '/wordcamp-phpunit-error.log';
ini_set( 'error_log', $wordcamp_phpunit_error_log );

// Start each run with an empty log, so a stale entry can't fail a later run.
file_put_contents( $wordcamp_phpunit_error_log, '' );

register_shutdown_function(
	static function () use ( $wordcamp_phpunit_error_log ) {
		if ( ! file_exists( $wordcamp_phpunit_error_log ) ) {
			return;
		}

		// Matches `[dd-mon-yyyy hh:mm:ss tz] [request-id] file:line - function:code -- {json}`,
		// the format written by `WordCamp\Logger\log()` (see 1-logger.php).
		$logger_entry_pattern = '/^\[[^\]]+\] \[[^\]]+\] \S+:\d+ - \S+:\S* -- \{.*\}$/';
		$unexpected           = array();

		foreach ( file( $wordcamp_phpunit_error_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			if ( ! preg_match( $logger_entry_pattern, $line ) ) {
				$unexpected[] = $line;
			}
		}

		if ( $unexpected ) {
			fwrite(
				STDERR,
				"\nUnexpected entries were written to PHP's error log during the test run " .
				'(i.e. not WordCamp\\Logger\\log() calls) -- fix the underlying error instead of ' .
				'letting it accumulate silently in ' . $wordcamp_phpunit_error_log . ":\n\n" .
				implode( "\n", $unexpected ) . "\n"
			);
			exit( 1 );
		}
	}
);

const WORDCAMP_NETWORK_ID   = 1;
const WORDCAMP_ROOT_BLOG_ID = 5;
const EVENTS_NETWORK_ID     = 2;
const EVENTS_ROOT_BLOG_ID   = 47;
const CAMPUS_NETWORK_ID     = 3;
const CAMPUS_ROOT_BLOG_ID   = 47;
const GROUPS_NETWORK_ID     = 4;
const GROUPS_ROOT_BLOG_ID   = 52;
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
require_once WP_PLUGIN_DIR . '/wordcamp-payments/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/wordcamp-reports/tests/bootstrap.php';
require_once SUT_WPMU_PLUGIN_DIR . '/tests/bootstrap.php';
require_once SUT_WPMU_PLUGIN_DIR . '/wporg-groups-frontend/tests/bootstrap.php';
require_once SUT_WPMU_PLUGIN_DIR . '/gatherpress-recurring-events/tests/bootstrap.php';
require_once SUT_WPMU_PLUGIN_DIR . '/groups/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/wordcamp-coming-soon-page/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/wordcamp-forms-to-drafts/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/campt-indian-payment-gateway/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/multi-event-sponsors/tests/bootstrap.php';

/*
 * GatherPress hooks `send_headers` to set a novelty HTTP header ("Go
 * Bills!"). By the time that fires in this suite, the core test bootstrap
 * has already written to stdout via `system()` (installing the test DB),
 * so calling header() anywhere afterwards trips a "headers already sent"
 * warning. It has no effect on WordCamp's use of GatherPress, so drop it
 * before any test runs. Priority 30 ensures GatherPress -- loaded by the
 * `muplugins_loaded` callbacks above, all at the default priority -- has
 * already registered the hook by the time this runs.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		if ( class_exists( 'GatherPress\Core\Setup' ) ) {
			remove_action( 'send_headers', array( GatherPress\Core\Setup::get_instance(), 'smash_table' ) );
		}
	},
	30
);

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
