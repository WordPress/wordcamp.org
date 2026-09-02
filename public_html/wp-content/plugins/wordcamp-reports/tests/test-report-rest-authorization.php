<?php
/**
 * Authorization and field-contract tests for the report REST routes.
 *
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Tests;

use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WP_REST_Request;
use WordCamp\Reports\Report\CampusConnect_Details;
use function WordCamp\Reports\default_rest_permission_callback;
use const WordCamp\Reports\CAPABILITY;

defined( 'WPINC' ) || die();

/**
 * Report REST routes read private post meta and non-public post statuses, so
 * they must be unreachable without the reports capability, and reachable with
 * nothing more than it.
 *
 * The success cases assert against a real fixture rather than an empty result
 * set: with no `campusconnect` posts, `get_event_posts()` returns `[]` and a
 * success assertion degrades to `assertIsArray( [] )`, which would still pass
 * if `E-mail Address` were added to the published field list.
 *
 * @group wordcamp-reports
 *
 * @covers \WordCamp\Reports\Report\CampusConnect_Details::rest_callback
 * @covers \WordCamp\Reports\Report\CampusConnect_Details::rest_permission_callback
 * @covers \WordCamp\Reports\Report\CampusConnect_Details::get_rest_fields
 * @covers \WordCamp\Reports\default_rest_permission_callback
 */
class Test_Report_Rest_Authorization extends WP_UnitTestCase {
	/**
	 * The route under test.
	 *
	 * @var string
	 */
	const ROUTE = '/wordcamp-reports/v1/campus-connect-details';

	/**
	 * Contact details seeded on the fixture that must never be published.
	 *
	 * @var string
	 */
	const EMAIL_CANARY = 'canary@example.test';

	/**
	 * @var string
	 */
	const PHONE_CANARY = '555-0100-canary';

	/**
	 * A user with no report capability.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * The Campus Connect event fixture.
	 *
	 * @var int
	 */
	protected static $event_id;

	/**
	 * Whether the caller should also be treated as an administrator.
	 *
	 * @var bool
	 */
	protected $grant_manage_options = false;

	/**
	 * Set up shared fixtures.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );

		/*
		 * A non-public status if wcpt registered one, so the fixture also proves
		 * the endpoint reaches events the public API cannot see. `post_status`
		 * falls back to draft, which `post_status => 'any'` still matches.
		 */
		$status = get_post_status_object( 'wcpt-pre-planning' ) ? 'wcpt-pre-planning' : 'draft';

		self::$event_id = $factory->post->create( array(
			'post_type'   => defined( 'WCPT_POST_TYPE_ID' ) ? WCPT_POST_TYPE_ID : 'wordcamp',
			'post_title'  => 'WordPress Campus Connect Test University',
			'post_status' => $status,
		) );

		update_post_meta( self::$event_id, 'event_subtype', 'campusconnect' );
		update_post_meta( self::$event_id, 'Venue Name', 'Test University' );
		update_post_meta( self::$event_id, '_venue_city', 'Cape Town' );
		update_post_meta( self::$event_id, '_venue_country_name', 'South Africa' );
		update_post_meta( self::$event_id, 'Number of Anticipated Attendees', '120' );
		update_post_meta( self::$event_id, 'Series Event', 1 );

		// Canaries: private fields that are in the safelist but must not ship.
		update_post_meta( self::$event_id, 'E-mail Address', self::EMAIL_CANARY );
		update_post_meta( self::$event_id, 'Telephone', self::PHONE_CANARY );
	}

	/**
	 * Remove the shared fixtures.
	 */
	public static function wpTearDownAfterClass(): void {
		wp_delete_post( self::$event_id, true );
	}

	/**
	 * Start each test anonymous, with the REST server initialised.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( 0 );
		$this->grant_manage_options = false;

		// Force the routes to register for this request.
		rest_get_server();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		remove_filter( 'user_has_cap', array( $this, 'grant_caps' ), 10 );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Grant the reports capability, and optionally `manage_options`.
	 *
	 * @param bool[] $allcaps All capabilities for the user.
	 *
	 * @return bool[]
	 */
	public function grant_caps( $allcaps ) {
		$allcaps[ CAPABILITY ] = true;

		if ( $this->grant_manage_options ) {
			$allcaps['manage_options'] = true;
		}

		return $allcaps;
	}

	/**
	 * Sign in as a subscriber holding only the reports capability.
	 */
	protected function act_as_report_viewer() {
		wp_set_current_user( self::$subscriber_id );
		add_filter( 'user_has_cap', array( $this, 'grant_caps' ) );
	}

	/**
	 * Sign in as a caller who also holds `manage_options`.
	 */
	protected function act_as_administrator() {
		$this->grant_manage_options = true;
		wp_set_current_user( self::$subscriber_id );
		add_filter( 'user_has_cap', array( $this, 'grant_caps' ) );
	}

	/**
	 * Dispatch a GET against the route under test.
	 *
	 * @return \WP_REST_Response
	 */
	protected function dispatch() {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );
	}

	/**
	 * The fixture row from a successful response.
	 *
	 * @param \WP_REST_Response $response The response to read.
	 *
	 * @return array
	 */
	protected function fixture_row( $response ) {
		$data = $response->get_data();

		$this->assertArrayHasKey( 'data', $data, 'Response is not wrapped by prepare_rest_response().' );
		$this->assertNotEmpty( $data['data'], 'No rows returned; the fixture did not reach the report.' );

		foreach ( $data['data'] as $row ) {
			if ( isset( $row['ID'] ) && (int) $row['ID'] === self::$event_id ) {
				return $row;
			}
		}

		$this->fail( 'The Campus Connect fixture was not present in the response.' );
	}

	/**
	 * The route exists at all. Guards against a silent registration regression,
	 * which would otherwise make every assertion below pass as a 404.
	 */
	public function test_route_is_registered() {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	/**
	 * An anonymous caller must not reach the report.
	 */
	public function test_denies_anonymous_caller() {
		$this->assertSame( 401, $this->dispatch()->get_status() );
	}

	/**
	 * Being logged in is not enough; the capability is what gates the route.
	 */
	public function test_denies_user_without_capability() {
		wp_set_current_user( self::$subscriber_id );

		$this->assertSame( 403, $this->dispatch()->get_status() );
	}

	/**
	 * A user holding nothing but `view_wordcamp_reports` -- the `report_viewer`
	 * subrole -- must actually receive the report, not a 500.
	 */
	public function test_allows_user_with_only_the_report_capability() {
		$this->act_as_report_viewer();

		$response = $this->dispatch();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->fixture_row( $response ) );
	}

	/**
	 * The published field set is exactly what `get_rest_fields()` promises, less
	 * the fields this caller's capability withholds. Asserting the whole key set
	 * -- rather than probing for known-bad keys -- is what makes adding a private
	 * field to the list fail here.
	 */
	public function test_report_viewer_receives_exactly_the_published_fields() {
		$this->act_as_report_viewer();

		$row = $this->fixture_row( $this->dispatch() );

		$expected = array_values( array_diff( CampusConnect_Details::get_rest_fields(), array( 'Series Event' ) ) );

		sort( $expected );
		$actual = array_keys( $row );
		sort( $actual );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * `Series Event` is dropped for a caller without `manage_options`, and the
	 * intersection has to notice that rather than failing the whole request.
	 */
	public function test_capability_gated_field_is_withheld_from_a_report_viewer() {
		$this->act_as_report_viewer();

		$this->assertArrayNotHasKey( 'Series Event', $this->fixture_row( $this->dispatch() ) );
	}

	/**
	 * The counterpart: a caller who does hold `manage_options` gets it.
	 */
	public function test_capability_gated_field_is_present_for_an_administrator() {
		$this->act_as_administrator();

		$this->assertArrayHasKey( 'Series Event', $this->fixture_row( $this->dispatch() ) );
	}

	/**
	 * Contact details sit in the private safelist, so nothing but the explicit
	 * field list keeps them off the wire. Check the whole serialised response,
	 * not just the row keys, so a value leaking through some other field fails.
	 */
	public function test_contact_details_never_reach_the_response() {
		foreach ( array( 'report_viewer', 'administrator' ) as $persona ) {
			$this->tear_down_persona();

			if ( 'report_viewer' === $persona ) {
				$this->act_as_report_viewer();
			} else {
				$this->act_as_administrator();
			}

			$response = $this->dispatch();
			$this->assertSame( 200, $response->get_status(), "$persona did not get a 200." );

			$row = $this->fixture_row( $response );
			$this->assertArrayNotHasKey( 'E-mail Address', $row, "$persona received an e-mail column." );
			$this->assertArrayNotHasKey( 'Telephone', $row, "$persona received a telephone column." );

			$serialised = wp_json_encode( $response->get_data() );
			$this->assertStringNotContainsString( self::EMAIL_CANARY, $serialised, "$persona: e-mail canary leaked." );
			$this->assertStringNotContainsString( self::PHONE_CANARY, $serialised, "$persona: telephone canary leaked." );
		}
	}

	/**
	 * Reset the capability filter between personas inside a single test.
	 */
	protected function tear_down_persona() {
		remove_filter( 'user_has_cap', array( $this, 'grant_caps' ), 10 );
		$this->grant_manage_options = false;
		wp_set_current_user( 0 );
	}

	/**
	 * The published field list has to stay a subset of the report's own field
	 * order, or the two drift with nothing catching it.
	 */
	public function test_published_fields_are_a_subset_of_the_field_order() {
		$this->assertSame(
			array(),
			array_diff( CampusConnect_Details::get_rest_fields(), CampusConnect_Details::get_field_order() )
		);
	}

	/**
	 * `Tracker URL` is `null` for any caller who cannot edit the post, which is
	 * the audience this endpoint exists for, so it is deliberately unpublished.
	 */
	public function test_tracker_url_is_not_published() {
		$this->assertNotContains( 'Tracker URL', CampusConnect_Details::get_rest_fields() );
	}

	/**
	 * The fallback used by any future report that declares `$rest_base` without
	 * its own permission callback. No route uses it today, so nothing else
	 * exercises the line that keeps those routes from being public.
	 */
	public function test_default_permission_callback_requires_the_capability() {
		wp_set_current_user( 0 );
		$this->assertFalse( default_rest_permission_callback() );

		wp_set_current_user( self::$subscriber_id );
		$this->assertFalse( default_rest_permission_callback() );

		$this->act_as_report_viewer();
		$this->assertTrue( default_rest_permission_callback() );
	}
}
