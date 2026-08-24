<?php

namespace WordCamp\WCPT\Tests;

use WP_Query;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for who the WordCamp list table shows, and what it prints about them.
 *
 * @group wcpt
 */
class Test_WordCamp_List_Table_Access extends WP_UnitTestCase {

	/**
	 * The admin instance under test, assigned in this suite's bootstrap.
	 *
	 * @var \WordCamp_Admin
	 */
	protected $admin;

	/**
	 * Set up the admin instance and put the request in the admin.
	 *
	 * The harness empties `$_GET` in `set_up()` and replaces `$wp_the_query` in
	 * `tear_down()`, so neither needs clearing here.
	 */
	public function set_up() {
		parent::set_up();

		global $wcorg_subroles;

		$this->admin    = $GLOBALS['wordcamp_admin'];
		$wcorg_subroles = array();

		set_current_screen( 'edit-' . WCPT_POST_TYPE_ID );
	}

	/**
	 * Leave the request outside the admin for whatever runs next.
	 */
	public function tear_down() {
		global $wcorg_subroles;

		$wcorg_subroles = array();

		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Wranglers curate the pipeline, so the whole list is theirs.
	 *
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_wrangler_query_is_not_limited() {
		global $wcorg_subroles;

		// The capability has to come through the subroles system: `omit_usermeta_caps()`
		// deliberately strips anything granted with `WP_User::add_cap()`.
		$wrangler       = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$wcorg_subroles = array( $wrangler => array( 'wordcamp_wrangler' ) );
		wp_set_current_user( $wrangler );

		$query = $this->build_main_query();
		$this->admin->limit_list_to_editable_wordcamps( $query );

		$this->assertSame( '', $query->get( 'post__in' ) );
	}

	/**
	 * The screen opens for anyone holding `edit_wordcamps`, which every lead organizer
	 * does, so it has to come back with their own applications and nothing else.
	 *
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_contributor_query_is_limited_to_own_applications() {
		$organizer = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $organizer );

		$own   = $this->create_wordcamp( $organizer );
		$other = $this->create_wordcamp( self::factory()->user->create() );

		$post_in = $this->limited_post_in();

		$this->assertContains( $own, $post_in );
		$this->assertNotContains( $other, $post_in );
	}

	/**
	 * Mentors reach their mentee camps through this screen. `map_subrole_caps()` lets
	 * them edit those posts, so the list has to keep offering them.
	 *
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_contributor_query_includes_mentored_applications() {
		$mentor = self::factory()->user->create_and_get( array( 'role' => 'contributor' ) );
		wp_set_current_user( $mentor->ID );

		$mentored = $this->create_wordcamp( self::factory()->user->create() );
		update_post_meta( $mentored, 'Mentor WordPress.org User Name', $mentor->user_login );

		$this->assertContains( $mentored, $this->limited_post_in() );
	}

	/**
	 * `map_subrole_caps()` resolves the mentor through `wcorg_get_user_by_canonical_names()`,
	 * which falls back to `user_nicename`. A mentor recorded that way can edit the post, so
	 * the list has to offer it.
	 *
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_contributor_query_matches_a_mentor_recorded_by_nicename() {
		$mentor = self::factory()->user->create_and_get(
			array(
				'role'          => 'contributor',
				'user_login'    => 'mentor-login',
				'user_nicename' => 'mentor-nicename',
			)
		);
		wp_set_current_user( $mentor->ID );

		$mentored = $this->create_wordcamp( self::factory()->user->create() );
		update_post_meta( $mentored, 'Mentor WordPress.org User Name', $mentor->user_nicename );

		$this->assertContains( $mentored, $this->limited_post_in() );
	}

	/**
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_contributor_with_no_applications_gets_an_empty_set() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->create_wordcamp( self::factory()->user->create() );

		$this->assertSame( array( 0 ), $this->limited_post_in() );
	}

	/**
	 * A logged-out request has no applications of its own either.
	 *
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_logged_out_query_gets_an_empty_set() {
		wp_set_current_user( 0 );

		$this->create_wordcamp( self::factory()->user->create() );

		$this->assertSame( array( 0 ), $this->limited_post_in() );
	}

	/**
	 * The filter's own lookups are secondary queries for this post type. Limiting those
	 * too would leave it unable to find anything.
	 *
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_secondary_query_is_not_limited() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->build_main_query();

		$secondary = new WP_Query();
		$secondary->set( 'post_type', WCPT_POST_TYPE_ID );

		$this->admin->limit_list_to_editable_wordcamps( $secondary );

		$this->assertSame( '', $secondary->get( 'post__in' ) );
	}

	/**
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_other_post_type_query_is_not_limited() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$query = $this->build_main_query();
		$query->set( 'post_type', 'post' );

		$this->admin->limit_list_to_editable_wordcamps( $query );

		$this->assertSame( '', $query->get( 'post__in' ) );
	}

	/**
	 * The front end runs `wordcamp` queries of its own, and its archive is public.
	 *
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_front_end_query_is_not_limited() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		set_current_screen( 'front' );

		$query = $this->build_main_query();
		$this->admin->limit_list_to_editable_wordcamps( $query );

		$this->assertSame( '', $query->get( 'post__in' ) );
	}

	/**
	 * `wp_count_posts()` has the same author blind spot as the list query, so the status
	 * links have to be counted over the same set the table is showing.
	 *
	 * @covers WordCamp_Admin::scope_status_counts
	 */
	public function test_status_counts_cover_only_the_viewers_own_applications() {
		$organizer = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $organizer );

		$this->create_wordcamp( $organizer );
		$this->create_wordcamp( self::factory()->user->create() );
		$this->create_wordcamp( self::factory()->user->create() );

		$counts = $this->admin->scope_status_counts( (object) array( WCPT_DEFAULT_STATUS => 3 ), WCPT_POST_TYPE_ID );

		$this->assertSame( 1, $counts->{WCPT_DEFAULT_STATUS} );
	}

	/**
	 * A status the viewer has none of reads 0 rather than dropping out of the object,
	 * which is what `WP_Posts_List_Table::get_views()` reads to decide whether to link it.
	 *
	 * @covers WordCamp_Admin::scope_status_counts
	 */
	public function test_status_counts_keep_every_key_core_supplied() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->create_wordcamp( self::factory()->user->create() );

		$counts = $this->admin->scope_status_counts(
			(object) array(
				WCPT_DEFAULT_STATUS => 1,
				'wcpt-rejected'     => 9,
			),
			WCPT_POST_TYPE_ID
		);

		$this->assertSame( 0, $counts->{'wcpt-rejected'} );
	}

	/**
	 * The filter is live for the whole request, so anything else counting in it has to
	 * come back untouched.
	 *
	 * @covers WordCamp_Admin::scope_status_counts
	 */
	public function test_status_counts_for_another_post_type_are_untouched() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$original = (object) array( 'publish' => 42 );

		$this->assertSame( $original, $this->admin->scope_status_counts( $original, 'post' ) );
	}

	/**
	 * The wrangler branch adds its own views on top of the plain status links.
	 *
	 * @covers WordCamp_Admin::alter_views
	 */
	public function test_status_links_are_kept_for_a_wrangler() {
		global $wcorg_subroles;

		$wrangler       = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$wcorg_subroles = array( $wrangler => array( 'wordcamp_wrangler' ) );
		wp_set_current_user( $wrangler );

		// The wrangler branch echoes the Event Subtype links as a side effect.
		ob_start();
		$views = $this->admin->alter_views( array( 'all' => 'All (1804)' ) );
		ob_end_clean();

		$this->assertArrayHasKey( 'all', $views );
	}

	/**
	 * Run the filter over a main query and return what it limited the results to.
	 *
	 * @return array|string The `post__in` query var.
	 */
	protected function limited_post_in() {
		$query = $this->build_main_query();
		$this->admin->limit_list_to_editable_wordcamps( $query );

		return $query->get( 'post__in' );
	}

	/**
	 * Build a WordCamp query that reports itself as the main query.
	 *
	 * `WP_Query::is_main_query()` compares against `$wp_the_query`, which the harness
	 * replaces in `tear_down()`.
	 *
	 * @return WP_Query
	 */
	protected function build_main_query() {
		$query = new WP_Query();
		$query->set( 'post_type', WCPT_POST_TYPE_ID );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- `is_main_query()` compares against it.
		$GLOBALS['wp_the_query'] = $query;

		return $query;
	}

	/**
	 * Create an unvetted application.
	 *
	 * @param int $author_id The lead organizer.
	 *
	 * @return int The post ID.
	 */
	protected function create_wordcamp( $author_id ) {
		return self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => WCPT_DEFAULT_STATUS,
				'post_author' => $author_id,
			)
		);
	}
}
