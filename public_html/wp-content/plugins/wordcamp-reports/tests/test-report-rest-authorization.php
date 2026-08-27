<?php
/**
 * Authorization tests for the report REST routes.
 *
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Tests;

use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WP_REST_Request;
use WordCamp\Reports\Report\CampusConnect_Details;
use const WordCamp\Reports\CAPABILITY;

defined( 'WPINC' ) || die();

/**
 * Report REST routes read private post meta and non-public post statuses, so
 * they must be unreachable without the reports capability, and reachable with
 * nothing more than it.
 *
 * The success case is load-bearing for a second reason: a report that asks for
 * a field the caller's capability-filtered safelist does not contain fails in
 * `validate_fields_input()` and returns a 500, which a permission-only test
 * would not notice.
 *
 * @group wordcamp-reports
 *
 * @covers \WordCamp\Reports\Report\CampusConnect_Details::rest_callback
 * @covers \WordCamp\Reports\Report\CampusConnect_Details::rest_permission_callback
 */
class Test_Report_Rest_Authorization extends WP_UnitTestCase {
	/**
	 * The route under test.
	 *
	 * @var string
	 */
	const ROUTE = '/wordcamp-reports/v1/campus-connect-details';

	/**
	 * A user with no report capability.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * Set up shared fixtures.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$subscriber_id = $factory->user->create( array(
			'role' => 'subscriber',
		) );
	}

	/**
	 * Start each test anonymous, with the REST server initialised.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( 0 );

		// Force the routes to register for this request.
		rest_get_server();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		remove_filter( 'user_has_cap', array( $this, 'grant_report_cap' ), 10 );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Grant the reports capability to the current user.
	 *
	 * @param bool[] $allcaps All capabilities for the user.
	 *
	 * @return bool[]
	 */
	public function grant_report_cap( $allcaps ) {
		$allcaps[ CAPABILITY ] = true;

		return $allcaps;
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
		$response = $this->dispatch();

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Being logged in is not enough; the capability is what gates the route.
	 */
	public function test_denies_user_without_capability() {
		wp_set_current_user( self::$subscriber_id );

		$response = $this->dispatch();

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A user holding nothing but `view_wordcamp_reports` -- the `report_viewer`
	 * subrole -- must actually receive the report, not a 500.
	 */
	public function test_allows_user_with_only_the_report_capability() {
		wp_set_current_user( self::$subscriber_id );
		add_filter( 'user_has_cap', array( $this, 'grant_report_cap' ) );

		$response = $this->dispatch();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * The reason the success case above can regress: `Series Event` is stripped
	 * from the safelist for anyone without `manage_options`, so the endpoint's
	 * fixed field list has to be intersected with what the caller can be given.
	 */
	public function test_capability_gated_field_is_absent_from_a_report_only_safelist() {
		wp_set_current_user( self::$subscriber_id );
		add_filter( 'user_has_cap', array( $this, 'grant_report_cap' ) );

		$report    = new CampusConnect_Details( null, null, false, array( 'public' => false ) );
		$available = array_keys( $report->get_data_fields_safelist() );

		$this->assertNotContains( 'Series Event', $available );
		$this->assertContains( 'Actual Attendees', $available );
		$this->assertNotContains( 'Series Event', array_intersect( CampusConnect_Details::get_rest_fields(), $available ) );
	}
}
