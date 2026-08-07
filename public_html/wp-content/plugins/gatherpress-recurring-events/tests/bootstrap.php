<?php
/**
 * PHPUnit bootstrap for GatherPress Recurring Events.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events\Tests
 */

namespace WordPressdotorg\GatherPress_Recurring_Events\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Loads GatherPress and its recurring-events extension for the test suite.
 *
 * @throws \RuntimeException If GatherPress isn't installed.
 */
function manually_load_plugins(): void {
	$gatherpress_file = WP_PLUGIN_DIR . '/gatherpress/gatherpress.php';

	if ( ! file_exists( $gatherpress_file ) ) {
		throw new \RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for developers.
			"GatherPress is required by this suite but was not found at {$gatherpress_file}. Run `.docker/bin/install-test-suite.sh` first."
		);
	}

	require_once $gatherpress_file;
	require_once dirname( __DIR__ ) . '/plugin.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins' );
