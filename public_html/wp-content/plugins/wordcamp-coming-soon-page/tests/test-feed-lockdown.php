<?php

namespace WordCamp\Coming_Soon_Page\Tests;

use WordCamp_Coming_Soon_Page;
use WP_UnitTestCase;
use WPDieException;

defined( 'WPINC' ) || die();

/**
 * @group coming-soon-page
 *
 * @covers WordCamp_Coming_Soon_Page::disable_feeds
 */
class Test_Feed_Lockdown extends WP_UnitTestCase {
	/**
	 * Start each test anonymous, the way a public feed request arrives.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 );
	}

	/**
	 * Build the plugin with Coming Soon in the given state and its state initialized.
	 *
	 * @param string $enabled `on` or `off`.
	 * @return WordCamp_Coming_Soon_Page
	 */
	protected function plugin_with_coming_soon( $enabled ) {
		update_option( 'wccsp_settings', array( 'enabled' => $enabled ) );

		$plugin = new WordCamp_Coming_Soon_Page();
		$plugin->init();

		return $plugin;
	}

	/**
	 * A feed request is refused while Coming Soon is active.
	 */
	public function test_feed_request_is_refused() {
		$plugin = $this->plugin_with_coming_soon( 'on' );

		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed(), 'The request should be a feed request.' );

		// `wp_die()` in a feed request routes through the XML handler; point it at the test
		// case's throwing handler so the refusal surfaces as an exception we can assert on.
		add_filter( 'wp_die_xml_handler', array( $this, 'get_wp_die_handler' ) );

		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'Feeds are not available while the site is in Coming Soon mode.' );

		$plugin->disable_feeds();
	}

	/**
	 * The Coming Soon page stops advertising the now-blocked feeds.
	 */
	public function test_feed_discovery_links_are_removed() {
		$plugin = $this->plugin_with_coming_soon( 'on' );

		$this->go_to( '/' );
		$plugin->disable_feeds();

		$this->assertFalse( has_action( 'wp_head', 'feed_links' ) );
		$this->assertFalse( has_action( 'wp_head', 'feed_links_extra' ) );
	}

	/**
	 * With the site launched, a feed request is left alone and the discovery links stay.
	 */
	public function test_launched_site_feed_is_not_refused() {
		$plugin = $this->plugin_with_coming_soon( 'off' );

		$this->go_to( '/?feed=rss2' );
		$plugin->disable_feeds();

		$this->assertSame( 2, has_action( 'wp_head', 'feed_links' ) );
		$this->assertSame( 3, has_action( 'wp_head', 'feed_links_extra' ) );
	}

	/**
	 * A logged-in editor keeps feed access while Coming Soon is active.
	 *
	 * The gate matches the page and REST locks: it applies to anonymous visitors, not to the
	 * organizers working on the site. Set the editor before init() so `override_theme_template`
	 * is computed for a user who can `edit_posts`.
	 */
	public function test_logged_in_editor_feed_is_not_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$plugin = $this->plugin_with_coming_soon( 'on' );

		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed(), 'The request should be a feed request.' );

		// Reaching the assertions at all proves the request was not refused: a refusal would
		// `wp_die()` and throw before we get here. The discovery links are left in place too.
		$plugin->disable_feeds();

		$this->assertSame( 2, has_action( 'wp_head', 'feed_links' ) );
		$this->assertSame( 3, has_action( 'wp_head', 'feed_links_extra' ) );
	}
}
