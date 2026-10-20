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
	 * The `PHP_SELF` in effect before the test.
	 *
	 * @var string|null
	 */
	protected $original_php_self;

	/**
	 * Make `go_to()` route the way a front-end request does.
	 */
	public function set_up(): void {
		parent::set_up();

		// `go_to()` fires `wp`, where `maybe_add_latest_site_hints()` switches to a
		// central blog the suite doesn't provision and logs DB errors. Nothing here
		// needs it. `WP_UnitTestCase` restores the hook registry after each test.
		remove_action( 'wp', 'WordCamp\\Latest_Site_Hints\\maybe_add_latest_site_hints' );

		// Another suite can leave a `wp-admin/` PHP_SELF behind, which makes
		// `WP::parse_request()` drop the query vars `go_to()` relies on.
		$this->original_php_self = $_SERVER['PHP_SELF'] ?? null;
		$_SERVER['PHP_SELF']     = '/index.php';
	}

	/**
	 * Restore state after each test.
	 */
	public function tear_down(): void {
		$_SERVER['PHP_SELF'] = $this->original_php_self;
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
		$settings            = get_option( 'wccsp_settings' );
		$settings            = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = 'on';
		update_option( 'wccsp_settings', $settings );
		wp_set_current_user( 0 );

		// A fresh instance registers the plugin's filters in the current hook
		// state, so this exercises the real `pre_get_document_title` wiring and
		// would fail if that `add_filter()` were missing or wrong.
		$plugin = new WordCamp_Coming_Soon_Page();
		$plugin->init();

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

		$title = wp_get_document_title();

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();
		$wp_query = $original_query;
		$post     = $original_post;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		remove_filter( 'pre_get_document_title', array( $plugin, 'force_placeholder_document_title' ) );

		$this->assertSame( get_bloginfo( 'name' ), $title );
	}

	/**
	 * While active, the head tags, headers and slug redirects that name the
	 * real post are unhooked, whatever priority core registered them at.
	 */
	public function test_identifying_links_are_unhooked_while_active() {
		$hooks = array(
			array( 'wp_head', 'rel_canonical' ),
			array( 'wp_head', 'wp_shortlink_wp_head' ),
			array( 'wp_head', 'wp_oembed_add_discovery_links' ),
			array( 'wp_head', 'rest_output_link_wp_head' ),
			array( 'wp_head', 'feed_links_extra' ),
			array( 'template_redirect', 'rest_output_link_header' ),
			array( 'template_redirect', 'wp_shortlink_header' ),
			array( 'template_redirect', 'wp_old_slug_redirect' ),
		);

		// Make sure each is present before asserting the placeholder removes it.
		foreach ( $hooks as $hook ) {
			if ( false === has_action( $hook[0], $hook[1] ) ) {
				add_action( $hook[0], $hook[1] );
			}
		}

		$this->set_coming_soon( 'on' );

		foreach ( $hooks as $hook ) {
			$this->assertFalse( has_action( $hook[0], $hook[1] ), "{$hook[1]} should be unhooked." );
		}
	}

	/**
	 * While inactive, the head tags, headers and slug redirects stay hooked.
	 */
	public function test_identifying_links_stay_hooked_while_inactive() {
		add_action( 'wp_head', 'feed_links_extra', 3 );
		add_action( 'template_redirect', 'wp_shortlink_header', 11 );

		$this->set_coming_soon( 'off' );

		$this->assertSame( 3, has_action( 'wp_head', 'feed_links_extra' ) );
		$this->assertSame( 11, has_action( 'template_redirect', 'wp_shortlink_header' ) );
	}

	/**
	 * WordPress.org's `wporg-seo` canonical tag, which stands in for core's on
	 * production, gets no URL while active and keeps it while inactive.
	 */
	public function test_wporg_canonical_url_is_withheld_only_while_active() {
		$url = 'https://example.org/organizer/secret-organizer-name/';

		$this->set_coming_soon( 'off' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wporg-seo's filter.
		$this->assertSame( $url, apply_filters( 'wporg_canonical_url', $url ) );

		$this->set_coming_soon( 'on' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wporg-seo's filter.
		$this->assertFalse( apply_filters( 'wporg_canonical_url', $url ) );
	}

	/**
	 * Build a fresh plugin instance with Coming Soon on, so its filters are
	 * registered in the current hook state and a test exercises the real wiring.
	 *
	 * @return WordCamp_Coming_Soon_Page
	 */
	protected function fresh_active_plugin(): WordCamp_Coming_Soon_Page {
		$this->set_coming_soon( 'on' );

		$plugin = new WordCamp_Coming_Soon_Page();
		$plugin->init();

		return $plugin;
	}

	/**
	 * The canonical redirect filter is wired up, not just the callback in
	 * isolation.
	 *
	 * Core's `redirect_canonical()` returns early under `is_admin()`, and the
	 * Remote CSS test bootstrap defines `WP_ADMIN` for the whole run, so this
	 * applies the filter core would apply rather than calling it.
	 */
	public function test_canonical_redirect_filter_is_wired_up() {
		$redirect = 'https://example.org/organizer/secret-organizer-name/';
		$request  = 'https://example.org/?p=' . self::$organizer_id;

		$this->assertSame( $redirect, apply_filters( 'redirect_canonical', $redirect, $request ), 'Nothing else should cancel the redirect.' );

		$this->fresh_active_plugin();

		$this->assertFalse( apply_filters( 'redirect_canonical', $redirect, $request ) );
	}

	/**
	 * The body class filter is wired up: `get_body_class()` itself carries no
	 * slug while active. Core spells out a term's slug on its archive.
	 */
	public function test_body_classes_through_the_real_path() {
		$term_id = self::factory()->category->create( array( 'slug' => 'secret-category-slug' ) );
		self::factory()->post->create( array( 'post_category' => array( $term_id ) ) );

		$this->go_to( add_query_arg( 'cat', $term_id, home_url( '/' ) ) );
		$this->assertContains( 'category-secret-category-slug', get_body_class(), 'Core should name the slug before the placeholder is on.' );

		$this->fresh_active_plugin();

		$classes = get_body_class();

		$this->assertEmpty( preg_grep( '/secret-category-slug/', $classes ) );
		$this->assertContains( 'category-' . $term_id, $classes );
	}
}
