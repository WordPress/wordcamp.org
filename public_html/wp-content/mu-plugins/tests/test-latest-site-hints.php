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
	 * @covers WordCamp\Latest_Site_Hints\close_discussion_on_past_sites
	 *
	 * Verify that comments/pings are closed and the banner hooks are registered on past WordCamp sites.
	 */
	public function test_comments_closed_on_past_site() {
		// 2018 seattle has a newer 2019 site.
		switch_to_blog( self::$year_dot_2018_site_id );
		$post_id = $this->create_open_post();

		// Closed without `maybe_add_latest_site_hints()` running, as for `wp-comments-post.php` and `xmlrpc.php`.
		$this->assertFalse( comments_open( $post_id ), 'Comments should be closed on past sites.' );
		$this->assertFalse( pings_open( $post_id ), 'Pings should be closed on past sites.' );

		maybe_add_latest_site_hints();

		// The banner prints in the normal flow at `wp_body_open`, with a `wp_footer` fallback. Locking
		// both hooks in guards against a future refactor silently dropping one of the two paths.
		$this->assertNotFalse( has_action( 'wp_body_open', 'WordCamp\Latest_Site_Hints\show_notification_in_flow' ), 'Banner should be hooked to wp_body_open on past sites.' );
		$this->assertNotFalse( has_action( 'wp_footer', 'WordCamp\Latest_Site_Hints\show_notification_overlay' ), 'Fallback banner should be hooked to wp_footer on past sites.' );

		// Clean up.
		remove_action( 'wp_head', 'WordCamp\Latest_Site_Hints\canonical_link_past_home_pages_to_current_year', 9 );
		remove_action( 'wp_head', 'WordCamp\Latest_Site_Hints\add_notification_styles' );
		remove_action( 'wp_body_open', 'WordCamp\Latest_Site_Hints\show_notification_in_flow' );
		remove_action( 'wp_footer', 'WordCamp\Latest_Site_Hints\show_notification_overlay' );
		restore_current_blog();
	}

	/**
	 * @covers WordCamp\Latest_Site_Hints\close_discussion_on_past_sites
	 *
	 * Verify that a comment sent straight to `wp-comments-post.php` is rejected on past WordCamp sites.
	 */
	public function test_comment_submission_rejected_on_past_site() {
		switch_to_blog( self::$year_dot_2018_site_id );
		$post_id = $this->create_open_post();

		$result = wp_handle_comment_submission( array(
			'comment_post_ID' => $post_id,
			'author'          => 'Commenter',
			'email'           => 'commenter@example.org',
			'comment'         => 'A comment on a past site.',
		) );

		$this->assertWPError( $result );
		$this->assertSame( 'comment_closed', $result->get_error_code() );

		restore_current_blog();
	}

	/**
	 * @covers WordCamp\Latest_Site_Hints\maybe_add_latest_site_hints
	 * @covers WordCamp\Latest_Site_Hints\close_discussion_on_past_sites
	 *
	 * Verify that comments and pings remain open on the latest WordCamp site.
	 */
	public function test_comments_open_on_latest_site() {
		// 2019 seattle is the newest.
		switch_to_blog( self::$year_dot_2019_site_id );
		$post_id = $this->create_open_post();

		maybe_add_latest_site_hints();

		// Pings aren't checked: other code may close them everywhere, and they share this callback with comments.
		$this->assertTrue( comments_open( $post_id ), 'Comments should stay open on the latest site.' );
		$this->assertFalse( has_action( 'wp_body_open', 'WordCamp\Latest_Site_Hints\show_notification_in_flow' ), 'Banner should not be hooked to wp_body_open on the latest site.' );
		$this->assertFalse( has_action( 'wp_footer', 'WordCamp\Latest_Site_Hints\show_notification_overlay' ), 'Fallback banner should not be hooked to wp_footer on the latest site.' );

		restore_current_blog();
	}

	/**
	 * @covers WordCamp\Latest_Site_Hints\close_discussion_on_past_sites
	 *
	 * Verify that the `latest-site-hint` skip flag keeps comments open on a past site.
	 */
	public function test_comments_open_on_past_site_with_skip_flag() {
		add_site_meta( self::$year_dot_2018_site_id, 'wordcamp_skip_feature', 'latest-site-hint' );

		switch_to_blog( self::$year_dot_2018_site_id );
		$post_id = $this->create_open_post();

		$this->assertTrue( comments_open( $post_id ), 'Comments should stay open when the feature is skipped.' );

		restore_current_blog();
	}

	/**
	 * Create a published post that accepts comments and pings.
	 *
	 * @return int
	 */
	protected function create_open_post() {
		return self::factory()->post->create( array(
			'post_status'    => 'publish',
			'comment_status' => 'open',
			'ping_status'    => 'open',
		) );
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
