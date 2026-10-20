<?php

defined( 'WPINC' ) || die();

/**
 * Tests for the Push to Active WordCamps endpoint.
 *
 * @group multi-event-sponsors
 */
class Test_MES_Push_To_Active_Camps extends WP_UnitTestCase {
	/**
	 * The root blog this class provisioned, if it wasn't already present.
	 *
	 * @var int|null
	 */
	protected static $created_root_blog;

	/**
	 * The group the fixture camp is in.
	 *
	 * @var int
	 */
	protected $group_id;

	/**
	 * Provision central, where the `mes` and WordCamp posts live. See Test_MES_Propagator.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
			self::$created_root_blog = $factory->blog->create( array(
				'blog_id'    => WORDCAMP_ROOT_BLOG_ID,
				'network_id' => defined( 'WORDCAMP_NETWORK_ID' ) ? WORDCAMP_NETWORK_ID : 1,
			) );
		}
	}

	/**
	 * Remove the root blog this class created.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$created_root_blog ) {
			wp_delete_site( self::$created_root_blog );
			self::$created_root_blog = null;
		}
	}

	/**
	 * Run on central with groups switched on, as a network admin.
	 */
	public function set_up() {
		parent::set_up();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		add_filter( 'mes_sponsor_groups_enabled', '__return_true' );

		$network_admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'network-admin',
			)
		);

		$GLOBALS['super_admins'] = array( 'network-admin' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Unset in tear_down().
		wp_set_current_user( $network_admin );
	}

	/**
	 * Back to the starting blog and user.
	 */
	public function tear_down() {
		unset( $GLOBALS['super_admins'] );
		wp_set_current_user( 0 );
		restore_current_blog();

		parent::tear_down();
	}

	/**
	 * A scheduled, upcoming camp with a site, in a group that sponsor A (published) and sponsor B
	 * (a draft) both target. A was already pushed to the camp, untouched since.
	 *
	 * @return array [ $wordcamp_id, $site_id, $sponsor_a, $sponsor_b, $pushed_a_id ]
	 */
	protected function make_fixture() {
		$this->group_id = self::factory()->term->create( array( 'taxonomy' => MES_Sponsor_Group::TAXONOMY_SLUG ) );
		$level_id       = self::factory()->post->create( array(
			'post_type'  => MES_Sponsorship_Level::POST_TYPE_SLUG,
			'post_title' => 'Gold',
			'post_name'  => 'gold',
		) );

		$sponsor_a = self::factory()->post->create( array(
			'post_type'    => MES_Sponsor::POST_TYPE_SLUG,
			'post_title'   => 'Sponsor A',
			'post_content' => 'New text from central.',
		) );
		$sponsor_b = self::factory()->post->create( array(
			'post_type'   => MES_Sponsor::POST_TYPE_SLUG,
			'post_status' => 'draft',
			'post_title'  => 'Sponsor B Brand',
		) );

		foreach ( array( $sponsor_a, $sponsor_b ) as $sponsor_id ) {
			update_post_meta( $sponsor_id, 'mes_group_sponsorships', array( $this->group_id => $level_id ) );
		}

		$site_id     = self::factory()->blog->create();
		$wordcamp_id = self::factory()->post->create( array(
			'post_type'   => 'wordcamp',
			'post_status' => 'wcpt-scheduled',
			'post_title'  => 'WordCamp Pushville',
		) );
		update_post_meta( $wordcamp_id, 'mes_sponsor_groups', array( $this->group_id ) );
		update_post_meta( $wordcamp_id, '_site_id', $site_id );
		update_post_meta( $wordcamp_id, 'Start Date (YYYY-mm-dd)', strtotime( '+30 days' ) );

		switch_to_blog( $site_id );
		$pushed_a_id = self::factory()->post->create( array(
			'post_type'    => 'wcb_sponsor',
			'post_status'  => 'publish',
			'post_title'   => 'Sponsor A',
			'post_content' => 'Old text.',
		) );
		update_post_meta( $pushed_a_id, '_mes_id', $sponsor_a );
		restore_current_blog();

		return array( $wordcamp_id, $site_id, $sponsor_a, $sponsor_b, $pushed_a_id );
	}

	/**
	 * Call the endpoint.
	 *
	 * @param int  $sponsor_id
	 * @param bool $dry_run
	 *
	 * @return WP_REST_Response
	 */
	protected function push( $sponsor_id, $dry_run ) {
		$request = new WP_REST_Request( 'POST', '/multi-event-sponsors/v1/push-to-active-camps' );
		$request->set_body_params( array(
			'sponsorId' => $sponsor_id,
			'dryRun'    => $dry_run,
		) );

		return rest_do_request( $request );
	}

	/**
	 * The camp site's sponsor posts for a `mes` post.
	 *
	 * @param int $site_id
	 * @param int $mes_id
	 *
	 * @return WP_Post[]
	 */
	protected function site_copies( $site_id, $mes_id ) {
		switch_to_blog( $site_id );
		$posts = get_posts( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'any',
			'meta_key'    => '_mes_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => $mes_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		) );
		restore_current_blog();

		return $posts;
	}

	/**
	 * Only network admins can push.
	 */
	public function test_push_requires_manage_network() {
		list( , , $sponsor_a ) = $this->make_fixture();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 403, $this->push( $sponsor_a, true )->get_status() );
	}

	/**
	 * Anything that isn't a Multi-Event Sponsor post is refused.
	 */
	public function test_push_refuses_a_post_that_is_not_a_sponsor() {
		$data = $this->push( self::factory()->post->create(), true )->get_data();

		$this->assertFalse( $data['success'] );
	}

	/**
	 * A preview of a published sponsor names the copies it would update, and changes nothing.
	 */
	public function test_preview_of_a_published_sponsor_writes_nothing() {
		list( , $site_id, $sponsor_a ) = $this->make_fixture();

		$data = $this->push( $sponsor_a, true )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['dry_run'] );
		$this->assertCount( 1, $data['updated'] );
		$this->assertSame( array(), $data['added'] );
		$this->assertSame( 'Old text.', $this->site_copies( $site_id, $sponsor_a )[0]->post_content );
	}

	/**
	 * Pushing a published sponsor updates the untouched copies, and adds none, as before.
	 */
	public function test_push_of_a_published_sponsor_updates_copies() {
		list( , $site_id, $sponsor_a ) = $this->make_fixture();

		$data = $this->push( $sponsor_a, false )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $data['updated'] );
		$this->assertSame( array(), $data['added'] );
		$this->assertSame( 'New text from central.', $this->site_copies( $site_id, $sponsor_a )[0]->post_content );
	}

	/**
	 * A preview of a draft names the camps it would add the sponsor to, and adds nothing.
	 */
	public function test_preview_of_a_draft_names_the_camps() {
		list( , $site_id, , $sponsor_b ) = $this->make_fixture();

		$data = $this->push( $sponsor_b, true )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( array( get_wordcamp_name( $site_id ) ), $data['added'] );
		$this->assertStringStartsWith( 'WordCamp Pushville', $data['added'][0] );
		$this->assertSame( array(), $this->site_copies( $site_id, $sponsor_b ) );
	}

	/**
	 * Pushing a draft adds it to the camps as a draft copy (#1208). Pushing again adds nothing new.
	 */
	public function test_push_of_a_draft_adds_draft_copies() {
		list( , $site_id, , $sponsor_b ) = $this->make_fixture();

		$data   = $this->push( $sponsor_b, false )->get_data();
		$copies = $this->site_copies( $site_id, $sponsor_b );

		$this->assertSame( array( get_wordcamp_name( $site_id ) ), $data['added'] );
		$this->assertSame( array(), $data['updated'], 'a copy added by this push is not also counted as updated' );
		$this->assertCount( 1, $copies );
		$this->assertSame( 'draft', $copies[0]->post_status );
		$this->assertSame( 'Sponsor B Brand', $copies[0]->post_title );

		$again = $this->push( $sponsor_b, false )->get_data();

		$this->assertSame( array(), $again['added'] );
		$this->assertCount( 1, $this->site_copies( $site_id, $sponsor_b ) );
	}

	/**
	 * Push a draft so it gets a copy, change its text on central, and run a change on the copy.
	 *
	 * @param callable $edit_copy Called on the camp site with the copy's ID.
	 *
	 * @return array [ $site_id, $copy_id, push response data ]
	 */
	protected function push_again_after( callable $edit_copy ) {
		list( , $site_id, , $sponsor_b ) = $this->make_fixture();
		$this->push( $sponsor_b, false );
		$copy_id = $this->site_copies( $site_id, $sponsor_b )[0]->ID;

		switch_to_blog( $site_id );
		$edit_copy( $copy_id );
		restore_current_blog();

		wp_update_post( array(
			'ID'           => $sponsor_b,
			'post_content' => 'Corrected brand text.',
		) );

		return array( $site_id, $copy_id, $this->push( $sponsor_b, false )->get_data() );
	}

	/**
	 * An organizer's edit to a draft copy is kept. WP resets a draft's dates on save, so the dates alone
	 * made it look untouched, and the next push overwrote it.
	 */
	public function test_push_skips_an_edited_draft_copy() {
		list( $site_id, $copy_id, $data ) = $this->push_again_after( function ( $copy_id ) {
			wp_update_post( array(
				'ID'           => $copy_id,
				'post_content' => 'Our camp-specific text.',
			) );
		} );

		$this->assertSame( array(), $data['updated'] );
		$this->assertCount( 1, $data['skipped_posts'] );

		switch_to_blog( $site_id );
		$this->assertSame( 'Our camp-specific text.', get_post( $copy_id )->post_content );
		$this->assertSame( 'draft', get_post_status( $copy_id ) );
		restore_current_blog();
	}

	/**
	 * Publishing a copy without changing it doesn't count as an edit, so it still gets updates.
	 */
	public function test_push_updates_a_copy_published_unchanged() {
		list( $site_id, $copy_id, $data ) = $this->push_again_after( function ( $copy_id ) {
			wp_update_post( array(
				'ID'          => $copy_id,
				'post_status' => 'publish',
			) );
		} );

		$this->assertCount( 1, $data['updated'] );

		switch_to_blog( $site_id );
		$this->assertSame( 'Corrected brand text.', get_post( $copy_id )->post_content );
		$this->assertSame( 'publish', get_post_status( $copy_id ) );
		restore_current_blog();
	}

	/**
	 * An edit to only the sponsor's details, not the text, is kept too.
	 */
	public function test_push_skips_a_copy_with_edited_sponsor_details() {
		list( $site_id, $copy_id, $data ) = $this->push_again_after( function ( $copy_id ) {
			update_post_meta( $copy_id, '_wcpt_sponsor_website', 'https://camp-specific.example' );
		} );

		$this->assertSame( array(), $data['updated'] );

		switch_to_blog( $site_id );
		$this->assertSame( 'https://camp-specific.example', get_post_meta( $copy_id, '_wcpt_sponsor_website', true ) );
		restore_current_blog();
	}
}
