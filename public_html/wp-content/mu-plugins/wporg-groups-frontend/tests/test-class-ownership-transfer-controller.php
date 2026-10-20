<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;
use WordCamp\Groups\Frontend\Ownership_Transfer\Ownership_Transfer_Controller;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * REST-layer tests for the ownership-transfer controller. The underlying
 * state machine (`WordCamp\Groups\Ownership_Transfer\*`) is covered by
 * `mu-plugins/groups/tests/test-group-ownership-transfer.php`; these tests
 * only exercise routing, permission-callback wiring, and response shaping.
 *
 * @group groups
 */
class Test_Groups_Ownership_Transfer_Controller extends Groups_TestCase {

	/**
	 * @var Ownership_Transfer_Controller
	 */
	protected static $controller;

	/**
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );
		self::$controller = new Ownership_Transfer_Controller();
	}

	/**
	 * Builds a REST request under the wporg-groups/v1 namespace.
	 */
	private function request( string $method, string $route ): WP_REST_Request {
		return new WP_REST_Request( $method, '/wporg-groups/v1' . $route );
	}

	/**
	 * Clears any super-admin override set by an individual test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['super_admins'] );

		parent::tearDown();
	}

	/**
	 * Grant `manage_sites` to a new user.
	 *
	 * `grant_super_admin()` reads `site_admins` out of `sitemeta`, which
	 * `Database_TestCase` truncates; setting the global that
	 * `get_super_admins()` checks first is the fixture-safe equivalent (see
	 * `test-sponsors.php::act_as_network_admin()`). `tearDown()` clears it.
	 *
	 * @return int User ID.
	 */
	private function create_network_admin(): int {
		$user_id = self::factory()->user->create();

		$GLOBALS['super_admins'] = array( get_userdata( $user_id )->user_login ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return $user_id;
	}

	/**
	 * A logged-out visitor cannot view the transfer state.
	 */
	public function test_logged_out_visitor_cannot_view_state() {
		wp_set_current_user( 0 );

		$result = self::$controller->view_permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code() );
	}

	/**
	 * A logged-in user who isn't a member of this group cannot view the state.
	 */
	public function test_non_member_cannot_view_state() {
		$outsider_id = self::factory()->user->create();

		remove_user_from_blog( $outsider_id, self::$groups_root_site_id );
		wp_set_current_user( $outsider_id );

		$result = self::$controller->view_permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 'not_a_member', $result->get_error_code() );
	}

	/**
	 * Membership alone isn't enough: this endpoint reports who is lined up to
	 * replace the owner, and the free-text reason a network admin gave for
	 * rejecting an earlier attempt. Like every other route behind the group
	 * settings UI, it takes `current_user_can_manage_group_settings()`.
	 *
	 * The nominated candidate holds the Organiser (`editor`) tier by
	 * definition — `Transfer\CANDIDATE_ROLE` — so nobody who has something to
	 * do here is shut out by this.
	 */
	public function test_member_without_settings_access_cannot_view_state() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $subscriber_id );

		$result = self::$controller->view_permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );

		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $candidate_id );

		$this->assertTrue(
			self::$controller->view_permissions_check(),
			'A nominated candidate must still be able to accept or decline.'
		);
	}

	/**
	 * A super admin who isn't personally a member of the group is exempt
	 * from the membership requirement — required for the abandoned-group
	 * path, where the network admin initiating on the owner's behalf may
	 * never have joined this particular group.
	 */
	public function test_super_admin_can_view_state_without_membership() {
		$admin_id = self::factory()->user->create();
		remove_user_from_blog( $admin_id, self::$groups_root_site_id );

		$GLOBALS['super_admins'] = array( get_userdata( $admin_id )->user_login ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( $admin_id );

		try {
			$this->assertTrue( self::$controller->view_permissions_check() );
			$this->assertTrue( self::$controller->initiate_permissions_check() );
		} finally {
			unset( $GLOBALS['super_admins'] );
		}
	}

	/**
	 * GET returns eligible candidates (editor tier) and current owners
	 * (administrator tier), and reflects whether the viewer can initiate.
	 */
	public function test_get_item_reports_state() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author_id    = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $owner_id );

		$response = self::$controller->get_item( $this->request( 'GET', '/ownership-transfer' ) );
		$data     = $response->get_data();

		$this->assertNull( $data['pending'] );
		$this->assertTrue( $data['canInitiate'] );
		$this->assertTrue( $data['viewerIsOwner'] );
		$this->assertContains( $owner_id, wp_list_pluck( $data['currentOwners'], 'id' ) );
		$this->assertContains( $candidate_id, wp_list_pluck( $data['eligibleCandidates'], 'id' ) );
		$this->assertNotContains( $author_id, wp_list_pluck( $data['eligibleCandidates'], 'id' ) );
	}

	/**
	 * Only the site's administrator, or a super admin, passes the initiate
	 * permission check.
	 */
	public function test_initiate_permissions_check() {
		$owner_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $owner_id );
		$this->assertTrue( self::$controller->initiate_permissions_check() );

		wp_set_current_user( $editor_id );
		$result = self::$controller->initiate_permissions_check();
		$this->assertWPError( $result );
		$this->assertSame( 'rest_cannot_initiate_transfer', $result->get_error_code() );
	}

	/**
	 * An owner initiating a transfer doesn't need to pass `fromUserId` — it
	 * defaults to them — but a mismatched value is rejected outright.
	 */
	public function test_owner_initiate_defaults_from_user_to_self() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other_id     = self::factory()->user->create();

		wp_set_current_user( $owner_id );

		$request = $this->request( 'POST', '/ownership-transfer/initiate' );
		$request->set_param( 'candidateId', $candidate_id );
		$request->set_param( 'fromUserId', $other_id );

		$mismatch = self::$controller->initiate( $request );
		$this->assertWPError( $mismatch );
		$this->assertSame( 'from_user_mismatch', $mismatch->get_error_code() );

		$request->set_param( 'fromUserId', 0 );
		$response = self::$controller->initiate( $request );

		$this->assertSame( $owner_id, $response->get_data()['pending']['fromUserId'] );
		$this->assertSame( $candidate_id, $response->get_data()['pending']['toUserId'] );
	}

	/**
	 * A super admin initiating on an inactive owner's behalf must specify
	 * `fromUserId` explicitly.
	 */
	public function test_super_admin_initiate_requires_from_user() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$admin_id = self::factory()->user->create();

		// `grant_super_admin()` reads `site_admins` out of `sitemeta`, which
		// `Database_TestCase` truncates; setting the global that
		// `get_super_admins()` checks first is the fixture-safe equivalent
		// (see `test-sponsors.php::act_as_network_admin()`). `tearDown()`
		// clears it.
		$GLOBALS['super_admins'] = array( get_userdata( $admin_id )->user_login ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		wp_set_current_user( $admin_id );

		$request = $this->request( 'POST', '/ownership-transfer/initiate' );
		$request->set_param( 'candidateId', $candidate_id );
		$request->set_param( 'fromUserId', 0 );

		$missing = self::$controller->initiate( $request );
		$this->assertWPError( $missing );
		$this->assertSame( 'from_user_required', $missing->get_error_code() );

		$request->set_param( 'fromUserId', $owner_id );
		$response = self::$controller->initiate( $request );

		$this->assertSame( $owner_id, $response->get_data()['pending']['fromUserId'] );
		$this->assertSame( $admin_id, $response->get_data()['pending']['initiatedBy'] );
	}

	/**
	 * Accept, decline, and cancel each report the state through their own routes.
	 */
	public function test_accept_decline_cancel_report_updated_state() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Accepting emails the network admins for approval, and a network with
		// nobody to approve is a warning-worthy state in its own right -- see
		// `Notifications\warn_no_recipient()`. Give this fixture a network
		// admin so it exercises the ordinary path rather than that alarm.
		$this->create_network_admin();

		wp_set_current_user( $owner_id );
		$request = $this->request( 'POST', '/ownership-transfer/initiate' );
		$request->set_param( 'candidateId', $candidate_id );
		$request->set_param( 'fromUserId', 0 );
		self::$controller->initiate( $request );

		// Wrong user can't accept.
		$bystander_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $bystander_id );
		$wrong_accept = self::$controller->accept( $this->request( 'POST', '/ownership-transfer/accept' ) );
		$this->assertWPError( $wrong_accept );
		$this->assertSame( 'not_the_candidate', $wrong_accept->get_error_code() );

		wp_set_current_user( $candidate_id );
		$accepted = self::$controller->accept( $this->request( 'POST', '/ownership-transfer/accept' ) );
		$this->assertSame( 'pending_approval', $accepted->get_data()['pending']['status'] );

		wp_set_current_user( $owner_id );
		$cancelled = self::$controller->cancel( $this->request( 'POST', '/ownership-transfer/cancel' ) );
		$this->assertNull( $cancelled->get_data()['pending'] );
		$this->assertSame( 'cancelled', $cancelled->get_data()['history'][0]['status'] );
	}
}
