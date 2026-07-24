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
 */
function manually_load_plugin() {
	$gatherpress_file = WP_PLUGIN_DIR . '/gatherpress/gatherpress.php';

	if ( file_exists( $gatherpress_file ) ) {
		require_once $gatherpress_file;
	}

	require_once dirname( __DIR__ ) . '/gatherpress-groups-tweaks.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
