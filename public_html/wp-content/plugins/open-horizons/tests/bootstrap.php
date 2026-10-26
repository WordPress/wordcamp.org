<?php
/**
 * Loads the plugin for the integrated test harness, which phpunit-bootstrap.php sets up.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once dirname( __DIR__ ) . '/bootstrap.php';
	}
);
