<?php

$test_install     = getenv( 'WP_TESTS_DIR' );
$config_file_path = dirname( dirname( $test_install ) ) . '/wp-tests-config.php';

require_once dirname( dirname( $test_install ) ) . '/wp-tests-config.php';

echo "\n";

shell_exec( sprintf(
	// Capture the output to avoid showing the password warning message.
	"/usr/bin/env mysqladmin drop %s --host=%s --user=%s --password=%s --force 2>&1",
	escapeshellarg( DB_NAME ),
	escapeshellarg( DB_HOST ),
	escapeshellarg( DB_USER ),
	escapeshellarg( DB_PASSWORD )
) );

shell_exec( sprintf(
	"/usr/bin/env mysqladmin create %s --host=%s --user=%s --password=%s 2>&1",
	escapeshellarg( DB_NAME ),
	escapeshellarg( DB_HOST ),
	escapeshellarg( DB_USER ),
	escapeshellarg( DB_PASSWORD )
) );

system( sprintf(
	'/usr/bin/env php %s %s run_ms_tests run_core_tests',
	escapeshellarg( $test_install. '/includes/install.php' ),
	escapeshellarg( $config_file_path )
) );
