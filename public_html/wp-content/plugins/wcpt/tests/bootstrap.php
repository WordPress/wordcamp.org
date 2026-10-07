<?php

namespace WordCamp\WCPT\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load the plugins that we'll need to be active for the tests
 */
function manually_load_plugins() {
	require_once SUT_WPMU_PLUGIN_DIR . '/3-helpers-misc.php';
	require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/sunrise.php';
	require_once dirname( __DIR__ ) . '/wcpt-wordcamp/wordcamp-new-site.php';

	/*
	 * `WCPT_Loader` only loads the admin class when `WP_ADMIN` is defined, which happens
	 * in another plugin's bootstrap, and a second plugin's bootstrap then assigns the
	 * global. Own both here so this suite does not depend on either of them. The loader
	 * comes first because `wordcamp-admin.php` resolves its own includes off `WCPT_DIR`.
	 */
	require_once dirname( __DIR__ ) . '/wcpt-loader.php';
	require_once dirname( __DIR__ ) . '/wcpt-wordcamp/wordcamp-admin.php';

	if ( ! isset( $GLOBALS['wordcamp_admin'] ) ) {
		$GLOBALS['wordcamp_admin'] = new \WordCamp_Admin();
	}
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins' );
