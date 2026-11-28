<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use WP_REST_Request;
use WordCamp\Groups\Frontend\Members\Members_Controller;

use function WordCamp\Groups\Frontend\Member_Bans\ban;
use function WordCamp\Groups\Frontend\Member_Bans\check_rsvp;
use function WordCamp\Groups\Frontend\Member_Bans\guard_rsvp_routes;
use function WordCamp\Groups\Frontend\Member_Bans\is_banned;
use function WordCamp\Groups\Frontend\REST\save_rsvp;

use const WordCamp\Groups\Frontend\Member_Bans\GUARDED_RSVP_ROUTES;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Banning members from a group (#2395).
 *
 * @group groups
 */
class Test_Groups_Member_Bans extends Groups_TestCase {

	/**
	 * An organizer, who removes and bans.
	 *
	 * @var int
	 */
	private $organizer_id;

	/**
	 * A member, who gets banned.
	 *
	 * @var int
	 */
	private $member_id;

	/**
	 * Creates the organizer and the member.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->organizer_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->member_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * A REST request under the wporg-groups/v1 namespace.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route after the namespace.
	 */
	private function request( string $method, string $route ): WP_REST_Request {
		return new WP_REST_Request( $method, '/wporg-groups/v1' . $route );
	}

	/**
	 * Remove and ban the member as the organizer.
	 */
	private function remove_and_ban(): \WP_REST_Response {
		wp_set_current_user( $this->organizer_id );

		$request = $this->request( 'DELETE', "/members/{$this->member_id}" );
		$request->set_query_params( array( 'ban' => '1' ) );

		return rest_do_request( $request );
	}

	/**
	 * A published event starting in a week.
	 */
	private function create_event(): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * Render a block on an event page.
	 *
	 * @param string $markup   Block markup.
	 * @param int    $event_id Event post ID.
	 */
	private function render_on_event( string $markup, int $event_id ): string {
		global $post;

		$post = get_post( $event_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$output = (string) do_blocks( $markup );

		wp_reset_postdata();

		return $output;
	}

	/**
	 * Removing with `ban` removes the member, bans them, lists them as banned
	 * and logs both.
	 */
	public function test_remove_with_ban() {
		$response = $this->remove_and_ban();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['banned'] );
		$this->assertFalse( is_user_member_of_blog( $this->member_id ) );
		$this->assertTrue( is_banned( $this->member_id ) );

		$banned = rest_do_request( $this->request( 'GET', '/members/banned' ) )->get_data();
		$this->assertCount( 1, $banned );
		$this->assertSame( $this->member_id, $banned[0]['id'] );
		$this->assertSame( Members_Controller::member_name( get_userdata( $this->organizer_id ) ), $banned[0]['bannedBy'] );

		$actions = wp_list_pluck( \WordCamp\Groups\Action_Log\get_entries()['entries'], 'action' );
		$this->assertContains( 'member_banned', $actions );
		$this->assertContains( 'member_removed', $actions );
	}

	/**
	 * Removing without `ban` doesn't ban.
	 */
	public function test_remove_without_ban() {
		wp_set_current_user( $this->organizer_id );

		$response = rest_do_request( $this->request( 'DELETE', "/members/{$this->member_id}" ) );

		$this->assertFalse( $response->get_data()['banned'] );
		$this->assertFalse( is_banned( $this->member_id ) );
	}

	/**
	 * A ban applies to one group only.
	 */
	public function test_ban_is_scoped_to_the_group() {
		$this->remove_and_ban();

		$this->assertTrue( is_banned( $this->member_id, get_current_blog_id() ) );
		$this->assertFalse( is_banned( $this->member_id, get_current_blog_id() + 1000 ) );
	}

	/**
	 * A banned person can't join, through the join route or any other call to
	 * `add_user_to_blog()`, such as the Meetup import or wp-admin.
	 */
	public function test_banned_person_cannot_join() {
		$this->remove_and_ban();

		wp_set_current_user( $this->member_id );
		$response = rest_do_request( $this->request( 'POST', '/members/join' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wporg_groups_banned', $response->get_data()['code'] );

		$added = add_user_to_blog( get_current_blog_id(), $this->member_id, 'subscriber' );
		$this->assertWPError( $added );
		$this->assertFalse( is_user_member_of_blog( $this->member_id ) );
	}

	/**
	 * A banned person's RSVP to attend is refused on our route, and no RSVP is saved.
	 */
	public function test_banned_person_cannot_rsvp() {
		$event_id = $this->create_event();
		$this->remove_and_ban();

		wp_set_current_user( $this->member_id );

		$request = $this->request( 'POST', "/event/{$event_id}/rsvp" );
		$request->set_param( 'id', $event_id );
		$request->set_param( 'status', 'attending' );
		$request->set_param( 'answers', array() );

		$response = save_rsvp( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'wporg_groups_banned', $response->get_error_code() );
		$this->assertSame( 0, (int) ( ( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->responses()['attending']['count'] ?? 0 ) );
		$this->assertFalse( is_user_member_of_blog( $this->member_id ) );
	}

	/**
	 * GatherPress's route and the recurring events route refuse a banned
	 * person's RSVP to attend, after their own checks, and let cancelling through.
	 */
	public function test_other_rsvp_routes_are_guarded() {
		$this->remove_and_ban();
		wp_set_current_user( $this->member_id );

		$endpoints = array();
		foreach ( GUARDED_RSVP_ROUTES as $route ) {
			$endpoints[ $route ] = array(
				array( 'permission_callback' => '__return_true' ),
			);
		}

		$guarded = guard_rsvp_routes( $endpoints );

		foreach ( GUARDED_RSVP_ROUTES as $route ) {
			$callback = $guarded[ $route ][0]['permission_callback'];

			$attend = new WP_REST_Request( 'POST', $route );
			$attend->set_param( 'status', 'attending' );
			$this->assertWPError( $callback( $attend ), $route );

			$cancel = new WP_REST_Request( 'POST', $route );
			$cancel->set_param( 'status', 'not_attending' );
			$this->assertTrue( $callback( $cancel ), $route );
		}

		$denied = guard_rsvp_routes(
			array( GUARDED_RSVP_ROUTES[0] => array( array( 'permission_callback' => '__return_false' ) ) )
		);
		$this->assertFalse( $denied[ GUARDED_RSVP_ROUTES[0] ][0]['permission_callback']( new WP_REST_Request() ) );
	}

	/**
	 * The real routes are wrapped once they're registered.
	 */
	public function test_registered_gatherpress_route_is_guarded() {
		$this->remove_and_ban();
		wp_set_current_user( $this->member_id );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( GUARDED_RSVP_ROUTES[0], $routes );

		$request = new WP_REST_Request( 'POST', GUARDED_RSVP_ROUTES[0] );
		$request->set_param( 'status', 'attending' );
		$request->set_param( 'post_id', $this->create_event() );

		$result = call_user_func( $routes[ GUARDED_RSVP_ROUTES[0] ][0]['permission_callback'], $request );

		$this->assertWPError( $result );
	}

	/**
	 * Cancelling is never refused for a ban.
	 */
	public function test_cancelling_is_allowed() {
		ban( $this->member_id, $this->organizer_id );

		$this->assertTrue( check_rsvp( $this->member_id, 'not_attending' ) );
		$this->assertWPError( check_rsvp( $this->member_id, 'attending' ) );
	}

	/**
	 * Lifting a ban lets the person join again but doesn't add them back.
	 */
	public function test_unban() {
		$this->remove_and_ban();

		$response = rest_do_request( $this->request( 'DELETE', "/members/banned/{$this->member_id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( is_banned( $this->member_id ) );
		$this->assertFalse( is_user_member_of_blog( $this->member_id ) );
		$this->assertSame( 'member_unbanned', \WordCamp\Groups\Action_Log\get_entries()['entries'][0]['action'] );
		$this->assertSame( array(), rest_do_request( $this->request( 'GET', '/members/banned' ) )->get_data() );

		$again = rest_do_request( $this->request( 'DELETE', "/members/banned/{$this->member_id}" ) );
		$this->assertSame( 404, $again->get_status() );

		wp_set_current_user( $this->member_id );
		$join = rest_do_request( $this->request( 'POST', '/members/join' ) );
		$this->assertSame( 200, $join->get_status() );
		$this->assertTrue( is_user_member_of_blog( $this->member_id ) );
	}

	/**
	 * Event Organizers and members can't see or change bans.
	 */
	public function test_only_organizers_manage_bans() {
		ban( $this->member_id, $this->organizer_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertSame( 403, rest_do_request( $this->request( 'GET', '/members/banned' ) )->get_status() );
		$this->assertSame( 403, rest_do_request( $this->request( 'DELETE', "/members/banned/{$this->member_id}" ) )->get_status() );
		$this->assertTrue( is_banned( $this->member_id ) );
	}

	/**
	 * A banned person sees "You can't join this group." in place of Join and
	 * of the RSVP button.
	 */
	public function test_blocks_tell_a_banned_person() {
		$event_id = $this->create_event();
		$this->remove_and_ban();

		wp_set_current_user( $this->member_id );

		$membership = $this->render_on_event( '<!-- wp:wporg/group-membership {"variant":"membership"} /-->', $event_id );
		$this->assertStringContainsString( 'You can&#039;t join this group.', $membership );
		$this->assertStringNotContainsString( 'actions.join', $membership );

		$rsvp = $this->render_on_event( '<!-- wp:wporg/event-rsvp /-->', $event_id );
		$this->assertStringContainsString( 'wporg-event-rsvp__banned', $rsvp );
		$this->assertStringNotContainsString( 'actions.handleRsvpButton', $rsvp );
		$this->assertStringNotContainsString( 'wporg-event-rsvp__modal-rsvp-btn', $rsvp );
	}
}
