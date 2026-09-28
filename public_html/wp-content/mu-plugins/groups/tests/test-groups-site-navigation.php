<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/../../wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * Coverage for the `groups-site` header's local navigation bar.
 *
 * The bar's items are hardcoded in the theme rather than provisioned as a nav
 * menu, so `add_local_navigation_menus()` is the whole definition of what a
 * visitor sees up there — and it is built per request, because two of the
 * three items depend on who is looking.
 *
 * @group groups
 */
class Test_Groups_Site_Navigation extends Groups_TestCase {

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
	 * Reset the current user between tests; the filter reads it.
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * The labels of the local-navigation items, in the order they render.
	 *
	 * @return string[]
	 */
	protected function get_labels(): array {
		$menus = \WordCamp\Groups\Site\add_local_navigation_menus( array() );

		return wp_list_pluck( $menus['local-navigation'], 'label' );
	}

	/**
	 * A visitor who isn't logged in is offered no "My events": the section it
	 * points at renders for members only, and these are the views served from
	 * the page cache, so the bar has to be the same for all of them.
	 */
	public function test_logged_out_visitor_gets_no_my_events() {
		$this->assertSame( array( 'All Events', 'Log in' ), $this->get_labels() );
	}

	/**
	 * A logged-in visitor who hasn't joined this group is in the same
	 * position: an account alone gives the block nothing to list.
	 */
	public function test_non_member_gets_no_my_events() {
		$user_id = self::factory()->user->create();

		// The factory joins new users to the current blog, which is the
		// opposite of what this test is about.
		remove_user_from_blog( $user_id, get_current_blog_id() );
		wp_set_current_user( $user_id );

		$this->assertSame( array( 'All Events', 'Log out' ), $this->get_labels() );
	}

	/**
	 * A member gets the link, between "All Events" and the account item.
	 */
	public function test_member_gets_my_events() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
		wp_set_current_user( $user_id );

		$this->assertSame( array( 'All Events', 'My events', 'Log out' ), $this->get_labels() );
	}

	/**
	 * The link is an anchor into the group's front page, where the
	 * `wporg/my-events` section renders under that same id.
	 */
	public function test_my_events_links_to_the_front_page_section() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
		wp_set_current_user( $user_id );

		$menus = \WordCamp\Groups\Site\add_local_navigation_menus( array() );
		$item  = $menus['local-navigation'][1];

		$this->assertSame( 'My events', $item['label'] );
		$this->assertSame( home_url( '/#my-events' ), $item['url'] );
	}

	/**
	 * Menus for other slugs are left alone.
	 */
	public function test_other_menus_are_preserved() {
		$menus = \WordCamp\Groups\Site\add_local_navigation_menus(
			array(
				'global-navigation' => array(
					array(
						'label' => 'Kept', 'url' => 'https://example.org/',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'global-navigation', $menus );
		$this->assertSame( 'Kept', $menus['global-navigation'][0]['label'] );
	}
}
