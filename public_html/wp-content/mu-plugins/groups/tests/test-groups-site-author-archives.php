<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Author archives on group sites.
 *
 * Core serves an author archive for any member of the site, posts or not, so
 * `?author=<id>` named every member of a group to logged-out visitors (#2304).
 *
 * @group groups
 */
class Test_Groups_Site_Author_Archives extends Groups_TestCase {

	const THEME_DIR = SUT_WP_CONTENT_DIR . 'themes/groups-site/';

	/**
	 * Load the theme's functions; `groups-site` isn't the active theme here.
	 *
	 * @param \WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );

		require_once self::THEME_DIR . 'functions.php';
	}

	/**
	 * A member with no posts: the case Core keeps alive and this closes.
	 */
	public function test_author_archive_of_member_without_posts_is_404() {
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->go_to( home_url( '/?author=' . $member_id ) );
		$this->assertTrue( is_author(), 'Core should resolve the archive before the theme steps in.' );

		\WordCamp\Groups\Site\disable_author_archives();

		$this->assertTrue( is_404() );
		$this->assertFalse( is_author() );
	}

	/**
	 * An organizer with published posts gets the same 404, so the response
	 * can't tell a member from a stranger either.
	 */
	public function test_author_archive_of_member_with_posts_is_404() {
		$organizer_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->post->create( array( 'post_author' => $organizer_id ) );

		$this->go_to( home_url( '/?author=' . $organizer_id ) );
		\WordCamp\Groups\Site\disable_author_archives();

		$this->assertTrue( is_404() );
	}

	/**
	 * Other views are left alone.
	 */
	public function test_non_author_views_are_untouched() {
		$post_id = self::factory()->post->create();

		$this->go_to( home_url( '/?p=' . $post_id ) );
		\WordCamp\Groups\Site\disable_author_archives();

		$this->assertFalse( is_404() );
		$this->assertTrue( is_single() );
	}

	/**
	 * It has to run before `redirect_canonical()`, or `?author=<id>` could
	 * be redirected to a URL carrying the member's slug first. Read from the
	 * theme file itself: the test case's hook snapshot drops what the theme
	 * added on load from the second test on.
	 */
	public function test_runs_before_redirect_canonical() {
		$functions = file_get_contents( self::THEME_DIR . 'functions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		$this->assertMatchesRegularExpression(
			"/add_action\\( 'template_redirect', __NAMESPACE__ \\. '\\\\disable_author_archives', 0 \\);/",
			$functions
		);
		$this->assertSame( 10, has_action( 'template_redirect', 'redirect_canonical' ) );
	}
}
