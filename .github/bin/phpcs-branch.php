#!/usr/bin/php
<?php
/**
 * Report on phpcs violations that are introduced in the current branch (not production).
 * Runs `phpcs` as normal on new files, and `phpcs-changed` on modified files. `phpcs-changed` will only report
 * on changed lines in each modified file.
 *
 * How to use: php .github/bin/phpcs-branch.php
 */

// phpcs:ignoreFile
namespace WordCamp\Bin\PHPCS_Changed;

function run_phpcs( $file, $bin_dir ) {
	exec( "$bin_dir/phpcs $file -q", $output, $exec_exit_status );
	echo implode( "\n", $output );
}

function run_phpcs_changed( $file, $git, $bin_dir ) {
	$name = basename( $file );
	exec( "$git diff production HEAD $file > $name.diff" );
	exec( "$git show HEAD:$file | $bin_dir/phpcs --standard=./phpcs.xml.dist --report=json -nq > $name.orig.phpcs" );
	exec( "cat $file | $bin_dir/phpcs --standard=./phpcs.xml.dist --report=json -nq > $name.phpcs" );
	
	$cmd = "$bin_dir/phpcs-changed --diff $name.diff --phpcs-orig $name.orig.phpcs --phpcs-new $name.phpcs";
	exec( $cmd, $output, $exec_exit_status );
	echo implode( "\n", $output );
	echo "\n";

	exec( "rm $name.diff $name.orig.phpcs $name.phpcs" );
}

function main() {
	$git_dir = dirname( dirname( __DIR__ ) );
	$bin_dir = dirname( dirname( __DIR__ ) ) . '/public_html/wp-content/mu-plugins/vendor/bin';
	$git     = "git -C $git_dir";

	try {
		echo "\nScanning changed files...\n";

		$affected_files = shell_exec( "$git diff production...HEAD --name-status --diff-filter=AM 2>&1 | grep .php$" );
		$affected_files = explode( "\n", trim( $affected_files ) );

		foreach ( $affected_files as $record ) {
			list( $change, $file ) = explode( "\t", trim( $record ) );

			switch ( $change ) {
				case 'M':
					run_phpcs_changed( $file, $git, $bin_dir );
					break;

				case 'A':
					run_phpcs( $file, $bin_dir );
					break;
			}
		}
		$status = 0;

	} catch ( Exception $exception ) {
		echo "\nAborting because of error: {$exception->getMessage()} \n";
		$status = 1;

	}

	exit( $status );
}

main();
