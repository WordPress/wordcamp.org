<?php

namespace WordCamp\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests
 */
function manually_load_plugins() {
	define( 'WORDCAMP_ENVIRONMENT', 'local' );
	define( 'NOBLOGREDIRECT',       'https://central.wordcamp.test' );
	define( 'SANDBOX_SLACK_USERNAME',                'UABCD1234' );
	define( 'WORDCAMP_LOGS_SLACK_CHANNEL',           '#logs' );
	define( 'WORDCAMP_LOGS_GUTENBERG_SLACK_CHANNEL', '#logs-gutenberg' );
	define( 'WORDCAMP_LOGS_JETPACK_SLACK_CHANNEL',   '#logs-jetpack' );

	define( 'DISALLOW_UNFILTERED_HTML', true );
	define( 'DISALLOW_FILE_MODS',       true );
	define( 'DISALLOW_FILE_EDIT',       true );

	// This isn't called by default when running tests because it's a `SHORTINIT` context.
	ms_upload_constants();

	require_once dirname( dirname( __DIR__ ) ) . '/sunrise.php';
	require_once dirname( dirname( __DIR__ ) ) . '/sunrise-events.php';

	require_once dirname( __DIR__ ) . '/0-error-handling.php';
	require_once dirname( __DIR__ ) . '/wordcamp/lets-encrypt-helper.php';
	require_once dirname( __DIR__ ) . '/latest-site-hints.php';
	require_once dirname( __DIR__ ) . '/trusted-deputy-capabilities.php';
	require_once dirname( __DIR__ ) . '/wcorg-subroles.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins', 1 );

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
		$callback = static function ( $query ) use ( $data, & $callback ) {
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
