<?php

namespace WordCamp\Coming_Soon_Page\Tests;

use WordCamp_Coming_Soon_Page;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group coming-soon-page
 *
 * @covers WordCamp_Coming_Soon_Page::disable_core_sitemaps
 * @covers WordCamp_Coming_Soon_Page::disable_jetpack_sitemaps
 */
class Test_Sitemap_Lockdown extends WP_UnitTestCase {
	/**
	 * Start each test anonymous, the way a public sitemap request arrives.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 );
	}

	/**
	 * Build the plugin with Coming Soon in the given state.
	 *
	 * The constructor is what registers both sitemap filters, so it has to run. The test case
	 * backs the hooks up in `set_up()` and restores them in `tear_down()`, so the registrations
	 * do not leak between tests.
	 *
	 * @param string $enabled `on` or `off`.
	 * @return WordCamp_Coming_Soon_Page
	 */
	protected function plugin_with( $enabled ) {
		update_option( 'wccsp_settings', array( 'enabled' => $enabled ) );

		return new WordCamp_Coming_Soon_Page();
	}

	/**
	 * Ask Core the same question it asks itself when deciding whether to serve a sitemap.
	 *
	 * @return bool
	 */
	protected function core_sitemaps_enabled() {
		return wp_sitemaps_get_server()->sitemaps_enabled();
	}

	/**
	 * Both filters are registered during construction.
	 *
	 * They cannot move to `init()`, because Core settles `wp_sitemaps_enabled` on `init` at
	 * priority 10 and Jetpack loads its modules on `after_setup_theme`, both ahead of this
	 * plugin's own `init()` at priority 11.
	 */
	public function test_filters_are_registered_on_construction() {
		$plugin = $this->plugin_with( 'on' );

		$this->assertSame( 10, has_filter( 'wp_sitemaps_enabled', array( $plugin, 'disable_core_sitemaps' ) ) );
		$this->assertSame( 10, has_filter( 'jetpack_active_modules', array( $plugin, 'disable_jetpack_sitemaps' ) ) );
	}

	/**
	 * An anonymous visitor gets no sitemap while Coming Soon is on.
	 */
	public function test_core_sitemaps_are_disabled_for_anonymous_visitors() {
		$this->plugin_with( 'on' );

		$this->assertFalse( $this->core_sitemaps_enabled() );
	}

	/**
	 * Once the camp launches, Core serves sitemaps again.
	 */
	public function test_core_sitemaps_are_enabled_on_a_launched_site() {
		$this->plugin_with( 'off' );

		$this->assertTrue( $this->core_sitemaps_enabled() );
	}

	/**
	 * Organizers keep their sitemap, matching the page and REST locks, which both let anyone
	 * who can edit posts through.
	 */
	public function test_core_sitemaps_are_left_alone_for_editors() {
		$this->plugin_with( 'on' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertTrue( $this->core_sitemaps_enabled() );
	}

	/**
	 * A site that already had sitemaps off stays off. The filter only ever refuses, it never
	 * turns something on that Core had decided against.
	 */
	public function test_core_sitemaps_stay_disabled_when_something_else_disabled_them() {
		$plugin = $this->plugin_with( 'off' );

		$this->assertFalse( $plugin->disable_core_sitemaps( false ) );
	}

	/**
	 * Jetpack's module is switched off while Coming Soon is on, and the other modules are
	 * left untouched.
	 */
	public function test_jetpack_sitemaps_module_is_removed() {
		$plugin  = $this->plugin_with( 'on' );
		$modules = $plugin->disable_jetpack_sitemaps( array( 'contact-form', 'sitemaps', 'shortcodes' ) );

		$this->assertSame( array( 'contact-form', 'shortcodes' ), $modules );
	}

	/**
	 * Once the camp launches, the module is left in place.
	 */
	public function test_jetpack_sitemaps_module_is_kept_on_a_launched_site() {
		$plugin  = $this->plugin_with( 'off' );
		$modules = $plugin->disable_jetpack_sitemaps( array( 'contact-form', 'sitemaps', 'shortcodes' ) );

		$this->assertSame( array( 'contact-form', 'sitemaps', 'shortcodes' ), $modules );
	}

	/**
	 * Sites that never had the module active are passed through untouched, rather than being
	 * handed a reindexed copy of their own list.
	 */
	public function test_jetpack_module_list_without_sitemaps_is_unchanged() {
		$plugin  = $this->plugin_with( 'on' );
		$modules = $plugin->disable_jetpack_sitemaps( array( 'contact-form', 'shortcodes' ) );

		$this->assertSame( array( 'contact-form', 'shortcodes' ), $modules );
	}

	/**
	 * Unlike the Core lock, the Jetpack one deliberately ignores capabilities: it runs on
	 * `after_setup_theme` at priority -2, too early to resolve the current user safely.
	 */
	public function test_jetpack_sitemaps_module_is_removed_even_for_editors() {
		$plugin = $this->plugin_with( 'on' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( array(), $plugin->disable_jetpack_sitemaps( array( 'sitemaps' ) ) );
	}
}
