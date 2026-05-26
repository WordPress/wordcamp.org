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
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Necessary for testing multisite global state.
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
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring original state.
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
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Necessary for testing multisite global state.
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
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring original state.
		$current_blog = $original_blog;
	}

	/**
	 * @covers WordCamp\Latest_Site_Hints\get_latest_home_url
	 * @covers WordCamp\Latest_Site_Hints\get_current_edition
	 *
	 * While a real edition is current, the banner should skip a newer, not-yet-scheduled placeholder site.
	 *
	 * Uses a dedicated city so the per-request static cache in `get_latest_home_url()` can't collide with
	 * the other cases (which reuse the shared fixture domains).
	 */
	public function test_skips_unscheduled_placeholder_site() {
		$current     = self::factory()->blog->create( array(
			'domain'     => 'skiptest.wordcamp.test',
			'path'       => '/2025/',
			'network_id' => WORDCAMP_NETWORK_ID,
		) );
		$placeholder = self::factory()->blog->create( array(
			'domain'     => 'skiptest.wordcamp.test',
			'path'       => '/2026/',
			'network_id' => WORDCAMP_NETWORK_ID,
		) );

		// 2025 is upcoming / only just finished; 2026 is an empty placeholder with no schedule meta.
		update_site_meta( $current, '_wc_event_end', strtotime( '+2 weeks' ) );

		$this->assertSame(
			'http://skiptest.wordcamp.test/2025/',
			get_latest_home_url( 'skiptest.wordcamp.test', '/2025/' )
		);

		wp_delete_site( $current );
		wp_delete_site( $placeholder );
	}

	/**
	 * @covers WordCamp\Latest_Site_Hints\get_latest_home_url
	 * @covers WordCamp\Latest_Site_Hints\get_current_edition
	 *
	 * Once the latest real edition is well in the past, the banner should fall through to the newest site,
	 * even if that's an unscheduled placeholder.
	 */
	public function test_falls_through_to_newest_when_edition_long_over() {
		$old         = self::factory()->blog->create( array(
			'domain'     => 'overtest.wordcamp.test',
			'path'       => '/2020/',
			'network_id' => WORDCAMP_NETWORK_ID,
		) );
		$placeholder = self::factory()->blog->create( array(
			'domain'     => 'overtest.wordcamp.test',
			'path'       => '/2099/',
			'network_id' => WORDCAMP_NETWORK_ID,
		) );

		// 2020 finished well over a month ago; 2099 is still an unscheduled placeholder.
		update_site_meta( $old, '_wc_event_end', strtotime( '2020-08-01' ) );

		$this->assertSame(
			'http://overtest.wordcamp.test/2099/',
			get_latest_home_url( 'overtest.wordcamp.test', '/2020/' )
		);

		wp_delete_site( $old );
		wp_delete_site( $placeholder );
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
