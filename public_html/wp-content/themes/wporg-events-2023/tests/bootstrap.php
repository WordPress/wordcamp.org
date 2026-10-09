<?php

namespace WordPressdotorg\Events_2023\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the parts of the theme under test.
 *
 * Only the file each test needs: the theme's `functions.php` registers a block from a `build/` directory
 * that only exists after `npm run build`, and pulls in blocks from wporg-mu-plugins that this suite
 * doesn't carry.
 */
function load_theme_files() {
	require_once dirname( __DIR__ ) . '/inc/feeds.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\load_theme_files' );
