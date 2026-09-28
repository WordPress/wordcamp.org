<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Authorization matrix for every front-end REST route.
 *
 * The rest of this suite checks permission callbacks by calling them (or the
 * capability functions behind them) directly, and checks controllers by
 * calling their handler methods directly — see Test_Groups_Members_Controller,
 * which never dispatches. That leaves the join between the two untested: a
 * route registered without its permission callback, or with the wrong one,
 * passes every existing test in this suite.
 *
 * So everything here goes through `rest_do_request()`, which is the only way
 * to exercise route registration, the args schema, and the permission
 * callback together, the way a real request does.
 *
 * Reading the table: each case is one route, one actor, one expected HTTP
 * status. The expectations are transcribed from the callbacks as they behave
 * today, not from what they arguably should do — see
 * `test_non_member_status_is_inconsistent_across_member_routes()` for a case
 * where today's behaviour is not self-consistent.
 *
 * @group groups
 */
class Test_Groups_REST_Authorization extends Groups_TestCase {

	/**
	 * Actor user IDs, keyed by the names used in the matrix below.
	 *
	 * @var array<string, int>
	 */
	private $actors = array();

	/**
	 * A published event owned by the `organiser` actor.
	 *
	 * @var int
	 */
	private $event_id = 0;

	/**
	 * A draft event owned by the `organiser` actor.
	 *
	 * @var int
	 */
	private $draft_id = 0;

	/**
	 * Builds one user per role tier plus an event and a draft owned by the
	 * organiser.
	 *
	 * The fixtures are deliberately owned by `organiser` rather than by
	 * whoever is making the request: the interesting cell in the matrix is an
	 * Event Organiser (author) reaching for someone else's event, which is
	 * what `current_user_can_edit_event()` is there to stop.
	 */
	protected function setUp(): void {
		parent::setUp();

		// `$wp_rest_server` is rebuilt per test by the core test case, so the
		// routes have to be registered against this request's server.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->actors = array(
			'member'          => self::factory()->user->create( array( 'role' => 'subscriber' ) ),
			'event_organiser' => self::factory()->user->create( array( 'role' => 'author' ) ),
			'organiser'       => self::factory()->user->create( array( 'role' => 'editor' ) ),
			'outsider'        => self::factory()->user->create( array( 'role' => 'subscriber' ) ),
		);

		// An "outsider" is logged in but has no role on *this* group's site —
		// the shape of a member of another group poking at this one. On
		// multisite that is the absence of a capabilities row, not a role.
		remove_user_from_blog( $this->actors['outsider'], get_current_blog_id() );

		$this->event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_author' => $this->actors['organiser'],
				'post_title'  => 'Authorization Matrix Event',
			)
		);

		$this->draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
				'post_author' => $this->actors['organiser'],
				'post_title'  => 'Authorization Matrix Draft',
			)
		);
	}

	/**
	 * Builds the request for a matrix row.
	 *
	 * Allowed cells are given valid parameters so that a 2xx means the
	 * handler actually ran, rather than the request dying in arg validation
	 * and leaving the permission callback unproven.
	 */
	private function build_request( string $route_key ): WP_REST_Request {
		$future = current_datetime()->modify( '+1 week' )->format( 'Y-m-d' );

		$event_payload = array(
			'title'      => 'Authorization Matrix Submission',
			'date'       => $future,
			'time_start' => '18:00',
			'time_end'   => '20:00',
		);

		switch ( $route_key ) {
			case 'GET /event-form-data':
				return new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );

			case 'GET /event-form-data (someone else\'s event)':
				$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
				$request->set_param( 'event_id', $this->event_id );
				return $request;

			case 'POST /event':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event' );
				$request->set_body_params( $event_payload );
				return $request;

			case 'POST /event/{id}':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event/' . $this->event_id );
				$request->set_body_params( $event_payload );
				return $request;

			case 'POST /event/{id}/rsvp':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event/' . $this->event_id . '/rsvp' );
				$request->set_body_params( array( 'status' => 'attending' ) );
				return $request;

			case 'GET /drafts':
				return new WP_REST_Request( 'GET', '/wporg-groups/v1/drafts' );

			case 'POST /draft':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft' );
				$request->set_body_params( $event_payload );
				return $request;

			case 'POST /draft/{id}':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft/' . $this->draft_id );
				$request->set_body_params( $event_payload );
				return $request;

			case 'POST /draft/{id}/publish':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft/' . $this->draft_id . '/publish' );
				$request->set_body_params( $event_payload );
				return $request;

			case 'GET /group-info':
				return new WP_REST_Request( 'GET', '/wporg-groups/v1/group-info' );

			case 'POST /group-info':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/group-info' );
				$request->set_body_params( array( 'title' => 'Authorization Matrix Group' ) );
				return $request;

			case 'GET /members':
				return new WP_REST_Request( 'GET', '/wporg-groups/v1/members' );

			case 'GET /members/{id}':
				return new WP_REST_Request( 'GET', '/wporg-groups/v1/members/' . $this->actors['member'] );

			case 'POST /members/{id}/role':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/members/' . $this->actors['member'] . '/role' );
				$request->set_body_params( array( 'role' => 'author' ) );
				return $request;

			case 'POST /members/me/role':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/members/me/role' );
				$request->set_body_params( array( 'role' => 'author' ) );
				return $request;

			case 'POST /members/join':
				return new WP_REST_Request( 'POST', '/wporg-groups/v1/members/join' );

			case 'DELETE /members/leave':
				return new WP_REST_Request( 'DELETE', '/wporg-groups/v1/members/leave' );

			case 'POST /members/notification-preference':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/members/notification-preference' );
				$request->set_body_params( array( 'opt_in' => true ) );
				return $request;

			case 'GET /ownership-transfer':
				return new WP_REST_Request( 'GET', '/wporg-groups/v1/ownership-transfer' );

			case 'POST /ownership-transfer/initiate':
				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/ownership-transfer/initiate' );
				$request->set_body_params( array( 'user_id' => $this->actors['organiser'] ) );
				return $request;

			case 'POST /ownership-transfer/accept':
				return new WP_REST_Request( 'POST', '/wporg-groups/v1/ownership-transfer/accept' );

			case 'POST /ownership-transfer/decline':
				return new WP_REST_Request( 'POST', '/wporg-groups/v1/ownership-transfer/decline' );

			case 'POST /ownership-transfer/cancel':
				return new WP_REST_Request( 'POST', '/wporg-groups/v1/ownership-transfer/cancel' );

			case 'GET /export':
				return new WP_REST_Request( 'GET', '/wporg-groups/v1/export' );
		}

		$this->fail( "No request builder for route '{$route_key}'." );
	}

	/**
	 * Every route, every actor, and the status each one gets today.
	 *
	 * `DENIED` means "this actor must not reach the handler" — the exact code
	 * is asserted too, since 401 and 403 are not interchangeable to a client
	 * deciding whether to offer a login link.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public function data_authorization_matrix(): array {
		// Route => [ actor => expected status ]. Anonymous is always first so
		// the 401/403 split stays visible while reading down a column.
		$matrix = array(
			// Event management: gated on EVENT_MANAGER_ROLES (author and up).
			'GET /event-form-data' => array(
				'anonymous' => 401,
				'member'    => 403,
				// An Event Organiser may create their own events.
				'event_organiser' => 200,
				'organiser'       => 200,
				'outsider'        => 403,
			),
			'GET /event-form-data (someone else\'s event)' => array(
				'anonymous' => 401,
				'member'    => 403,
				// Reading the form for an event they cannot edit is refused,
				// which is what keeps one organiser out of another's draft.
				'event_organiser' => 403,
				'organiser'       => 200,
				'outsider'        => 403,
			),
			'POST /event' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 200,
				'organiser'       => 200,
				'outsider'        => 403,
			),
			'POST /event/{id}' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'organiser'       => 200,
				'outsider'        => 403,
			),

			// Drafts.
			'GET /drafts' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 200,
				'organiser'       => 200,
				'outsider'        => 403,
			),
			'POST /draft' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 200,
				'organiser'       => 200,
				'outsider'        => 403,
			),
			'POST /draft/{id}' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'organiser'       => 200,
				'outsider'        => 403,
			),
			'POST /draft/{id}/publish' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'organiser'       => 200,
				'outsider'        => 403,
			),

			// Group settings: gated on `edit_others_posts`, so an Event
			// Organiser is deliberately below the bar here even though they
			// clear it for their own events above.
			'GET /group-info' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'organiser'       => 200,
				'outsider'        => 403,
			),
			'POST /group-info' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'organiser'       => 200,
				'outsider'        => 403,
			),

			// Role changes: both `manage_events` AND `manage_group_settings`.
			'POST /members/{id}/role' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'organiser'       => 200,
				'outsider'        => 403,
			),

			// Ownership transfer.
			'GET /ownership-transfer' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'organiser'       => 200,
				'outsider'        => 403,
			),
			'POST /ownership-transfer/accept' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'outsider'        => 403,
			),
			'POST /ownership-transfer/decline' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'outsider'        => 403,
			),
			'POST /ownership-transfer/cancel' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'outsider'        => 403,
			),

			// Export: the one route that already had denial coverage, kept
			// here so the matrix is the complete picture rather than most of
			// it. See Test_Groups_Export for the payload-level assertions.
			'GET /export' => array(
				'anonymous'       => 401,
				'member'          => 403,
				'event_organiser' => 403,
				'organiser'       => 200,
				'outsider'        => 403,
			),

			// Membership: logged-in, not role-gated.
			'POST /members/notification-preference' => array(
				'anonymous'       => 401,
				'member'          => 200,
				'event_organiser' => 200,
				'organiser'       => 200,
				// 403 rather than 400, unlike DELETE /members/leave below.
				'outsider'        => 403,
			),
			'DELETE /members/leave' => array(
				'anonymous' => 401,
				// 400 rather than 403 for the same "not a member" condition.
				'outsider'  => 400,
			),
		);

		$cases = array();

		foreach ( $matrix as $route_key => $expectations ) {
			foreach ( $expectations as $actor => $expected_status ) {
				$cases[ "{$route_key} as {$actor}" ] = array( $route_key, $actor, $expected_status );
			}
		}

		return $cases;
	}

	/**
	 * @dataProvider data_authorization_matrix
	 */
	public function test_authorization_matrix( string $route_key, string $actor, int $expected_status ): void {
		wp_set_current_user( 'anonymous' === $actor ? 0 : $this->actors[ $actor ] );

		$response = rest_do_request( $this->build_request( $route_key ) );

		$this->assertSame(
			$expected_status,
			$response->get_status(),
			sprintf(
				'%s as %s should respond %d, got %d (%s).',
				$route_key,
				$actor,
				$expected_status,
				$response->get_status(),
				is_array( $response->get_data() ) && isset( $response->get_data()['code'] )
					? $response->get_data()['code']
					: 'no error code'
			)
		);
	}

	/**
	 * A denied request must not be a 404 in disguise.
	 *
	 * `rest_do_request()` answers an unregistered route with
	 * `rest_no_route` / 404. Every 401 and 403 in the matrix above would
	 * still be "not 200" if the route silently stopped being registered, so
	 * this asserts each one is a real authorization refusal.
	 */
	public function test_denials_are_authorization_errors_not_missing_routes(): void {
		foreach ( $this->data_authorization_matrix() as $case ) {
			list( $route_key, $actor, $expected_status ) = $case;

			if ( $expected_status < 400 ) {
				continue;
			}

			wp_set_current_user( 'anonymous' === $actor ? 0 : $this->actors[ $actor ] );

			$data = rest_do_request( $this->build_request( $route_key ) )->get_data();

			$this->assertIsArray( $data, "{$route_key} as {$actor} should return an error payload." );
			$this->assertArrayHasKey( 'code', $data );
			$this->assertNotSame(
				'rest_no_route',
				$data['code'],
				"{$route_key} is no longer registered — its denial is a 404, not an authorization refusal."
			);
		}
	}

	/**
	 * The member directory is intentionally public, so what it discloses is
	 * the thing worth pinning down.
	 *
	 * `GET /members` and `GET /members/{id}` both register
	 * `permission_callback => '__return_true'`. That is deliberate (w.org
	 * profiles are public), which means there is no denial to assert and the
	 * risk moves to a future field being added to the response. This fails if
	 * one ever carries an email address or login.
	 */
	public function test_public_member_routes_disclose_no_private_fields(): void {
		wp_set_current_user( 0 );

		$collection = rest_do_request( $this->build_request( 'GET /members' ) );
		$this->assertSame( 200, $collection->get_status() );

		$single = rest_do_request( $this->build_request( 'GET /members/{id}' ) );
		$this->assertSame( 200, $single->get_status() );

		$private_fields = array( 'email', 'user_email', 'user_login', 'user_pass', 'ip', 'user_registered' );
		$payloads       = array_merge( (array) $collection->get_data(), array( $single->get_data() ) );

		foreach ( $payloads as $index => $member ) {
			if ( ! is_array( $member ) ) {
				continue;
			}

			foreach ( $private_fields as $field ) {
				$this->assertArrayNotHasKey(
					$field,
					$member,
					"An anonymous read of the member directory exposed '{$field}' (entry {$index})."
				);
			}
		}
	}

	/**
	 * Documents an inconsistency rather than asserting it is correct.
	 *
	 * `leave_permissions_check()` answers a non-member with 400, while
	 * `notification_preference_permissions_check()` answers the identical
	 * condition with 403. A client cannot tell from the status alone whether
	 * it hit an authorization boundary or sent a bad request.
	 *
	 * Changing either one is a behaviour change and does not belong in a
	 * test-only patch, so this pins today's behaviour and will fail loudly if
	 * someone reconciles them — at which point this test is the note saying
	 * that was the intent. See #1863.
	 */
	public function test_non_member_status_is_inconsistent_across_member_routes(): void {
		wp_set_current_user( $this->actors['outsider'] );

		$leave        = rest_do_request( $this->build_request( 'DELETE /members/leave' ) );
		$notification = rest_do_request( $this->build_request( 'POST /members/notification-preference' ) );

		$this->assertSame( 400, $leave->get_status() );
		$this->assertSame( 'not_a_member', $leave->get_data()['code'] );

		$this->assertSame( 403, $notification->get_status() );
		$this->assertSame( 'not_a_member', $notification->get_data()['code'] );
	}
}
