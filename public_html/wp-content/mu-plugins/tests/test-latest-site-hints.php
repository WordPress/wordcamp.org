<?php

namespace WordCamp\Latest_Site_Hints\Tests;
use WP_UnitTest_Factory;
use WordCamp\Tests\Database_TestCase;

use function WordCamp\Latest_Site_Hints\{ get_latest_home_url, maybe_add_latest_site_hints };

defined( 'WPINC' ) || die();

/**
 * @group mu-plugins
 * @group latest-site-hints
 */
class Test_WordCamp_SEO extends Database_TestCase {
	/**
	 * Create sites we'll need for the tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );
	}

	/**
	 * Revert the persistent changes from `wpSetUpBeforeClass()` that won't be automatically cleaned up.
	 */
	public static function wpTearDownAfterClass() {
		parent::wpTearDownAfterClass();
	}

	/**
	 * @covers WordCamp\Latest_Site_Hints\get_latest_home_url
	 *
	 * @dataProvider data_get_latest_home_url
	 */
	public function test_get_latest_home_url( $current_domain, $current_path, $expected ) {
		$actual = get_latest_home_url( $current_domain, $current_path );

		$this->assertSame( $expected, $actual );
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

			"there isn't a newer site for the root WordCamp site" => array(
				'wordcamp.test',
				'/',
				false,
			),

			"there isn't a newer site for the root Events site" => array(
				'wordcamp.test',
				'/',
				false,
			),

			"there isn't a newer site for non-event sites" => array(
				'central.wordcamp.test',
				'/',
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

			'nextgen event should return latest' => array(
				'events.wordpress.test',
				'/rome/2023/training/',
				'http://events.wordpress.test/rome/2024/training/',
			),
		);
	}


	/**
	 * @covers WordCamp\Latest_Site_Hints\maybe_add_latest_site_hints
	 *
	 * @dataProvider data_maybe_add_latest_site_hints
	 */
	public function test_maybe_add_latest_site_hints( $domain, $path, $expected ) {

		// Sanity Check
		$this->assertFalse( has_filter( 'wp_head', 'WordCamp\Latest_Site_Hints\add_notification_styles' ) );

		// Switch to blog
		$site = get_site_by_path( $domain, $path );
		$this->assertNotFalse( $site );
		switch_to_blog( $site->ID );

		// Initialize.
		maybe_add_latest_site_hints();

		// Verify expected.
		$actual = has_filter( 'wp_head', 'WordCamp\Latest_Site_Hints\add_notification_styles' );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for test_get_latest_home_url().
	 *
	 * @return array
	 */
	public function data_maybe_add_latest_site_hints() {
		return array(
			'city/year newest year should return itself' => array(
				'vancouver.wordcamp.test',
				'/2020/',
				false,
			),

			'city/year newest year with identifier `-foo` should return itself' => array(
				'vancouver.wordcamp.test',
				'/2020-developers/',
				false,
			),

			'city/year old year should return newest' => array(
				'vancouver.wordcamp.test',
				'/2018/',
				true,
			),

			'city/year old year should return newest' => array(
				'vancouver.wordcamp.test',
				'/2018-developers/',
				true,
			),
		);
	}

}
