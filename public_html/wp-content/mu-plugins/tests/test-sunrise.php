<?php

/*
 * This isn't technically an mu-plugin, but it's easier to just put this here than creating a whole new suite.
 *
 * @todo `sunrise.php` isn't watched by phpunit-watcher because it can only watch entire folders, and `wp-content`
 * is too big to monitor without significant performance impacts. You'll have to modify this file to automatically
 * re-run tests.
 *
 * See https://github.com/spatie/phpunit-watcher/issues/113
 */


namespace WordCamp\Sunrise\Tests;
use WP_UnitTestCase, WP_UnitTest_Factory;

use function WordCamp\Sunrise\{
	guess_requested_domain_path, site_redirects
};

defined( 'WPINC' ) || die();

/**
 * @group sunrise
 */
class Test_Sunrise extends WP_UnitTestCase {
	/**
	 * @covers ::guess_requested_domain_path
	 *
	 * @dataProvider data_guess_requested_domain_path
	 */
	public function test_guess_requested_domain_path( $site ) {
		list(
			'domain'     => $domain,
			'test-paths' => $test_paths,
			'expected'   => $expected,
		) = $site;

		$_SERVER['HTTP_HOST'] = $domain;

		foreach ( $test_paths as $path ) {
			$_SERVER['REQUEST_URI'] = $path;

			$actual = guess_requested_domain_path();

			$this->assertEquals( $expected, $actual );
		}
	}

	/**
	 * Test cases for test_guess_requested_domain_path().
	 *
	 * @return array
	 */
	public function data_guess_requested_domain_path() {
		return array(
			'root site' => array( array(
				'domain' => 'wordcamp.test',

				'test-paths' => array(
					'/',
					'/schedule/',
					'/2020/', // Year archive.
				),

				'expected' => array(
					'domain' => 'wordcamp.test',
					'path'   => '/',
				),
			) ),

			'central' => array( array(
				'domain' => 'central.wordcamp.test',

				'test-paths' => array(
					'/',
					'/schedule/',

					// This function isn't expected to distinguish the `/2020/` path as a year archive. See its phpdoc.
				),

				'expected' => array(
					'domain' => 'central.wordcamp.test',
					'path'   => '/',
				),
			) ),

			'year.city site' => array( array(
				'domain' => '2020.seattle.wordcamp.test',

				'test-paths' => array(
					'/',
					'/schedule/',
					'/2020/', // Year archive.
				),

				'expected' => array(
					'domain' => '2020.seattle.wordcamp.test',
					'path'   => '/',
				),
			) ),

			'city/year site' => array( array(
				'domain' => 'vancouver.wordcamp.test',

				'test-paths' => array(
					'/2020/',
					'/2020/schedule/',
					'/2020/2020/', // Year archive.
				),

				'expected' => array(
					'domain' => 'vancouver.wordcamp.test',
					'path'   => '/2020/',
				),
			) ),
		);
	}

	/**
	 * @covers ::site_redirects
	 * @covers ::get_domain_redirects
	 *
	 * @dataProvider data_site_redirects
	 */
	public function test_site_redirects( $domain, $path, $expected ) {
		$actual = site_redirects( $domain, $path );

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Test cases for test_site_redirects().
	 *
	 * @return array
	 */
	public function data_site_redirects() {
		return array(
			/*
			 * There aren't any cases to test that front-end requests to the root site redirect to Central,
			 * because it's difficult to mock `is_admin()` being `false` in that context.
			 * `set_current_screen( 'front' )` works for some cases, but not all. `$this->>go_to()` doesn't seem
			 * to help either.
			 */

			'root site cron requests are not redirected to Central' => array(
				'wordcamp.test',
				'/wp-cron.php',
				false,
			),

			'root site rest requests are not redirected to Central' => array(
				'wordcamp.test',
				'/wp-json',
				false,
			),

			'domain redirect to central removes request uri' => array(
				'bg.wordcamp.test',
				'/schedule/',
				'https://central.wordcamp.test',
			),

			'domain redirect elsewhere includes request uri' => array(
				'fr.2014.montreal.wordcamp.test',
				'/schedule/',
				'https://2014-fr.montreal.wordcamp.test/schedule/',
			),

			'external domain redirects to central' => array(
				'wordcampsf.org',
				'/schedule/',
				'https://sf.wordcamp.test/schedule/',
			),

			'unknown domain should not redirect' => array(
				'narnia.wordcamp.test',
				'/',
				false,
			),
		);
	}
}
