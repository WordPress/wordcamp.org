<?php

namespace WordCamp\Latest_Site_Hints\Tests;
use WP_UnitTest_Factory;
use WordCamp\Tests\Database_TestCase;

use function WordCamp\Latest_Site_Hints\get_latest_home_url;
use function WordCamp\Latest_Site_Hints\maybe_add_latest_site_hints;

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
	 * @covers WordCamp\Latest_Site_Hints\maybe_add_latest_site_hints
	 *
	 * Verify that comments and pings are closed on past WordCamp sites.
	 */
	public function test_comments_closed_on_past_site() {
		global $current_blog;

		// Save original state.
		$original_blog = $current_blog;

		// Set current blog to a past site (2018 seattle has a newer 2019 site).
		$current_blog = get_site( self::$year_dot_2018_site_id );
		switch_to_blog( self::$year_dot_2018_site_id );

		// Remove any previously added filters to start clean.
		remove_filter( 'comments_open', '__return_false' );
		remove_filter( 'pings_open', '__return_false' );

		maybe_add_latest_site_hints();

		$this->assertNotFalse( has_filter( 'comments_open', '__return_false' ), 'comments_open filter should be registered on past sites.' );
		$this->assertNotFalse( has_filter( 'pings_open', '__return_false' ), 'pings_open filter should be registered on past sites.' );

		// Clean up.
		remove_filter( 'comments_open', '__return_false' );
		remove_filter( 'pings_open', '__return_false' );
		restore_current_blog();
		$current_blog = $original_blog;
	}

	/**
	 * @covers WordCamp\Latest_Site_Hints\maybe_add_latest_site_hints
	 *
	 * Verify that comments and pings remain open on the latest WordCamp site.
	 */
	public function test_comments_open_on_latest_site() {
		global $current_blog;

		// Save original state.
		$original_blog = $current_blog;

		// Set current blog to the latest site (2019 seattle is the newest).
		$current_blog = get_site( self::$year_dot_2019_site_id );
		switch_to_blog( self::$year_dot_2019_site_id );

		// Remove any previously added filters to start clean.
		remove_filter( 'comments_open', '__return_false' );
		remove_filter( 'pings_open', '__return_false' );

		maybe_add_latest_site_hints();

		$this->assertFalse( has_filter( 'comments_open', '__return_false' ), 'comments_open filter should not be registered on the latest site.' );
		$this->assertFalse( has_filter( 'pings_open', '__return_false' ), 'pings_open filter should not be registered on the latest site.' );

		// Clean up.
		restore_current_blog();
		$current_blog = $original_blog;
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
}
