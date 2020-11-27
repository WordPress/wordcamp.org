<?php

namespace WordCamp\RemoteCSS;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests.
 */
function manually_load_plugin() {
	$_SERVER['PHP_SELF'] = admin_url( 'themes.php?page=remote-css' );

	/*
	 * Defining WP_ADMIN is so that wordcamp-remote-css/bootstrap.php will load the app/*.php files.
	 * It may need to be refactored if we add tests for output-cached-css.php.
	 */
	define( 'WP_ADMIN',          true );
	define( 'JETPACK_DEV_DEBUG', true );

	// Initialize Jetpack.
	require_once dirname( dirname( __DIR__ ) ) . '/jetpack/jetpack.php';

	// Some of the sanitization lives here because it runs for both Custom CSS and Remote CSS.
	require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/mu-plugins/jetpack-tweaks/css-sanitization.php';

	// Initialize the remote CSS plugin.
	require_once dirname( __DIR__ )  . '/bootstrap.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
