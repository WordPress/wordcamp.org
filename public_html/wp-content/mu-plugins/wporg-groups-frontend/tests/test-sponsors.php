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
		wp_delete_site( $this->other_group_id );
		Sponsors\flush_cache();

		parent::tearDown();
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
	 * The admin screens only exist on the store site. A group organiser
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
	 * A group organiser — an administrator on their own group site, but not a
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
		$super_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// `grant_super_admin()` reads `site_admins` out of `sitemeta`, which
		// `Database_TestCase` truncates; setting the global that
		// `get_super_admins()` checks first is the fixture-safe equivalent.
		$GLOBALS['super_admins'] = array( get_userdata( $super_admin )->user_login ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( $super_admin );

		$post_id   = $this->create_sponsor();
		$post_type = get_post_type_object( Sponsors\POST_TYPE );

		switch_to_blog( self::$events_root_site_id );
		$can_edit_any = current_user_can( $post_type->cap->edit_posts );
		$can_edit_one = current_user_can( 'edit_post', $post_id );
		restore_current_blog();

		unset( $GLOBALS['super_admins'] );

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
}
