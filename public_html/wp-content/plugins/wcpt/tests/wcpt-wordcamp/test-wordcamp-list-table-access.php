<?php

namespace WordCamp\WCPT\Tests;

use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/trait-wordcamp-fixtures.php';

defined( 'WPINC' ) || die();

/**
 * Tests for who the WordCamp list table shows, and what it prints about them.
 *
 * @group wcpt
 */
class Test_WordCamp_List_Table_Access extends WP_UnitTestCase {

	use \WordCamp_Fixtures;

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
		$this->become_contributor();
		$this->become_wrangler();

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
		$organizer = $this->become_contributor();

		$own   = $this->create_wordcamp( $organizer );
		$other = $this->create_wordcamp( self::factory()->user->create() );

		$post_in = $this->limited_post_in();

		$this->assertContains( $own, $post_in );
		$this->assertNotContains( $other, $post_in );
	}

	/**
	 * Mentors reach their mentee camps through this screen, and `map_subrole_caps()` resolves
	 * the stored name through `wcorg_get_user_by_canonical_names()`, which accepts the login
	 * or the nicename. The list has to offer the same pair.
	 *
	 * @dataProvider data_mentor_name_fields
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 *
	 * @param string $field The user field the mentor meta was recorded from.
	 */
	public function test_contributor_query_includes_mentored_applications( $field ) {
		$mentor = get_userdata( $this->become_contributor() );

		$mentored = $this->create_wordcamp( self::factory()->user->create() );
		update_post_meta( $mentored, 'Mentor WordPress.org User Name', $mentor->$field );

		$this->assertContains( $mentored, $this->limited_post_in() );
	}

	/**
	 * @return array
	 */
	public function data_mentor_name_fields() {
		return array(
			'login'    => array( 'user_login' ),
			'nicename' => array( 'user_nicename' ),
		);
	}

	/**
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_contributor_with_no_applications_gets_an_empty_set() {
		$this->become_contributor();

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
		$this->become_contributor();

		$this->build_main_query();

		$secondary = new \WP_Query();
		$secondary->set( 'post_type', WCPT_POST_TYPE_ID );

		$this->admin->limit_list_to_editable_wordcamps( $secondary );

		$this->assertSame( '', $secondary->get( 'post__in' ) );
	}

	/**
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_other_post_type_query_is_not_limited() {
		$this->become_contributor();

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
		$this->become_contributor();
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
		$organizer = $this->become_contributor();

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
		$this->become_contributor();

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
		$this->become_contributor();

		$original = (object) array( 'publish' => 42 );

		$this->assertSame( $original, $this->admin->scope_status_counts( $original, 'post' ) );
	}

	/**
	 * The wrangler branch adds its own views on top of the plain status links.
	 *
	 * @covers WordCamp_Admin::alter_views
	 */
	public function test_status_links_are_kept_for_a_wrangler() {
		$this->become_contributor();
		$this->become_wrangler();

		// The wrangler branch echoes the Event Subtype links as a side effect.
		ob_start();
		$views = $this->admin->alter_views( array( 'all' => 'All (1804)' ) );
		ob_end_clean();

		// `all` survives the non-wrangler branch too, so it proves nothing. `mentoring` is
		// added only by the branch under test.
		$this->assertArrayHasKey( 'mentoring', $views );
	}

	/**
	 * Run the filter over a main query and return what it limited the results to.
	 *
	 * @return array|string The `post__in` query var.
	 */
	/**
	 * The default screen has to show the camps a viewer mentors, not only the ones they
	 * wrote. Core narrows the query to the current author for anyone who has written one of
	 * these and cannot edit others', which would hide the rest while the status links above
	 * still count them.
	 *
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_the_default_screen_shows_a_mentored_camp_to_an_author() {
		$user = $this->become_contributor();

		$authored = $this->create_wordcamp( $user );
		$mentored = $this->create_wordcamp( self::factory()->user->create() );

		update_post_meta( $mentored, 'Mentor WordPress.org User Name', wp_get_current_user()->user_login );

		$ids = wp_list_pluck( $this->run_list_screen_query()->posts, 'ID' );

		$this->assertContains( $authored, $ids );
		$this->assertContains( $mentored, $ids, 'The camp they mentor is missing from the default screen.' );
	}

	/**
	 * An explicitly chosen Mine view still narrows to what they wrote. Core only narrows
	 * when the request named nobody, so the two cases have to stay distinguishable.
	 *
	 * @covers WordCamp_Admin::limit_list_to_editable_wordcamps
	 */
	public function test_the_mine_view_still_narrows_to_what_they_authored() {
		$user = $this->become_contributor();

		$authored = $this->create_wordcamp( $user );
		$mentored = $this->create_wordcamp( self::factory()->user->create() );

		update_post_meta( $mentored, 'Mentor WordPress.org User Name', wp_get_current_user()->user_login );

		$_REQUEST['author'] = $user;
		$_GET['author']     = $user;

		$ids = wp_list_pluck( $this->run_list_screen_query()->posts, 'ID' );

		unset( $_REQUEST['author'], $_GET['author'] );

		$this->assertSame( array( $authored ), $ids );
	}

	/**
	 * The stored mentor name is resolved login-first everywhere else, so a user whose
	 * nicename happens to be somebody else's login is not the mentor.
	 *
	 * @covers WordCamp_Admin::get_authored_or_mentored_wordcamps
	 */
	public function test_a_nicename_matching_another_users_login_is_not_a_mentor() {
		$mentor = self::factory()->user->create_and_get(
			array(
				'user_login' => 'shared',
				'role'       => 'contributor',
			)
		);

		// Free the slug, so the other account can take it as a nicename.
		wp_update_user(
			array(
				'ID'            => $mentor->ID,
				'user_nicename' => 'shared-mentor',
			)
		);

		$bystander = self::factory()->user->create_and_get(
			array(
				'user_login' => 'alpha',
				'role'       => 'contributor',
			)
		);

		wp_update_user(
			array(
				'ID'            => $bystander->ID,
				'user_nicename' => 'shared',
			)
		);

		$camp = $this->create_wordcamp( self::factory()->user->create() );
		update_post_meta( $camp, 'Mentor WordPress.org User Name', 'shared' );

		wp_set_current_user( $bystander->ID );
		$this->assertNotContains( $camp, wp_list_pluck( $this->run_list_screen_query()->posts, 'ID' ), 'A bystander sees a camp they do not mentor.' );

		wp_set_current_user( $mentor->ID );
		$this->assertContains( $camp, wp_list_pluck( $this->run_list_screen_query()->posts, 'ID' ), 'The real mentor lost their camp.' );
	}

	protected function limited_post_in() {
		$query = $this->build_main_query();
		$this->admin->limit_list_to_editable_wordcamps( $query );

		return $query->get( 'post__in' );
	}
}
