<?php
/**
 * Integrated-harness bootstrap for the Multi-Event Sponsors test suite.
 *
 * Loaded by the repo-wide phpunit-bootstrap.php. For isolated local runs, use
 * phpunit-standalone.xml.dist instead (see tests/standalone-bootstrap.php).
 */

tests_add_filter(
	'muplugins_loaded',
	function () {
		require_once dirname( __DIR__ ) . '/bootstrap.php';
	}
);
