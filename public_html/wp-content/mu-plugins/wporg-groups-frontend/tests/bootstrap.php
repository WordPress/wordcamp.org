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
 * Without it, this plugin's own bootstrap no-ops (see its `class_exists()`
 * guard), so tests that need real behavior will fail with a clear reason
 * rather than a fatal.
 */
function manually_load_plugins() {
	$gatherpress_file = WP_PLUGIN_DIR . '/gatherpress/gatherpress.php';

	if ( file_exists( $gatherpress_file ) ) {
		require_once $gatherpress_file;
	}

	require_once dirname( __DIR__ ) . '/wporg-groups-frontend.php';

	// The plugin's own bootstrap() only runs on `plugins_loaded`, gated on
	// `\GatherPress\Core\Event\Event` existing — mirrors production loading.
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins' );
