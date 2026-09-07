<?php

namespace CampTix\CompanionTickets\Tests;

if ( 'cli' !== php_sapi_name() ) {
	return;
}

/**
 * Load this addon plugin for the test run.
 *
 * CampTix core itself is loaded by camptix/tests/bootstrap.php (required last
 * in phpunit-bootstrap.php). This bootstrapper only needs to register our
 * plugin so its `camptix_load_addons` hook is in place before CampTix inits.
 */
function manually_load_plugin() {
	require_once dirname( __DIR__, 2 ) . '/camptix/tests/trait-wordcamp-root-blog.php';
	require_once dirname( __DIR__, 2 ) . '/camptix-admin-flags/camptix-admin-flags.php';
	require_once dirname( __DIR__ ) . '/camptix-companion-tickets.php';
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );
