<?php

namespace WordCamp\Coming_Soon_Page\Tests;

use WordCamp_Coming_Soon_Page;
use WP_UnitTestCase;
use WP_UnitTest_Factory;

defined( 'WPINC' ) || die();

/**
 * @group coming-soon-page
 *
 * @covers WordCamp_Coming_Soon_Page::force_placeholder_document_title
 * @covers WordCamp_Coming_Soon_Page::disable_canonical_redirect
 * @covers WordCamp_Coming_Soon_Page::remove_identifying_body_classes
 */
class Test_Placeholder_Privacy extends WP_UnitTestCase {
	/**
	 * @var int
	 */
	protected static $organizer_id;

	/**
	 * @var WordCamp_Coming_Soon_Page
	 */
	protected static $plugin;

	/**
	 * Set up shared fixtures.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$organizer_id = $factory->post->create(
			array(
				'post_type'   => 'wcb_organizer',
				'post_status' => 'publish',
				'post_title'  => 'Secret Organizer Name',
				'post_name'   => 'secret-organizer-name',
			)
		);

		// The plugin's bootstrap global is not populated in the test suite, so
		// instantiate it once here (the class itself is loaded).
		self::$plugin = new WordCamp_Coming_Soon_Page();
	}

	/**
	 * Restore state after each test.
	 */
	public function tear_down(): void {
		$this->set_coming_soon( 'off' );
		wp_set_current_user( 0 );
		wp_reset_postdata();

		parent::tear_down();
	}

	/**
	 * Toggle Coming Soon and recompute the plugin's active state as an
	 * unauthenticated visitor.
	 *
	 * @param string $enabled 'on' or 'off'.
	 *
	 * @return WordCamp_Coming_Soon_Page The bootstrapped plugin instance.
	 */
	protected function set_coming_soon( $enabled ): WordCamp_Coming_Soon_Page {
		$settings            = get_option( 'wccsp_settings' );
		$settings            = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = $enabled;
		update_option( 'wccsp_settings', $settings );

		wp_set_current_user( 0 );

		self::$plugin->init();

		return self::$plugin;
	}

	/**
	 * While active, the document title is the site name, not the resolved post.
	 */
	public function test_document_title_is_site_name_while_active() {
		$plugin = $this->set_coming_soon( 'on' );

		$this->assertSame(
			get_bloginfo( 'name' ),
			$plugin->force_placeholder_document_title( 'Secret Organizer Name - WordCamp' )
		);
	}

	/**
	 * While inactive, the real document title passes through unchanged.
	 */
	public function test_document_title_untouched_while_inactive() {
		$plugin = $this->set_coming_soon( 'off' );

		$this->assertSame(
			'Real Document Title',
			$plugin->force_placeholder_document_title( 'Real Document Title' )
		);
	}

	/**
	 * While active, canonical redirects are cancelled so `?p=<id>` does not
	 * reveal the real permalink.
	 */
	public function test_canonical_redirect_cancelled_while_active() {
		$plugin = $this->set_coming_soon( 'on' );

		$this->assertFalse(
			$plugin->disable_canonical_redirect( 'https://example.org/organizer/secret-organizer-name/' )
		);
	}

	/**
	 * While inactive, canonical redirects behave normally.
	 */
	public function test_canonical_redirect_kept_while_inactive() {
		$plugin = $this->set_coming_soon( 'off' );

		$this->assertSame(
			'https://example.org/organizer/secret-organizer-name/',
			$plugin->disable_canonical_redirect( 'https://example.org/organizer/secret-organizer-name/' )
		);
	}

	/**
	 * While active, body classes that spell out the resolved post's slug are
	 * dropped, while generic classes stay.
	 */
	public function test_slug_body_classes_removed_while_active() {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		global $wp_query, $post;
		$original_query = $wp_query;
		$original_post  = $post;

		$wp_query = new \WP_Query(
			array(
				'p'         => self::$organizer_id,
				'post_type' => 'wcb_organizer',
			)
		);
		$wp_query->the_post();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		$plugin = $this->set_coming_soon( 'on' );

		$filtered = $plugin->remove_identifying_body_classes(
			array( 'single', 'postid-' . self::$organizer_id, 'wcb_organizer-slug-secret-organizer-name' )
		);

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();
		$wp_query = $original_query;
		$post     = $original_post;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertNotContains( 'wcb_organizer-slug-secret-organizer-name', $filtered );
		$this->assertContains( 'single', $filtered );
	}

	/**
	 * The title filter is wired up: `wp_get_document_title()` itself returns the
	 * site name while active, not just the callback in isolation.
	 */
	public function test_document_title_through_the_real_path() {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		global $wp_query, $post;
		$original_query = $wp_query;
		$original_post  = $post;

		$wp_query = new \WP_Query(
			array(
				'p'         => self::$organizer_id,
				'post_type' => 'wcb_organizer',
			)
		);
		$wp_query->the_post();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->set_coming_soon( 'on' );
		$title = wp_get_document_title();

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();
		$wp_query = $original_query;
		$post     = $original_post;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertSame( get_bloginfo( 'name' ), $title );
	}

	/**
	 * While active, the head tags, headers and slug redirects that name the
	 * real post are unhooked.
	 */
	public function test_identifying_links_are_unhooked_while_active() {
		$hooks = array(
			array( 'wp_head', 'rel_canonical' ),
			array( 'wp_head', 'wp_oembed_add_discovery_links' ),
			array( 'template_redirect', 'wp_old_slug_redirect' ),
		);

		// Normalise to exactly one registration each, so the assertions reflect
		// this plugin's removal rather than leftover state from another test.
		foreach ( $hooks as $hook ) {
			remove_action( $hook[0], $hook[1] );
			add_action( $hook[0], $hook[1] );
		}

		$this->set_coming_soon( 'on' );

		foreach ( $hooks as $hook ) {
			$this->assertFalse( has_action( $hook[0], $hook[1] ), "{$hook[1]} should be unhooked." );
		}
	}
}
