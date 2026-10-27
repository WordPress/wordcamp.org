<?php
/**
 * Loads the plugin for the integrated test harness, which phpunit-bootstrap.php sets up.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

// The FreeScout client refuses to make requests without these; tests fake the requests themselves.
if ( ! defined( 'FREESCOUT_API_KEY' ) ) {
	define( 'FREESCOUT_API_KEY', 'test-api-key' );
}

if ( ! defined( 'FREESCOUT_COMMUNITY_MAILBOX_ID' ) ) {
	define( 'FREESCOUT_COMMUNITY_MAILBOX_ID', 7 );
}

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// For the event names and email addresses the emails use.
		require_once SUT_WPMU_PLUGIN_DIR . '/4-helpers-wcpt.php';
		require_once dirname( __DIR__ ) . '/bootstrap.php';
	}
);
