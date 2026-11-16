<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Theme_Overrides\Cleanup;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';
require_once __DIR__ . '/trait-groups-site-patterns.php';
require_once dirname( __DIR__, 2 ) . '/wp-cli-commands/groups-theme-overrides.php';

/**
 * The one-off cleanup of group sites' theme overrides (#2368).
 *
 * @group groups
 */
class Test_Groups_Theme_Overrides_Cleanup extends Groups_TestCase {
	use Groups_Site_Patterns;

	/**
	 * Theme directories before this class added the repo's.
	 *
	 * @var array
	 */
	private $theme_directories;

	/**
	 * The group site each test runs on.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Run each test on a fresh group site on `groups-site`, read from this repo.
	 *
	 * After `Groups_TestCase::setUp()`, which switches to the network's root site.
	 */
	protected function setUp(): void {
		global $wp_theme_directories;

		parent::setUp();

		$this->theme_directories = $wp_theme_directories;
		register_theme_directory( SUT_WP_CONTENT_DIR . 'themes' );
		wp_clean_themes_cache();

		$this->site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/theme-cleanup/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		switch_to_blog( $this->site_id );
		switch_theme( 'groups-site' );
	}

	/**
	 * Undo `setUp()`.
	 */
	protected function tearDown(): void {
		global $wp_theme_directories;

		restore_current_blog();
		self::unregister_groups_site_patterns();

		$wp_theme_directories = $this->theme_directories; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the list `setUp()` changed.
		wp_clean_themes_cache();

		parent::tearDown();
	}

	/**
	 * Save a custom template or template part for `groups-site`, as the Site Editor does.
	 *
	 * @param string $post_type `wp_template` or `wp_template_part`.
	 * @param string $slug      Its slug.
	 * @param string $content   Its block markup.
	 */
	private function add_override( string $post_type, string $slug, string $content ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		wp_set_object_terms( $post_id, 'groups-site', 'wp_theme' );

		return $post_id;
	}

	/**
	 * The theme's front page with its shared header taken out.
	 */
	private function front_page_without_header(): string {
		$content = Cleanup\get_theme_template( 'front-page' );
		$header  = '<!-- wp:template-part {"slug":"header","className":"has-display-contents","tagName":"div","lock":{"move":true,"remove":true}} /-->';

		$this->assertStringContainsString( $header, $content, 'front-page.html no longer has the header this test removes.' );

		return str_replace( $header, '', $content );
	}

	/**
	 * Custom header and footer parts are reset, and so is a template that lost a locked block. A template that
	 * kept them, one the theme doesn't have, and any other part are only listed.
	 */
	public function test_audit_resets_shared_parts_and_templates_missing_locked_blocks() {
		$header_id = $this->add_override( 'wp_template_part', 'header', '<!-- wp:site-title /-->' );
		$this->add_override( 'wp_template_part', 'sidebar', '<!-- wp:site-title /-->' );
		$front_id = $this->add_override( 'wp_template', 'front-page', $this->front_page_without_header() );
		$this->add_override( 'wp_template', 'index', Cleanup\get_theme_template( 'index' ) . '<!-- wp:paragraph --><p>Added</p><!-- /wp:paragraph -->' );
		$this->add_override( 'wp_template', 'page-custom', '<!-- wp:post-content /-->' );

		$audit = Cleanup\audit_site();

		$this->assertSame( 'groups-site', $audit['theme'] );
		$this->assertSame( array( 'header', 'sidebar' ), $audit['parts'] );
		$this->assertSame( array( 'front-page', 'index', 'page-custom' ), $audit['templates'] );
		$this->assertSame( array( $header_id, $front_id ), array_keys( $audit['reset'] ) );
		$this->assertStringContainsString( 'core/template-part:header', $audit['reset'][ $front_id ] );
		$this->assertStringNotContainsString( 'footer', $audit['reset'][ $front_id ] );
	}

	/**
	 * The Site Editor saves a template with its patterns inlined, so a locked block that the theme keeps in a
	 * pattern, like the front page's About excerpt, counts too. A front page saved as it was is left alone.
	 */
	public function test_locked_blocks_in_patterns_count() {
		self::register_groups_site_patterns();

		$saved = serialize_blocks( resolve_pattern_blocks( parse_blocks( Cleanup\get_theme_template( 'front-page' ) ) ) );
		$about = '/<!-- wp:wporg\/page-content \{[^}]*"slug":"about".*?\/-->/';

		$this->assertMatchesRegularExpression( $about, $saved, 'The front page no longer has the About excerpt this test removes.' );

		$intact_id = $this->add_override( 'wp_template', 'front-page', $saved );
		$this->assertSame( array(), Cleanup\audit_site()['reset'] );

		wp_update_post(
			array(
				'ID'           => $intact_id,
				'post_content' => preg_replace( $about, '', $saved ),
			)
		);

		$reset = Cleanup\audit_site()['reset'];

		$this->assertSame( array( $intact_id ), array_keys( $reset ) );
		$this->assertStringContainsString( 'wporg/page-content:about', $reset[ $intact_id ] );
	}

	/**
	 * Resetting puts the site back on the theme and trashes what the audit said, and nothing else.
	 */
	public function test_reset_switches_back_and_trashes_only_what_the_audit_said() {
		$header_id = $this->add_override( 'wp_template_part', 'header', '<!-- wp:site-title /-->' );
		$front_id  = $this->add_override( 'wp_template', 'front-page', $this->front_page_without_header() );
		$index_id  = $this->add_override( 'wp_template', 'index', Cleanup\get_theme_template( 'index' ) );

		switch_theme( 'twentytwentyfour' );

		$done = Cleanup\reset_site();

		$this->assertSame( 'groups-site', get_stylesheet() );
		$this->assertCount( 3, $done );
		$this->assertSame( 'trash', get_post_status( $header_id ) );
		$this->assertSame( 'trash', get_post_status( $front_id ) );
		$this->assertSame( 'publish', get_post_status( $index_id ) );

		$audit = Cleanup\audit_site();

		$this->assertSame( array(), $audit['reset'] );
		$this->assertSame( array(), $audit['parts'] );
		$this->assertSame( array( 'index' ), $audit['templates'] );
	}

	/**
	 * The backup holds every override and the changed global styles, with what's needed to restore them. Global
	 * styles that were opened but not changed aren't listed.
	 */
	public function test_backup_holds_every_override_and_changed_global_styles() {
		$styles_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_global_styles',
				'post_name'    => 'wp-global-styles-groups-site',
				'post_status'  => 'publish',
				'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true}',
			)
		);

		$this->assertFalse( Cleanup\audit_site()['global_styles'] );

		wp_update_post(
			array(
				'ID'           => $styles_id,
				'post_content' => wp_slash( '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"background":"#000000"}}}' ),
			)
		);
		$part_id = $this->add_override( 'wp_template_part', 'footer', '<!-- wp:site-title /-->' );
		$this->add_override( 'wp_template', 'index', Cleanup\get_theme_template( 'index' ) );

		$this->assertTrue( Cleanup\audit_site()['global_styles'] );

		$backup = Cleanup\get_backup();
		$posts  = array_column( $backup['posts'], null, 'ID' );

		$this->assertSame( $this->site_id, $backup['blog_id'] );
		$this->assertSame( 'groups-site', $backup['theme'] );
		$this->assertCount( 3, $posts );
		$this->assertSame( '<!-- wp:site-title /-->', $posts[ $part_id ]['post_content'] );
		$this->assertSame( array( 'groups-site' ), $posts[ $part_id ]['terms']['wp_theme'] );
		$this->assertArrayHasKey( $styles_id, $posts );
	}
}
