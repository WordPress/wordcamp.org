<?php

namespace WordCamp\Coming_Soon_Page\Tests;

use WordCamp_Coming_Soon_Page;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group coming-soon-page
 *
 * @covers WordCamp_Coming_Soon_Page::disable_jetpack_instant_search
 */
class Test_Instant_Search extends WP_UnitTestCase {
	/**
	 * The footer callback Jetpack Search registers for its widget area.
	 */
	const SIDEBAR_CALLBACK = array( 'Automattic\Jetpack\Search\Helper', 'print_instant_search_sidebar' );

	/**
	 * Register Instant Search's script and footer output the way Jetpack does, then evaluate the
	 * plugin's template override for a logged-out visitor.
	 *
	 * @param string $enabled Whether the Coming Soon page is `on` or `off`.
	 *
	 * @return WordCamp_Coming_Soon_Page
	 */
	protected function set_up_visitor_with_instant_search( $enabled ) {
		update_option( 'wccsp_settings', array( 'enabled' => $enabled ) );
		wp_set_current_user( 0 );

		wp_register_script( 'jetpack-instant-search', 'https://example.org/jp-search.js', array(), '1', true );
		wp_enqueue_script( 'jetpack-instant-search' );
		add_action( 'wp_footer', self::SIDEBAR_CALLBACK );

		$plugin = new WordCamp_Coming_Soon_Page();
		$plugin->init();

		return $plugin;
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		wp_dequeue_script( 'jetpack-instant-search' );
		wp_deregister_script( 'jetpack-instant-search' );
		remove_action( 'wp_footer', self::SIDEBAR_CALLBACK );
		delete_option( 'wccsp_settings' );

		parent::tear_down();
	}

	/**
	 * When the Coming Soon page is shown, Instant Search's script and footer widget area are dropped.
	 */
	public function test_instant_search_is_removed_when_coming_soon_is_shown() {
		$plugin = $this->set_up_visitor_with_instant_search( 'on' );

		$this->assertTrue( wp_script_is( 'jetpack-instant-search', 'enqueued' ) );
		$this->assertNotFalse( has_action( 'wp_footer', self::SIDEBAR_CALLBACK ) );

		$plugin->disable_jetpack_instant_search();

		$this->assertFalse( wp_script_is( 'jetpack-instant-search', 'enqueued' ) );
		$this->assertFalse( has_action( 'wp_footer', self::SIDEBAR_CALLBACK ) );
	}

	/**
	 * When the Coming Soon page is off, the theme renders and Instant Search is left alone.
	 */
	public function test_instant_search_is_kept_when_coming_soon_is_off() {
		$plugin = $this->set_up_visitor_with_instant_search( 'off' );

		$plugin->disable_jetpack_instant_search();

		$this->assertTrue( wp_script_is( 'jetpack-instant-search', 'enqueued' ) );
		$this->assertNotFalse( has_action( 'wp_footer', self::SIDEBAR_CALLBACK ) );
	}

	/**
	 * The removal runs after Jetpack Search enqueues at the default priority.
	 */
	public function test_removal_is_hooked_after_jetpack_enqueues() {
		$plugin = new WordCamp_Coming_Soon_Page();

		$this->assertSame( 99, has_action( 'wp_enqueue_scripts', array( $plugin, 'disable_jetpack_instant_search' ) ) );
	}
}
