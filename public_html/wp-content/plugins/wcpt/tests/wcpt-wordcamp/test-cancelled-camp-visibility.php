<?php

namespace WordCamp\WCPT\Tests;

use WP_Query;
use WP_REST_Request;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for which cancelled camps stay readable.
 *
 * A camp that reached the official schedule and was then cancelled is a public record that
 * consumers still need. A camp cancelled while it was still an application was never listed
 * anywhere. `wcpt-cancelled` is one status, so the query decides, not the status flag.
 *
 * @group wcpt
 */
class Test_Cancelled_Camp_Visibility extends WP_UnitTestCase {

	/**
	 * A camp that reached the schedule and was then cancelled.
	 *
	 * @var int
	 */
	protected $scheduled;

	/**
	 * A camp cancelled while it was still an application.
	 *
	 * @var int
	 */
	protected $application;

	/**
	 * Reset the subroles global and create one camp of each kind.
	 */
	public function set_up() {
		parent::set_up();

		global $wcorg_subroles;

		$wcorg_subroles = array();
		wp_set_current_user( 0 );

		/*
		 * `wordcamp-remote-css/tests/bootstrap.php` defines `WP_ADMIN` for the whole run, so `is_admin()` is
		 * true unless a screen says otherwise. A permalink or REST request is neither, and
		 * the filter exempts wp-admin, so every test starts on the front end and the one
		 * about the list table opts in.
		 */
		set_current_screen( 'front' );

		// A subscriber, not a contributor: an application cancelled before it was accepted
		// never ran `add_organizer_to_central()`, so its author holds no role here.
		$organizer = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->scheduled   = $this->create_cancelled_camp( 1560293422, $organizer );
		$this->application = $this->create_cancelled_camp( 0, $organizer );
	}

	/**
	 * Leave the global clean for whatever runs next.
	 */
	public function tear_down() {
		global $wcorg_subroles;

		$wcorg_subroles = array();

		parent::tear_down();
	}

	/**
	 * The status stays public: a camp that was announced and then cancelled keeps its page.
	 *
	 * @covers WordCamp_Loader::get_publicly_viewable_post_statuses
	 */
	public function test_cancelled_remains_a_publicly_viewable_status() {
		$this->assertContains( 'wcpt-cancelled', \WordCamp_Loader::get_publicly_viewable_post_statuses() );
		$this->assertTrue( get_post_status_object( 'wcpt-cancelled' )->public );
	}

	/**
	 * Official WordPress Events reads these to drop cancelled camps from the events widget.
	 *
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_camp_cancelled_after_scheduling_is_readable() {
		$this->assertSame( 200, $this->request_camp( $this->scheduled )->get_status() );
	}

	/**
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_camp_cancelled_while_an_application_is_not_readable() {
		$this->assertSame( 401, $this->request_camp( $this->application )->get_status() );
	}

	/**
	 * The refactor rewrote this branch, so pin that an ordinary public camp still reads.
	 *
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_a_scheduled_camp_is_still_readable() {
		// Scheduling fires the Slack notification, which reads both of these. CI fails on any
		// warning, and an incomplete camp cannot reach `wcpt-scheduled` in the admin anyway.
		$camp = self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-scheduled',
				'meta_input'  => array(
					'Start Date (YYYY-mm-dd)' => 1560293422,
					'URL'                     => 'https://seattle.wordcamp.test/2023/',
				),
			)
		);

		$this->assertSame( 200, $this->request_camp( $camp )->get_status() );
	}

	/**
	 * The applicant keeps their own record, matching what every other application status
	 * gives its author.
	 *
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_the_author_can_read_their_own_cancelled_application() {
		$author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$camp   = $this->create_cancelled_camp( 0, $author );

		wp_set_current_user( $author );

		// Without this the test passes on an `edit_post` check too, and proves nothing.
		$this->assertFalse( current_user_can( 'edit_wordcamps' ), 'The fixture holds the capability.' );

		$this->assertSame( 200, $this->request_camp( $camp )->get_status() );
		$this->assertCount( 1, $this->query_single( $camp )->posts );
	}

	/**
	 * Mentors keep the camps they mentor, the same set the admin list table gives them.
	 *
	 * @covers WordCamp_Loader::can_read_unscheduled_cancellation
	 */
	public function test_a_mentor_reads_their_mentee_application_on_both_surfaces() {
		$mentor = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		update_post_meta( $this->application, 'Mentor WordPress.org User Name', $mentor->user_login );
		wp_set_current_user( $mentor->ID );

		$this->assertSame( 200, $this->request_camp( $this->application )->get_status() );
		$this->assertCount( 1, $this->query_single( $this->application )->posts );
	}

	/**
	 * The stored name is matched against both canonical names, because that is what the
	 * list table does and what the SQL clause can express.
	 *
	 * @covers WordCamp_Loader::can_read_unscheduled_cancellation
	 */
	public function test_a_mentor_named_by_nicename_is_recognised() {
		// A distinct nicename, or the factory gives it the login and the lookup never runs.
		$mentor = self::factory()->user->create_and_get(
			array(
				'role'          => 'subscriber',
				'user_login'    => 'mentorlogin',
				'user_nicename' => 'mentor-slug',
			)
		);

		$this->assertNotSame( $mentor->user_login, $mentor->user_nicename );

		update_post_meta( $this->application, 'Mentor WordPress.org User Name', $mentor->user_nicename );
		wp_set_current_user( $mentor->ID );

		$this->assertSame( 200, $this->request_camp( $this->application )->get_status() );
		$this->assertCount( 1, $this->query_single( $this->application )->posts );
	}

	/**
	 * Somebody else's mentee stays somebody else's.
	 *
	 * @covers WordCamp_Loader::can_read_unscheduled_cancellation
	 */
	public function test_mentoring_one_camp_does_not_open_another() {
		$mentor = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		update_post_meta( $this->application, 'Mentor WordPress.org User Name', 'somebody_else' );
		wp_set_current_user( $mentor->ID );

		$this->assertSame( 403, $this->request_camp( $this->application )->get_status() );
		$this->assertEmpty( $this->query_single( $this->application )->posts );
	}

	/**
	 * `was_ever_scheduled()` reads `menu_order > 0`, so anything at or below zero has to be
	 * an application-stage cancellation on both surfaces. Nothing writes a negative today.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_a_negative_scheduled_date_counts_as_never_scheduled() {
		$camp = $this->create_cancelled_camp( -1, 0 );

		$this->assertSame( 401, $this->request_camp( $camp )->get_status() );
		$this->assertEmpty( $this->query_single( $camp )->posts );
	}

	/**
	 * The pre-planning statuses resolve at their permalink and stay refused here. Widening
	 * this to `get_publicly_viewable_post_statuses()` would publish the meta
	 * `register_rest_public_fields()` exposes, `Organizer Name` and `WordPress.org Username`
	 * among it, which `single-wordcamp.php` never renders. That is its own change.
	 *
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_pre_planning_camps_are_not_readable_over_rest() {
		foreach ( \WordCamp_Loader::get_pre_planning_post_statuses() as $status ) {
			$camp = self::factory()->post->create(
				array(
					'post_type'   => WCPT_POST_TYPE_ID,
					'post_status' => $status,
				)
			);

			$this->assertSame( 401, $this->request_camp( $camp )->get_status(), "$status is readable." );
		}
	}

	/**
	 * The clause and the predicate have to take the same exemptions. When the clause is the
	 * more permissive of the two it selects a row the predicate then refuses, and
	 * `get_items()` drops the item without reducing the total.
	 *
	 * @covers WordCamp_Loader::can_read_unscheduled_cancellation
	 */
	public function test_the_collection_agrees_with_its_count_inside_wp_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		set_current_screen( 'edit-wordcamp' );

		$request = new WP_REST_Request( 'GET', '/wp/v2/wordcamps' );
		$request->set_param( 'status', 'wcpt-cancelled' );
		$request->set_param( '_fields', 'id' );

		$response = rest_do_request( $request );

		set_current_screen( 'front' );

		$this->assertSame( count( $response->get_data() ), (int) $response->get_headers()['X-WP-Total'] );
		$this->assertContains( $this->application, wp_list_pluck( $response->get_data(), 'id' ) );
	}

	/**
	 * A camp can carry more than one row for the key. `IN ( ... )` matches any of them, so
	 * reading only the first would put the two sides back out of step.
	 *
	 * @covers WordCamp_Loader::is_mentored_by
	 */
	public function test_a_second_mentor_row_is_recognised() {
		$mentor = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		add_post_meta( $this->application, 'Mentor WordPress.org User Name', 'somebody_else' );
		add_post_meta( $this->application, 'Mentor WordPress.org User Name', $mentor->user_login );
		wp_set_current_user( $mentor->ID );

		$this->assertSame( 200, $this->request_camp( $this->application )->get_status() );
		$this->assertCount( 1, $this->query_single( $this->application )->posts );
	}

	/**
	 * Filtering per row would leave the count and the page total describing a set the
	 * response does not contain, so a client that stops on a short page truncates.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_the_collection_pages_over_only_the_readable_camps() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/wordcamps' );
		$request->set_param( 'status', 'wcpt-cancelled' );
		$request->set_param( '_fields', 'id' );

		$response = rest_do_request( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertContains( $this->scheduled, $ids );
		$this->assertNotContains( $this->application, $ids );
		$this->assertSame( count( $ids ), (int) $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * The front end and the REST API have to agree, which is the whole reason this is a
	 * query filter rather than a status flag.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_the_permalink_resolves_for_a_camp_cancelled_after_scheduling() {
		$this->assertCount( 1, $this->query_single( $this->scheduled )->posts );
	}

	/**
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_the_permalink_is_empty_for_a_camp_cancelled_while_an_application() {
		$this->assertEmpty( $this->query_single( $this->application )->posts );
	}

	/**
	 * Wranglers process these records, so the filter has to let them through.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_a_wrangler_still_sees_an_application_stage_cancellation() {
		global $wcorg_subroles;

		$wrangler = self::factory()->user->create( array( 'role' => 'contributor' ) );

		// `omit_usermeta_caps()` strips anything granted with `WP_User::add_cap()`.
		$wcorg_subroles = array( $wrangler => array( 'wordcamp_wrangler' ) );
		wp_set_current_user( $wrangler );

		$this->assertCount( 1, $this->query_single( $this->application )->posts );
	}

	/**
	 * `post_author` 0 is a real value, and the logged-out user ID is also 0, so the
	 * "keep your own" clause must not treat them as the same thing.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_an_unattributed_cancellation_is_not_readable_when_logged_out() {
		$orphan = $this->create_cancelled_camp( 0, 0 );

		$this->assertEmpty( $this->query_single( $orphan )->posts );
	}

	/**
	 * A personal data export has to be complete whoever is processing it, and a Central
	 * administrator without the wrangler subrole does not hold the capability.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_an_export_query_still_sees_an_application_stage_cancellation() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		// Not in wp-admin, or the exemption for it would carry this rather than the argument.
		set_current_screen( 'front' );

		$query = new WP_Query(
			array(
				'post_type' => WCPT_POST_TYPE_ID,
				'name'      => get_post_field( 'post_name', $this->application ),

				'wcpt_include_unscheduled_cancellations' => true,
			)
		);

		$this->assertCount( 1, $query->posts );
	}

	/**
	 * The export escape hatch is a programmatic argument. If a request could set it, it
	 * would undo everything above.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_the_export_escape_hatch_is_not_reachable_from_a_url() {
		global $wp;

		// `WP::parse_request()` only copies registered public query vars out of the request,
		// so a URL cannot reach this one. Asserted rather than exercised, because calling
		// `parse_request()` here would leave the global `$wp` altered for later tests.
		$this->assertNotContains( 'wcpt_include_unscheduled_cancellations', $wp->public_query_vars );
		$this->assertArrayNotHasKey( 'wcpt_include_unscheduled_cancellations', $GLOBALS['wp_query']->query_vars );
	}

	/**
	 * The mentor arm is a second way into the `WHERE` clause, and the reason this filters in
	 * the query at all is that items, `X-WP-Total` and page count have to agree.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_the_collection_agrees_with_its_count_for_a_mentor() {
		$mentor = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$theirs = $this->create_cancelled_camp( 0, 0 );

		update_post_meta( $theirs, 'Mentor WordPress.org User Name', $mentor->user_login );
		wp_set_current_user( $mentor->ID );

		$request = new WP_REST_Request( 'GET', '/wp/v2/wordcamps' );
		$request->set_param( 'status', 'wcpt-cancelled' );
		$request->set_param( '_fields', 'id' );
		$request->set_param( 'per_page', 2 );

		$response = rest_do_request( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );
		$headers  = $response->get_headers();

		$this->assertContains( $theirs, $ids, 'The mentor does not see their mentee camp.' );
		$this->assertNotContains( $this->application, $ids, 'The mentor sees a camp they do not mentor.' );
		$this->assertSame( 2, (int) $headers['X-WP-Total'], 'The total disagrees with the readable set.' );
		$this->assertSame( 1, (int) $headers['X-WP-TotalPages'] );
	}

	/**
	 * The clause names `wp_postmeta` inside a subselect while a `meta_query` on the same
	 * request aliases it in the outer query, which is how the subtype filter runs.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_the_clause_survives_a_meta_query_on_the_same_request() {
		$mentor = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$theirs = $this->create_cancelled_camp( 0, 0 );

		update_post_meta( $theirs, 'Mentor WordPress.org User Name', $mentor->user_login );
		update_post_meta( $theirs, 'event_subtype', 'next-gen' );
		wp_set_current_user( $mentor->ID );

		$query = new WP_Query(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-cancelled',
				'meta_query'  => array(
					array(
						'key'   => 'event_subtype',
						'value' => 'next-gen',
					),
				),
			)
		);

		// A malformed clause returns nothing here, so the row coming back is the assertion.
		$this->assertSame( array( $theirs ), wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * The stored name is free text, so `MentorJane` for `mentorjane` is ordinary input. The
	 * SQL compares under the column collation and ignores case, so resolving the name in PHP
	 * has to ignore it too.
	 *
	 * @covers WordCamp_Loader::is_mentored_by
	 */
	public function test_a_mentor_named_in_a_different_case_is_recognised() {
		$mentor = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		update_post_meta( $this->application, 'Mentor WordPress.org User Name', strtoupper( $mentor->user_login ) );
		wp_set_current_user( $mentor->ID );

		$this->assertSame( 200, $this->request_camp( $this->application )->get_status() );
		$this->assertCount( 1, $this->query_single( $this->application )->posts );
	}

	/**
	 * `get_items()` re-checks `check_read_permission()` per row, so when the clause selects a
	 * camp the predicate then refuses, the item is dropped and the total is not. That short
	 * page is the failure filtering in the query exists to avoid.
	 *
	 * @covers WordCamp_Loader::is_mentored_by
	 */
	public function test_the_collection_count_matches_its_items_for_a_differently_cased_mentor() {
		$mentor = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		update_post_meta( $this->application, 'Mentor WordPress.org User Name', strtoupper( $mentor->user_login ) );
		wp_set_current_user( $mentor->ID );

		$request = new WP_REST_Request( 'GET', '/wp/v2/wordcamps' );
		$request->set_param( 'status', 'wcpt-cancelled' );
		$request->set_param( '_fields', 'id' );

		$response = rest_do_request( $request );

		$this->assertSame( count( $response->get_data() ), (int) $response->get_headers()['X-WP-Total'] );
		$this->assertContains( $this->application, wp_list_pluck( $response->get_data(), 'id' ) );
	}

	/**
	 * The list table is not a public surface, and every other application status stays in it.
	 * Which camp a viewer sees there is #1943's subject.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_an_administrator_without_the_subrole_still_sees_it_in_wp_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( current_user_can( 'wordcamp_wrangle_wordcamps' ), 'The fixture holds the subrole.' );

		set_current_screen( 'edit-wordcamp' );

		$query = new WP_Query(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-cancelled',
			)
		);

		set_current_screen( 'front' );

		$this->assertContains( $this->application, wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * Any logged-in user can reach admin-ajax, where `is_admin()` is true as well, so the
	 * exemption for wp-admin must not carry to it.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 */
	public function test_the_wp_admin_exemption_does_not_reach_admin_ajax() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'edit-wordcamp' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$query = new WP_Query(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-cancelled',
			)
		);

		remove_filter( 'wp_doing_ajax', '__return_true' );
		set_current_screen( 'front' );

		$this->assertNotContains( $this->application, wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * A stored name can be one account's login and another's nicename. It belongs to the login
	 * holder, because that is who `wcorg_get_user_by_canonical_names()` resolves it to and so
	 * who holds `edit_post` on the camp through `map_subrole_caps()`. The clause has to select
	 * the same person, or it returns a row the rule refuses.
	 *
	 * @covers WordCamp_Loader::mentor_names_for
	 * @covers WordCamp_Loader::is_mentored_by
	 */
	public function test_a_name_that_is_another_login_belongs_to_the_login_holder() {
		$login_holder = self::factory()->user->create_and_get(
			array(
				'role'       => 'subscriber',
				'user_login' => 'sharedname',
			)
		);

		// Free the nicename, so the account below can hold it while this one keeps the login.
		wp_update_user(
			array(
				'ID'            => $login_holder->ID,
				'user_nicename' => 'sharedname-other',
			)
		);

		$slug_holder = self::factory()->user->create_and_get(
			array(
				'role'          => 'subscriber',
				'user_login'    => 'mentorbylogin',
				'user_nicename' => 'sharedname',
			)
		);

		// The fixture only means anything if the two accounts ended up holding what they asked
		// for, and `wp_update_user()` or a uniqueness suffix can quietly refuse either half.
		$slug_holder = get_user_by( 'id', $slug_holder->ID );

		$this->assertSame( $login_holder->ID, get_user_by( 'login', 'sharedname' )->ID, 'The login went elsewhere.' );
		$this->assertSame( 'sharedname', $slug_holder->user_nicename, 'The nicename went elsewhere.' );

		update_post_meta( $this->application, 'Mentor WordPress.org User Name', 'sharedname' );

		$expected = array(
			$login_holder->ID => true,
			$slug_holder->ID  => false,
		);

		foreach ( $expected as $viewer => $is_the_mentor ) {
			wp_set_current_user( 0 );
			wp_set_current_user( $viewer );

			$request = new WP_REST_Request( 'GET', '/wp/v2/wordcamps' );
			$request->set_param( 'status', 'wcpt-cancelled' );
			$request->set_param( '_fields', 'id' );

			$response = rest_do_request( $request );
			$ids      = wp_list_pluck( $response->get_data(), 'id' );

			$this->assertSame( count( $ids ), (int) $response->get_headers()['X-WP-Total'] );

			if ( $is_the_mentor ) {
				$this->assertContains( $this->application, $ids );
			} else {
				$this->assertNotContains( $this->application, $ids );
			}
		}
	}

	/**
	 * The clause and the rule have to admit the same set.
	 *
	 * `hide_unscheduled_cancellations()` decides in SQL and `can_read_unscheduled_cancellation()`
	 * decides in PHP, about the same camps. Where they disagree the clause selects a row the rule
	 * then refuses, `get_items()` drops the item without reducing `X-WP-Total`, and the response
	 * is a page short. Every defect review has found here has been one input the two answered
	 * differently, each on a dimension nobody had thought to enumerate yet, so this compares them
	 * across the matrix rather than one case at a time.
	 *
	 * @covers WordCamp_Loader::hide_unscheduled_cancellations
	 * @covers WordCamp_Loader::can_read_unscheduled_cancellation
	 */
	public function test_the_clause_and_the_rule_admit_the_same_set() {
		global $wcorg_subroles;

		$author   = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$by_login = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$second   = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$stranger = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$admin    = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$wrangler = self::factory()->user->create_and_get( array( 'role' => 'contributor' ) );

		$by_slug = self::factory()->user->create_and_get(
			array(
				'role'          => 'subscriber',
				'user_login'    => 'matrixslug',
				'user_nicename' => 'matrix-slug',
			)
		);

		// A name that is one account's login and another's nicename.
		$holds_login = self::factory()->user->create_and_get(
			array(
				'role'       => 'subscriber',
				'user_login' => 'matrixshared',
			)
		);

		wp_update_user(
			array(
				'ID'            => $holds_login->ID,
				'user_nicename' => 'matrixshared-moved',
			)
		);

		$holds_slug = self::factory()->user->create_and_get(
			array(
				'role'          => 'subscriber',
				'user_login'    => 'matrixother',
				'user_nicename' => 'matrixshared',
			)
		);

		$wcorg_subroles = array( $wrangler->ID => array( 'wordcamp_wrangler' ) );

		// Camps, all cancelled and none of them ever scheduled.
		$camps = array(
			'unrelated'        => $this->create_cancelled_camp( 0, 0 ),
			'negative order'   => $this->create_cancelled_camp( -1, 0 ),
			'authored'         => $this->create_cancelled_camp( 0, $author->ID ),
			'mentor by login'  => $this->create_cancelled_camp( 0, 0 ),
			'mentor by slug'   => $this->create_cancelled_camp( 0, 0 ),
			'mentor 2nd row'   => $this->create_cancelled_camp( 0, 0 ),
			'mentor collision' => $this->create_cancelled_camp( 0, 0 ),
			'mentor cased'     => $this->create_cancelled_camp( 0, 0 ),
		);

		update_post_meta( $camps['mentor by login'], 'Mentor WordPress.org User Name', $by_login->user_login );
		update_post_meta( $camps['mentor by slug'], 'Mentor WordPress.org User Name', $by_slug->user_nicename );
		update_post_meta( $camps['mentor collision'], 'Mentor WordPress.org User Name', 'matrixshared' );
		update_post_meta( $camps['mentor cased'], 'Mentor WordPress.org User Name', strtoupper( $by_login->user_login ) );
		add_post_meta( $camps['mentor 2nd row'], 'Mentor WordPress.org User Name', 'nobody_at_all' );
		add_post_meta( $camps['mentor 2nd row'], 'Mentor WordPress.org User Name', $second->user_login );

		$viewers = array(
			'logged out'   => 0,
			'author'       => $author->ID,
			'by login'     => $by_login->ID,
			'by slug'      => $by_slug->ID,
			'second row'   => $second->ID,
			'holds slug'   => $holds_slug->ID,
			'holds login'  => $holds_login->ID,
			'stranger'     => $stranger->ID,
			'administrator' => $admin->ID,
			'wrangler'     => $wrangler->ID,
		);

		$contexts   = array( 'front', 'wp-admin', 'ajax' );
		$controller = new \WordCamp_REST_WordCamps_Controller( WCPT_POST_TYPE_ID );

		foreach ( $contexts as $context ) {
			foreach ( $viewers as $viewer_name => $viewer_id ) {
				foreach ( $camps as $camp_name => $camp_id ) {
					wp_set_current_user( 0 );
					wp_set_current_user( $viewer_id );

					set_current_screen( 'front' === $context ? 'front' : 'edit-wordcamp' );

					if ( 'ajax' === $context ) {
						add_filter( 'wp_doing_ajax', '__return_true' );
					}

					$query = new WP_Query(
						array(
							'post_type'   => WCPT_POST_TYPE_ID,
							'post_status' => 'wcpt-cancelled',
							'p'           => $camp_id,
						)
					);

					$selected = ! empty( $query->posts );
					// The controller's decision, not the predicate alone: `get_items()` calls this,
					// so a change that stops consulting the rule has to be caught here too.
					$allowed = $controller->check_read_permission( get_post( $camp_id ) );

					if ( 'ajax' === $context ) {
						remove_filter( 'wp_doing_ajax', '__return_true' );
					}

					$this->assertSame(
						$selected,
						$allowed,
						"The clause and the rule disagree: $viewer_name, $camp_name, $context. " .
						'The clause ' . ( $selected ? 'selected' : 'withheld' ) . ' it and the rule ' .
						( $allowed ? 'allows' : 'refuses' ) . ' it.'
					);
				}
			}
		}

		set_current_screen( 'front' );
		$wcorg_subroles = array();
	}

	/**
	 * Run the query a request for a camp's permalink produces.
	 *
	 * Queried by name rather than through `go_to()`, which depends on rewrite rules and
	 * `$_SERVER` globals that other suites in this run leave modified.
	 *
	 * @param int $post_id
	 *
	 * @return WP_Query
	 */
	protected function query_single( $post_id ) {
		set_current_screen( 'front' );

		return new WP_Query(
			array(
				'post_type' => WCPT_POST_TYPE_ID,
				'name'      => get_post_field( 'post_name', $post_id ),
			)
		);
	}

	/**
	 * Ask the REST controller for one camp.
	 *
	 * @param int $post_id
	 *
	 * @return \WP_REST_Response
	 */
	protected function request_camp( $post_id ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/wordcamps/' . $post_id );
		$request->set_param( '_fields', 'id,status' );

		return rest_do_request( $request );
	}

	/**
	 * @param int $scheduled_date The date the camp was added to the schedule, or 0 if it
	 *                            never reached it. Stored in `menu_order`.
	 * @param int $author_id      The camp's author.
	 *
	 * @return int
	 */
	protected function create_cancelled_camp( $scheduled_date, $author_id ) {
		return self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => 'wcpt-cancelled',
				'menu_order'  => $scheduled_date,
				'post_author' => $author_id,
			)
		);
	}
}
