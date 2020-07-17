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

	// Needed for checking subrole capabilities. The ID is 1 because there's only one site in the test instance.
	define( 'BLOG_ID_CURRENT_SITE', 1 );

	require_once dirname( dirname( __DIR__ ) ) . '/sunrise.php';

	require_once dirname( __DIR__ ) . '/0-error-handling.php';
	require_once dirname( __DIR__ ) . '/lets-encrypt-helper.php';
	require_once dirname( __DIR__ ) . '/seo.php';
	require_once dirname( __DIR__ ) . '/wcorg-json-api.php';
	require_once dirname( __DIR__ ) . '/wcorg-subroles.php';
}

tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugins' );
