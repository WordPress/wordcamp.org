<?php

namespace WordCamp\SEO\Tests;
use WP_UnitTestCase, WP_UnitTest_Factory;
use function WordCamp\SEO\get_latest_home_url;

defined( 'WPINC' ) || die();

/**
 * @group wordcamp-mu-plugins
 */
class Test_WordCamp_SEO extends WP_UnitTestCase {
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
	 * @covers ::get_latest_home_url
	 *
	 * @dataProvider data_get_latest_home_url
	 */
	public function test_get_latest_home_url( $current_domain, $current_path, $expected ) {
		$actual = get_latest_home_url( $current_domain, $current_path );

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Test cases for test_get_latest_home_url().
	 *
	 * @return array
	 */
	public function data_get_latest_home_url() {
		return array(
			'invalid' => array(
				'',
				'',
				false,
			),

			'year.city past year should return the newest year' => array(
				'2018.seattle.wordcamp.test',
				'/',
				'http://2019.seattle.wordcamp.test/',
			),
			'year.city newest year should return itself' => array(
				'2019.seattle.wordcamp.test',
				'/',
				'http://2019.seattle.wordcamp.test/',
			),

			'city/year past year should return the newest year' => array(
				'vancouver.wordcamp.test',
				'/2016/',
				'http://vancouver.wordcamp.test/2020/',
			),

			'city/year past year with `-foo` variant should return the newest year' => array(
				'vancouver.wordcamp.test',
				'/2018-developers/',
				'http://vancouver.wordcamp.test/2020/',
			),

			'city/year newest year should return itself' => array(
				'vancouver.wordcamp.test',
				'/2020/',
				'http://vancouver.wordcamp.test/2020/',
			),
		);
	}
}
