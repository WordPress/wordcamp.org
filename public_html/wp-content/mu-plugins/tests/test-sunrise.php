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
	get_canonical_year_url, guess_requested_domain_path,
	get_city_slash_year_url, site_redirects, unsubdomactories_redirects,
};

defined( 'WPINC' ) || die();

/**
 * @group sunrise
 */
class Test_Sunrise extends WP_UnitTestCase {
	protected static $network_id;
	protected static $year_dot_2018_site_id;
	protected static $year_dot_2019_site_id;
	protected static $slash_year_2016_site_id;
	protected static $slash_year_2018_dev_site_id;
	protected static $slash_year_2020_site_id;

	/**
	 * Create sites we'll need for the tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$network_id = $factory->network->create( array(
			'domain' => 'wordcamp.test',
			'path'   => '/',
		) );

		self::$year_dot_2018_site_id = $factory->blog->create( array(
			'domain'     => '2018.seattle.wordcamp.test',
			'path'       => '/',
			'network_id' => self::$network_id,
		) );

		self::$year_dot_2019_site_id = $factory->blog->create( array(
			'domain'     => '2019.seattle.wordcamp.test',
			'path'       => '/',
			'network_id' => self::$network_id,
		) );

		self::$slash_year_2016_site_id = $factory->blog->create( array(
			'domain'     => 'vancouver.wordcamp.test',
			'path'       => '/2016/',
			'network_id' => self::$network_id,
		) );

		self::$slash_year_2018_dev_site_id = $factory->blog->create( array(
			'domain'     => 'vancouver.wordcamp.test',
			'path'       => '/2018-developers/',
			'network_id' => self::$network_id,
		) );

		self::$slash_year_2020_site_id = $factory->blog->create( array(
			'domain'     => 'vancouver.wordcamp.test',
			'path'       => '/2020/',
			'network_id' => self::$network_id,
		) );
	}

	/**
	 * Revert the persistent changes from `wpSetUpBeforeClass()` that won't be automatically cleaned up.
	 */
	public static function wpTearDownAfterClass() {
		global $wpdb;

		wp_delete_site( self::$year_dot_2018_site_id );
		wp_delete_site( self::$year_dot_2019_site_id );
		wp_delete_site( self::$slash_year_2016_site_id );
		wp_delete_site( self::$slash_year_2018_dev_site_id );
		wp_delete_site( self::$slash_year_2020_site_id );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d", self::$network_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->site}     WHERE id      = %d", self::$network_id ) );
	}

	/**
	 * @covers ::get_city_slash_year_url
	 *
	 * @dataProvider data_get_city_slash_year_url
	 */
	public function test_get_city_slash_year_url( $domain, $request_uri, $expected ) {
		$actual = get_city_slash_year_url( $domain, $request_uri );

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Test cases for test_get_city_slash_year_url().
	 *
	 * @return array
	 */
	public function data_get_city_slash_year_url() {
		return array(
			/*
			 * Should not redirect.
			 */
			'root site homepage should not redirect' => array(
				'wordcamp.test',
				'/',
				false,
			),

			'root site subpage should not redirect' => array(
				'wordcamp.test',
				'/schedule/',
				false,
			),

			'central homepage should not redirect' => array(
				'central.wordcamp.test',
				'/',
				false,
			),

			'central subpage should not redirect' => array(
				'central.wordcamp.test',
				'/schedule/',
				false,
			),

			'city missing from `$redirect_cities` should not redirect' => array(
				'2018.narnia.wordcamp.test',
				'/',
				false,
			),

			'already a city/year format homepage should not redirect' => array(
				'vancouver.wordcamp.test',
				'/2020/',
				false,
			),

			'already a city/year format subpage should not redirect' => array(
				'vancouver.wordcamp.test',
				'/2020/schedule/',
				false,
			),

			'already a city/year format year archive should not redirect' => array(
				'vancouver.wordcamp.test',
				'/2020/2020/',
				false,
			),


			/*
			 * Should redirect.
			 */
			'city.year homepage should redirect' => array(
				'2020.testing.wordcamp.test',
				'/',
				'https://testing.wordcamp.test/2020/',
			),

			'city.year subpage should redirect' => array(
				'2020.testing.wordcamp.test',
				'/schedule/',
				'https://testing.wordcamp.test/2020/schedule/',
			),

			'city.year year archive should redirect' => array(
				'2020.testing.wordcamp.test',
				'/2020/',
				'https://testing.wordcamp.test/2020/2020/',
			),

			'city.year extra ID homepage should redirect' => array(
				'2019-designers.testing.wordcamp.test',
				'/',
				'https://testing.wordcamp.test/2019-designers/',
			),

			'city.year extra ID subpage should redirect' => array(
				'2019-designers.testing.wordcamp.test',
				'/schedule/',
				'https://testing.wordcamp.test/2019-designers/schedule/',
			),

			'city.year extra ID year archive should redirect' => array(
				'2019-designers.testing.wordcamp.test',
				'/2019/',
				'https://testing.wordcamp.test/2019-designers/2019/',
			),
		);
	}

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
	 * @covers ::unsubdomactories_redirects
	 *
	 * @dataProvider data_unsubdomactories_redirects
	 */
	public function test_unsubdomactories_redirects( $domain, $request_uri, $expected ) {
		$actual = unsubdomactories_redirects( $domain, $request_uri );

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Test cases for test_unsubdomactories_redirects().
	 *
	 * @return array
	 */
	public function data_unsubdomactories_redirects() {
		return array(
			'request without year should not redirect' => array(
				'central.wordcamp.test',
				'/',
				false,
			),

			'city missing from `$redirect_cities` should not redirect' => array(
				'fortaleza.wordcamp.test',
				'/2016/',
				false,
			),

			'year.city homepage request should not redirect' => array(
				'2020.vancouver.wordcamp.test',
				'/',
				false,
			),

			'year.city subpage request should not redirect' => array(
				'2020.vancouver.wordcamp.test',
				'/schedule/',
				false,
			),

			'city/year homepage request should redirect' => array(
				'vancouver.wordcamp.test',
				'/2020/',
				'https://2020.vancouver.wordcamp.test/'
			),

			'city/year subpage request should redirect' => array(
				'vancouver.wordcamp.test',
				'/2020/schedule/',
				'https://2020.vancouver.wordcamp.test/schedule/'
			),
		);
	}

	/**
	 * @covers ::get_canonical_year_url
	 *
	 * @dataProvider data_get_canonical_year_url
	 */
	public function test_get_canonical_year_url( $domain, $path, $expected ) {
		$actual = get_canonical_year_url( $domain, $path );

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Test cases for test_get_canonical_year_url().
	 *
	 * @return array
	 */
	public function data_get_canonical_year_url() {
		return array(
			'dont redirect root site' => array(
				'wordcamp.test',
				'/',
				false
			),

			'dont redirect non-existent site' => array(
				'narnia.wordcamp.test',
				'/',
				false
			),

			'dont redirect year.city sites' => array(
				'2018.seattle.wordcamp.test',
				'/',
				false
			),

			'dont redirect city/year sites' => array(
				'vancouver.wordcamp.test',
				'/2020/',
				false
			),

			'redirect year.city root to latest camp' => array(
				'seattle.wordcamp.test',
				'/',
				'https://2019.seattle.wordcamp.test/'
			),

			'redirect city/year root to latest camp' => array(
				'vancouver.wordcamp.test',
				'/',
				'https://vancouver.wordcamp.test/2020/'
			),
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
