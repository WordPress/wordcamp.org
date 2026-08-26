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
		$mentor = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

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
	 * #1931 gave the pre-planning statuses a page on Central, and
	 * `get_publicly_viewable_post_statuses()` brings the API into line with it. These
	 * answer anonymously where they used to be refused, which is a deliberate widening.
	 *
	 * @covers WordCamp_REST_WordCamps_Controller::check_read_permission
	 */
	public function test_pre_planning_camps_are_readable_over_rest() {
		foreach ( \WordCamp_Loader::get_pre_planning_post_statuses() as $status ) {
			$camp = self::factory()->post->create(
				array(
					'post_type'   => WCPT_POST_TYPE_ID,
					'post_status' => $status,
				)
			);

			$this->assertSame( 200, $this->request_camp( $camp )->get_status(), "$status is not readable." );
		}
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
