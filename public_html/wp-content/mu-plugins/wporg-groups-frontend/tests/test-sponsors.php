<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Frontend\Sponsors;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Covers the network-level sponsor store and the `wporg/sponsors` block.
 *
 * The point of the feature is that one list renders identically on every
 * group site, so these tests create the sponsor on the store site
 * (`EVENTS_ROOT_BLOG_ID`, which `Database_TestCase` sets up) and read it back
 * from group sites, which sit on a different network entirely.
 *
 * @group groups
 */
class Test_Groups_Sponsors extends Groups_TestCase {
	/**
	 * A second group site, used to prove the data really is shared.
	 *
	 * @var int
	 */
	protected $other_group_id;

	/**
	 * Creates the second group site and clears the cached list, which would
	 * otherwise leak sponsors between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->other_group_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/testing-sponsors/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		Sponsors\flush_cache();
	}

	/**
	 * @inheritDoc
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['super_admins'] );
		wp_delete_site( $this->other_group_id );
		Sponsors\flush_cache();

		parent::tearDown();
	}

	/**
	 * Becomes a network admin for the rest of the test.
	 *
	 * `grant_super_admin()` reads `site_admins` out of `sitemeta`, which
	 * `Database_TestCase` truncates; setting the global that
	 * `get_super_admins()` checks first is the fixture-safe equivalent.
	 * `tearDown()` clears it.
	 *
	 * @return int The new user's ID.
	 */
	protected function act_as_network_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$GLOBALS['super_admins'] = array( get_userdata( $user_id )->user_login ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Creates a published sponsor on the store site.
	 *
	 * @param array $args Overrides for the sponsor post.
	 * @return int The new sponsor's post ID.
	 */
	protected function create_sponsor( array $args = array() ): int {
		$url = $args['url'] ?? 'https://example.org/sponsor/';
		unset( $args['url'] );

		switch_to_blog( self::$events_root_site_id );

		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => Sponsors\POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => 'Woo',
					'post_excerpt' => 'Woo is the leading open-source ecommerce platform.',
				),
				$args
			)
		);

		if ( $url ) {
			update_post_meta( $post_id, Sponsors\URL_META_KEY, $url );
		}

		restore_current_blog();
		Sponsors\flush_cache();

		return $post_id;
	}

	/**
	 * The sponsor post type has to exist on every group site, not just the
	 * one that stores the posts, so the block can resolve it while reading.
	 */
	public function test_post_type_is_registered() {
		$this->assertTrue( post_type_exists( Sponsors\POST_TYPE ) );
	}

	/**
	 * The admin screens only exist on the store site. A group organizer
	 * browsing their own wp-admin should never find a Sponsors menu, quite
	 * apart from not having the capability to use one.
	 *
	 * Re-runs the registration in each blog's context, since `show_ui` is
	 * decided once at `init` from whichever site is serving the request.
	 */
	public function test_admin_ui_is_only_registered_on_the_store_site() {
		$show_ui = function () {
			Sponsors\register_post_type();

			return get_post_type_object( Sponsors\POST_TYPE )->show_ui;
		};

		switch_to_blog( $this->other_group_id );
		$on_group_site = $show_ui();
		restore_current_blog();

		switch_to_blog( self::$events_root_site_id );
		$on_store_site = $show_ui();
		restore_current_blog();

		// Leave the registration as this test found it.
		Sponsors\register_post_type();

		$this->assertFalse( $on_group_site );
		$this->assertTrue( $on_store_site );
	}

	/**
	 * A sponsor added once on the store site is readable from an unrelated
	 * group site on another network — the whole point of the feature.
	 */
	public function test_sponsors_are_readable_from_another_group_site() {
		$this->create_sponsor();

		switch_to_blog( $this->other_group_id );
		$sponsors = Sponsors\get_sponsors();
		restore_current_blog();

		$this->assertCount( 1, $sponsors );
		$this->assertSame( 'Woo', $sponsors[0]['name'] );
		$this->assertSame( 'https://example.org/sponsor/', $sponsors[0]['url'] );
		$this->assertStringStartsWith( 'Woo is the leading', $sponsors[0]['description'] );
	}

	/**
	 * Nothing is copied into the group sites — a group site that queries its
	 * own tables for sponsors finds none, so there's no second copy to drift.
	 */
	public function test_sponsors_are_not_duplicated_into_group_sites() {
		$this->create_sponsor();

		switch_to_blog( $this->other_group_id );
		$local = get_posts(
			array(
				'post_type'   => Sponsors\POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);
		restore_current_blog();

		$this->assertSame( array(), $local );
	}

	/**
	 * Drafted and trashed sponsors stay off the group sites.
	 */
	public function test_unpublished_sponsors_are_excluded() {
		$this->create_sponsor( array( 'post_status' => 'draft' ) );

		$this->assertSame( array(), Sponsors\get_sponsors() );
	}

	/**
	 * Editing a sponsor has to show up on the group sites without waiting for
	 * the cache to expire.
	 */
	public function test_cache_is_invalidated_when_a_sponsor_changes() {
		$post_id = $this->create_sponsor();

		// Prime the cache from the other site.
		switch_to_blog( $this->other_group_id );
		Sponsors\get_sponsors();
		restore_current_blog();

		switch_to_blog( self::$events_root_site_id );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Woo Renamed',
			)
		);
		restore_current_blog();

		switch_to_blog( $this->other_group_id );
		$sponsors = Sponsors\get_sponsors();
		restore_current_blog();

		$this->assertSame( 'Woo Renamed', $sponsors[0]['name'] );
	}

	/**
	 * Trashing a sponsor removes it from the group sites immediately too.
	 */
	public function test_cache_is_invalidated_when_a_sponsor_is_trashed() {
		$post_id = $this->create_sponsor();

		Sponsors\get_sponsors();

		switch_to_blog( self::$events_root_site_id );
		wp_trash_post( $post_id );
		restore_current_blog();

		$this->assertSame( array(), Sponsors\get_sponsors() );
	}

	/**
	 * Sponsors render in the order network admins put them in, not by date.
	 */
	public function test_sponsors_are_ordered_by_menu_order_then_title() {
		$this->create_sponsor(
			array(
				'post_title' => 'Zeta',
				'menu_order' => 1,
			)
		);
		$this->create_sponsor(
			array(
				'post_title' => 'Beta',
				'menu_order' => 2,
			)
		);
		$this->create_sponsor(
			array(
				'post_title' => 'Alpha',
				'menu_order' => 2,
			)
		);

		$this->assertSame(
			array( 'Zeta', 'Alpha', 'Beta' ),
			wp_list_pluck( Sponsors\get_sponsors(), 'name' )
		);
	}

	/**
	 * A group organizer — an administrator on their own group site, but not a
	 * network admin — must not be able to edit the shared sponsor list.
	 */
	public function test_group_organisers_cannot_edit_sponsors() {
		$organiser = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_user_to_blog( $this->other_group_id, $organiser, 'administrator' );
		wp_set_current_user( $organiser );

		$post_id   = $this->create_sponsor();
		$post_type = get_post_type_object( Sponsors\POST_TYPE );

		switch_to_blog( $this->other_group_id );
		$can_edit_any = current_user_can( $post_type->cap->edit_posts );
		$can_create   = current_user_can( $post_type->cap->create_posts );
		$can_delete   = current_user_can( $post_type->cap->delete_posts );
		restore_current_blog();

		switch_to_blog( self::$events_root_site_id );
		$can_edit_one = current_user_can( 'edit_post', $post_id );
		restore_current_blog();

		$this->assertFalse( $can_edit_any, 'A site administrator should not be able to edit sponsors.' );
		$this->assertFalse( $can_create, 'A site administrator should not be able to add sponsors.' );
		$this->assertFalse( $can_delete, 'A site administrator should not be able to delete sponsors.' );
		$this->assertFalse( $can_edit_one, 'A site administrator should not be able to edit a specific sponsor.' );
	}

	/**
	 * Network admins are the ones who can.
	 */
	public function test_network_admins_can_edit_sponsors() {
		$this->act_as_network_admin();

		$post_id   = $this->create_sponsor();
		$post_type = get_post_type_object( Sponsors\POST_TYPE );

		switch_to_blog( self::$events_root_site_id );
		$can_edit_any = current_user_can( $post_type->cap->edit_posts );
		$can_edit_one = current_user_can( 'edit_post', $post_id );
		restore_current_blog();

		$this->assertTrue( $can_edit_any );
		$this->assertTrue( $can_edit_one );
	}

	/**
	 * The block renders the sponsor's logo, name, description and link.
	 */
	public function test_block_renders_sponsor_details() {
		$this->create_sponsor();

		switch_to_blog( $this->other_group_id );
		$output = do_blocks( '<!-- wp:wporg/sponsors /-->' );
		restore_current_blog();

		$this->assertStringContainsString( 'wporg-sponsors__list', $output );
		$this->assertStringContainsString( 'Woo', $output );
		$this->assertStringContainsString( 'Woo is the leading open-source ecommerce platform.', $output );
		$this->assertStringContainsString( 'href="https://example.org/sponsor/"', $output );
		$this->assertStringContainsString( 'rel="noopener nofollow sponsor"', $output );
	}

	/**
	 * Zero sponsors must render nothing at all — the block sits in the theme
	 * templates of every group site, so an empty card would show up on sites
	 * that have never had a sponsor.
	 */
	public function test_block_renders_nothing_without_sponsors() {
		switch_to_blog( $this->other_group_id );
		$output = trim( do_blocks( '<!-- wp:wporg/sponsors /-->' ) );
		restore_current_blog();

		$this->assertSame( '', $output );
	}

	/**
	 * A sponsor with no website URL still renders, just without a link.
	 */
	public function test_block_renders_sponsor_without_url() {
		$this->create_sponsor( array( 'url' => '' ) );

		switch_to_blog( $this->other_group_id );
		$output = do_blocks( '<!-- wp:wporg/sponsors /-->' );
		restore_current_blog();

		$this->assertStringContainsString( 'Woo', $output );
		$this->assertStringNotContainsString( '<a class="wporg-sponsors__link"', $output );
	}

	/**
	 * Sponsors past the block's `limit` are rendered but hidden, with a
	 * "Show all" button to reveal them.
	 */
	public function test_block_hides_sponsors_past_the_limit() {
		$this->create_sponsor( array( 'post_title' => 'Alpha' ) );
		$this->create_sponsor( array( 'post_title' => 'Beta' ) );
		$this->create_sponsor( array( 'post_title' => 'Gamma' ) );

		switch_to_blog( $this->other_group_id );
		$output = do_blocks( '<!-- wp:wporg/sponsors {"limit":2} /-->' );
		restore_current_blog();

		$this->assertStringContainsString( 'wporg-sponsors__show-all', $output );
		$this->assertSame( 1, substr_count( $output, 'data-wp-bind--hidden="!context.isExpanded"' ) );
	}

	/**
	 * No "Show all" button when everything already fits.
	 */
	public function test_block_omits_show_all_when_everything_fits() {
		$this->create_sponsor();

		switch_to_blog( $this->other_group_id );
		$output = do_blocks( '<!-- wp:wporg/sponsors {"limit":4} /-->' );
		restore_current_blog();

		$this->assertStringNotContainsString( 'wporg-sponsors__show-all', $output );
	}

	/**
	 * Submits the sponsor URL meta box for a post, as wp-admin would.
	 *
	 * @param int    $post_id The sponsor being saved.
	 * @param string $url     The value typed into the field.
	 * @param bool   $valid_nonce Whether to send a valid nonce.
	 */
	protected function submit_url_form( int $post_id, string $url, bool $valid_nonce = true ): void {
		$_POST['wporg_sponsor_url']       = addslashes( $url );
		$_POST['wporg_sponsor_url_nonce'] = $valid_nonce
			? wp_create_nonce( 'wporg_sponsor_url' )
			: 'not-a-nonce';

		Sponsors\save_sponsor_url( $post_id );

		unset( $_POST['wporg_sponsor_url'], $_POST['wporg_sponsor_url_nonce'] );
	}

	/**
	 * The happy path: a valid URL is sanitised and stored.
	 */
	public function test_meta_box_saves_a_valid_url() {
		$this->act_as_network_admin();

		$post_id = $this->create_sponsor();

		switch_to_blog( self::$events_root_site_id );
		$this->submit_url_form( $post_id, 'https://example.org/new/' );
		$saved = get_post_meta( $post_id, Sponsors\URL_META_KEY, true );
		restore_current_blog();

		$this->assertSame( 'https://example.org/new/', $saved );
	}

	/**
	 * Clearing the field removes the link, as the field's own help text
	 * promises.
	 */
	public function test_meta_box_clears_the_url_when_the_field_is_emptied() {
		$this->act_as_network_admin();

		$post_id = $this->create_sponsor();

		switch_to_blog( self::$events_root_site_id );
		$this->submit_url_form( $post_id, '   ' );
		$saved = get_post_meta( $post_id, Sponsors\URL_META_KEY, true );
		restore_current_blog();

		$this->assertSame( '', $saved );
	}

	/**
	 * Data provider for test_meta_box_keeps_the_url_when_input_is_unusable().
	 */
	public function data_unusable_urls(): array {
		return array(
			'mistyped scheme'     => array( 'htps://example.org/' ),
			'disallowed protocol' => array( 'javascript:alert(1)' ),
		);
	}

	/**
	 * Input that `sanitize_url()` can't parse must not wipe a working link.
	 *
	 * `sanitize_url()` returns '' for these, which is indistinguishable from
	 * the field having been cleared — so a single mistyped character used to
	 * silently delete the sponsor's URL.
	 *
	 * @dataProvider data_unusable_urls
	 */
	public function test_meta_box_keeps_the_url_when_input_is_unusable( string $typed ) {
		$this->act_as_network_admin();

		$post_id = $this->create_sponsor();

		switch_to_blog( self::$events_root_site_id );
		$this->submit_url_form( $post_id, $typed );
		$saved  = get_post_meta( $post_id, Sponsors\URL_META_KEY, true );
		$notice = get_transient( Sponsors\invalid_url_notice_key( $post_id ) );
		delete_transient( Sponsors\invalid_url_notice_key( $post_id ) );
		restore_current_blog();

		$this->assertSame( 'https://example.org/sponsor/', $saved, 'The existing URL should survive unusable input.' );
		$this->assertSame( $typed, $notice, 'The editor should be told their input was rejected.' );
	}

	/**
	 * A missing or forged nonce leaves the meta alone.
	 */
	public function test_meta_box_ignores_a_bad_nonce() {
		$this->act_as_network_admin();

		$post_id = $this->create_sponsor();

		switch_to_blog( self::$events_root_site_id );
		$this->submit_url_form( $post_id, 'https://evil.example/', false );
		$saved = get_post_meta( $post_id, Sponsors\URL_META_KEY, true );
		restore_current_blog();

		$this->assertSame( 'https://example.org/sponsor/', $saved );
	}

	/**
	 * A user without the capability can't write the field even with a valid
	 * nonce — the nonce proves intent, not permission.
	 */
	public function test_meta_box_ignores_a_user_without_the_capability() {
		$post_id = $this->create_sponsor();

		$organiser = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $organiser );

		switch_to_blog( self::$events_root_site_id );
		$this->submit_url_form( $post_id, 'https://evil.example/' );
		$saved = get_post_meta( $post_id, Sponsors\URL_META_KEY, true );
		restore_current_blog();

		$this->assertSame( 'https://example.org/sponsor/', $saved );
	}

	/**
	 * Data provider for test_rebase_url().
	 */
	public function data_rebase_url(): array {
		return array(
			'ms-files rewrite'      => array(
				'https://events.wordpress.test/wp-content/uploads/2026/08/logo.png',
				'https://events.wordpress.test/wp-content/uploads',
				'https://events.wordpress.test/files',
				'https://events.wordpress.test/files/2026/08/logo.png',
			),
			'trailing slashes'      => array(
				'https://events.wordpress.test/wp-content/uploads/logo.png',
				'https://events.wordpress.test/wp-content/uploads/',
				'https://events.wordpress.test/files/',
				'https://events.wordpress.test/files/logo.png',
			),
			'url outside the base'  => array(
				'https://cdn.example/logo.png',
				'https://events.wordpress.test/wp-content/uploads',
				'https://events.wordpress.test/files',
				'https://cdn.example/logo.png',
			),
			'empty base is a no-op' => array(
				'https://events.wordpress.test/wp-content/uploads/logo.png',
				'',
				'https://events.wordpress.test/files',
				'https://events.wordpress.test/wp-content/uploads/logo.png',
			),
		);
	}

	/**
	 * The rewriting half of the ms-files logo fix, in isolation — the
	 * end-to-end behaviour is covered by
	 * `test_sponsor_logo_url_is_loadable_from_a_group_site()`.
	 *
	 * @dataProvider data_rebase_url
	 */
	public function test_rebase_url( string $url, string $from, string $to, string $expected ) {
		$this->assertSame( $expected, Sponsors\rebase_url( $url, $from, $to ) );
	}

	/**
	 * The logo URL a group site renders has to be one a visitor can load.
	 *
	 * This is the regression guard for the ms-files fix. The fixture network
	 * reproduces the real condition — ms-files rewriting on, `UPLOADS`
	 * defined, the read happening while switched — so without
	 * `correct_switched_upload_url()` the logo comes back as
	 * `…/wp-content/blogs.dir/{id}/files/…`, which 404s in production.
	 */
	public function test_sponsor_logo_url_is_loadable_from_a_group_site() {
		$post_id = $this->create_sponsor();

		switch_to_blog( self::$events_root_site_id );

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Logo',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			),
			'2026/08/logo.png',
			$post_id
		);
		set_post_thumbnail( $post_id, $attachment_id );

		$expected = trailingslashit( get_option( 'siteurl' ) ) . 'files/2026/08/logo.png';

		restore_current_blog();
		Sponsors\flush_cache();

		switch_to_blog( $this->other_group_id );
		$sponsors = Sponsors\get_sponsors();
		$rendered = do_blocks( '<!-- wp:wporg/sponsors /-->' );
		restore_current_blog();

		$this->assertSame( $expected, $sponsors[0]['logo'] );
		$this->assertStringContainsString( $expected, $rendered );
		$this->assertStringNotContainsString(
			'blogs.dir',
			$sponsors[0]['logo'],
			"The raw ms-files path isn't publicly servable — see correct_switched_upload_url()."
		);
	}

	/**
	 * An empty URL — a sponsor with no logo — is never rewritten.
	 */
	public function test_empty_upload_url_is_untouched() {
		$this->assertSame( '', Sponsors\correct_switched_upload_url( '' ) );
		$this->assertFalse( Sponsors\upload_url_needs_correcting( '' ) );
	}
}
