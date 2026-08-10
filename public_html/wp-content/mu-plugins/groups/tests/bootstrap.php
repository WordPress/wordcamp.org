<?php

namespace WordCamp\Groups\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugin(s) that we'll need to be active for the tests.
 *
 * GatherPress is gitignored/third-party (not a composer dependency), so it's
 * only present if the phpunit environment installed it first — see
 * `.docker/bin/install-test-suite.sh` / `.github/workflows/unit-tests.yml`.
 *
 * @throws \RuntimeException If GatherPress isn't installed.
 */
function manually_load_plugin() {
	$gatherpress_file = WP_PLUGIN_DIR . '/gatherpress/gatherpress.php';

	if ( ! file_exists( $gatherpress_file ) ) {
		throw new \RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for developers.
			"GatherPress is required by this suite but was not found at {$gatherpress_file}. Run `.docker/bin/install-test-suite.sh` (see also `.github/workflows/unit-tests.yml`) first."
		);
	}

	require_once $gatherpress_file;

	// `group-site-provisioning.php` calls `WordCamp\Logger\log()`, which isn't
	// autoloaded in the test environment the way it is on a real request.
	require_once dirname( __DIR__, 2 ) . '/1-logger.php';

	require_once dirname( __DIR__ ) . '/gatherpress-groups-tweaks.php';
	require_once dirname( __DIR__ ) . '/gatherpress-recurring-events.php';
	require_once dirname( __DIR__ ) . '/wporg-groups-archive.php';
	require_once dirname( __DIR__ ) . '/group-site-provisioning.php';
	require_once dirname( __DIR__ ) . '/network-messaging.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
