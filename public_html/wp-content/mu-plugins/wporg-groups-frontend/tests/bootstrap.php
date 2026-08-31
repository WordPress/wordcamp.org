<?php

namespace WordCamp\Groups\Frontend\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 *
 * GatherPress is gitignored/third-party (not a composer dependency), so it's
 * only present if the phpunit environment installed it first — see
 * `.docker/bin/install-test-suite.sh` / `.github/workflows/unit-tests.yml`.
 * Required unconditionally: without it, this plugin's own bootstrap no-ops
 * (see its `class_exists()` guard) and the suite would report a misleading
 * green run instead of failing with the actual cause.
 *
 * @throws \RuntimeException If GatherPress isn't installed.
 */
function manually_load_plugins() {
	$gatherpress_file = WP_PLUGIN_DIR . '/gatherpress/gatherpress.php';

	if ( ! file_exists( $gatherpress_file ) ) {
		throw new \RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for developers.
			"GatherPress is required by this suite but was not found at {$gatherpress_file}. Run `.docker/bin/install-test-suite.sh` (see also `.github/workflows/unit-tests.yml`) first."
		);
	}

	require_once $gatherpress_file;

	// `group-ownership-transfer.php` calls `WordCamp\Logger\log()`, which isn't
	// autoloaded in the test environment the way it is on a real request.
	require_once dirname( __DIR__, 2 ) . '/1-logger.php';

	// The REST layer sanitizes post titles with `wcorg_sanitize_plain_text()`.
	require_once SUT_WPMU_PLUGIN_DIR . '/3-helpers-misc.php';

	// `test-post-titles.php` writes through core's posts controller, whose
	// `rest_after_insert_*` pass runs `the_content` filters. One of them
	// (`wc-post-types`) calls `site_supports_block_templates()`, which lives
	// here and is otherwise not loaded in this suite.
	require_once SUT_WPMU_PLUGIN_DIR . '/theme-templates/bootstrap.php';

	// The ownership-transfer REST controller (registered by
	// `wporg-groups-frontend.php` below) is a thin client of
	// `WordCamp\Groups\Ownership_Transfer\*`, which normally loads from the
	// always-on `mu-plugins/groups/` folder rather than from this plugin.
	require_once dirname( __DIR__, 2 ) . '/groups/wporg-groups-archive.php';
	require_once dirname( __DIR__, 2 ) . '/groups/group-ownership-transfer.php';

	require_once dirname( __DIR__ ) . '/wporg-groups-frontend.php';

	// The plugin's own bootstrap() only runs on `plugins_loaded`, gated on
	// `\GatherPress\Core\Event\Event` existing — mirrors production loading.
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins' );
