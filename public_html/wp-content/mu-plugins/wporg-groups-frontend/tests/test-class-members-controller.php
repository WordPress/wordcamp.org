<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;
use WordCamp\Groups\Frontend\Members\Members_Controller;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Members_Controller extends Groups_TestCase {

	/**
	 * @var Members_Controller
	 */
	protected static $controller;

	/**
	 * Builds the shared fixture and a single controller instance for these tests.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );
		self::$controller = new Members_Controller();
	}

	/**
	 * Builds a REST request under the wporg-groups/v1 namespace.
	 */
	private function request( string $method, string $route ): WP_REST_Request {
		return new WP_REST_Request( $method, '/wporg-groups/v1' . $route );
	}

	/**
	 * Role labels should map editor/author/subscriber to their group-tier names.
	 */
	public function test_role_labels_map_correctly() {
		$editor     = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$author     = self::factory()->user->create_and_get( array( 'role' => 'author' ) );
		$subscriber = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$editor_request = $this->request( 'GET', '/members/' . $editor->ID );
		$editor_request->set_param( 'id', $editor->ID );
		$response = self::$controller->get_item( $editor_request );
		$this->assertSame( 'Organiser', $response->get_data()['roleLabel'] );

		$author_request = $this->request( 'GET', '/members/' . $author->ID );
		$author_request->set_param( 'id', $author->ID );
		$response = self::$controller->get_item( $author_request );
		$this->assertSame( 'Event Organiser', $response->get_data()['roleLabel'] );

		$subscriber_request = $this->request( 'GET', '/members/' . $subscriber->ID );
		$subscriber_request->set_param( 'id', $subscriber->ID );
		$response = self::$controller->get_item( $subscriber_request );
		$this->assertSame( 'Member', $response->get_data()['roleLabel'] );
	}

	/**
	 * Mirrors the args schema `validate_callback` — administrator is
	 * intentionally excluded from ASSIGNABLE_ROLES so it can never be
	 * set through this endpoint, only via wp-admin directly.
	 */
	public function test_validate_assignable_role_rejects_administrator() {
		$this->assertFalse( self::$controller->validate_assignable_role( 'administrator' ) );
		$this->assertTrue( self::$controller->validate_assignable_role( 'editor' ) );
		$this->assertTrue( self::$controller->validate_assignable_role( 'author' ) );
		$this->assertTrue( self::$controller->validate_assignable_role( 'subscriber' ) );
	}

	/**
	 * Authors (Event Organisers) can manage events but not member roles.
	 */
	public function test_author_blocked_from_role_changes() {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $author_id );

		$request = $this->request( 'POST', "/members/{$member_id}/role" );
		$request->set_param( 'id', $member_id );
		$request->set_param( 'role', 'author' );

		$result = self::$controller->update_member_role_permissions_check( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'rest_cannot_edit_roles', $result->get_error_code() );
	}

	/**
	 * Editors (Organisers) can promote a member to Event Organiser.
	 */
	public function test_editor_promotes_subscriber_to_author() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $editor_id );

		$request = $this->request( 'POST', "/members/{$member_id}/role" );
		$request->set_param( 'id', $member_id );
		$request->set_param( 'role', 'author' );

		$permission = self::$controller->update_member_role_permissions_check( $request );
		$this->assertTrue( $permission );

		self::$controller->update_member_role( $request );

		$this->assertContains( 'author', get_userdata( $member_id )->roles );
	}

	/**
	 * No one can change their own group role, even an Organiser.
	 */
	public function test_cannot_change_own_role() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$request = $this->request( 'POST', "/members/{$editor_id}/role" );
		$request->set_param( 'id', $editor_id );
		$request->set_param( 'role', 'author' );

		$result = self::$controller->update_member_role( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'cannot_change_own_role', $result->get_error_code() );
	}

	/**
	 * A group must always keep at least one organiser.
	 */
	public function test_cannot_remove_last_organizer() {
		// Clear any organisers left over from fixture/site creation so the
		// count below is deterministic.
		$existing_organizers = get_users(
			array(
				'blog_id'  => self::$groups_root_site_id,
				'role__in' => array( 'administrator', 'editor' ),
				'fields'   => 'ids',
			)
		);
		foreach ( $existing_organizers as $uid ) {
			remove_user_from_blog( $uid, self::$groups_root_site_id );
		}

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// A second organiser performs the (attempted) demotion so this isn't
		// also blocked by the separate "can't change your own role" check.
		$actor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $actor_id )->set_role( 'subscriber' );

		wp_set_current_user( $actor_id );

		$request = $this->request( 'POST', "/members/{$editor_id}/role" );
		$request->set_param( 'id', $editor_id );
		$request->set_param( 'role', 'subscriber' );

		$result = self::$controller->update_member_role( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'cannot_remove_last_organizer', $result->get_error_code() );
	}

	/**
	 * A logged-in non-member can join the group.
	 */
	public function test_join_group() {
		$member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		remove_user_from_blog( $member_id, self::$groups_root_site_id );

		wp_set_current_user( $member_id );

		$response = self::$controller->join_group( $this->request( 'POST', '/members/join' ) );

		$this->assertTrue( $response->get_data()['success'] );
		$this->assertTrue( is_user_member_of_blog( $member_id, self::$groups_root_site_id ) );
	}

	/**
	 * Organisers must be demoted before they can leave the group.
	 */
	public function test_organiser_cannot_leave_without_demotion() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$response = self::$controller->leave_group( $this->request( 'DELETE', '/members/leave' ) );

		$this->assertWPError( $response );
		$this->assertSame( 'cannot_leave', $response->get_error_code() );
	}

	/**
	 * MAX_PER_PAGE caps the collection regardless of requested per_page,
	 * so a public listing can't be used to pull the entire user table.
	 */
	public function test_members_collection_per_page_is_capped() {
		$this->assertFalse( self::$controller->validate_per_page( Members_Controller::MAX_PER_PAGE + 1 ) );
		$this->assertTrue( self::$controller->validate_per_page( Members_Controller::MAX_PER_PAGE ) );
	}
}
