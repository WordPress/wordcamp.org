<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Rsvp\Rsvp;
use WP_REST_Request;

use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\get_event_form_data;
use function WordCamp\Groups\Frontend\REST\save_draft;
use function WordCamp\Groups\Frontend\REST\update_event;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * The event form's attendee limit (#2254), stored as GatherPress's own
 * `gatherpress_capacity`, which puts RSVPs past it on the waiting list.
 *
 * @group groups
 */
class Test_Groups_Event_Capacity extends Groups_TestCase {

	/**
	 * An organizer, who may create and publish events on this group.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Creates the organizer each test acts as.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
	}

	/**
	 * A minimal valid set of event params, for tests to override from.
	 */
	private function base_event_params(): array {
		return array(
			'title'      => 'Test Event',
			'date'       => current_datetime()->modify( '+1 week' )->format( 'Y-m-d' ),
			'time_start' => '18:00',
			'time_end'   => '20:00',
		);
	}

	/**
	 * Builds an event request run through the route's own schema, so the
	 * limit is sanitized the way a real request's would be.
	 */
	private function event_request( array $params, string $route = '/wporg-groups/v1/event' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );
		$request->set_attributes( array( 'args' => \WordCamp\Groups\Frontend\REST\event_args_schema() ) );
		$request->sanitize_params();

		return $request;
	}

	/**
	 * Create a published event with the given params.
	 */
	private function create( array $params = array() ): int {
		$response = create_event( $this->event_request( $this->base_event_params() + $params ) );

		return (int) $response->get_data()['id'];
	}

	/**
	 * The stored limit, as GatherPress reads it.
	 */
	private function capacity( int $event_id ): int {
		return (int) get_post_meta( $event_id, 'gatherpress_capacity', true );
	}

	/**
	 * Creating an event stores its limit, and GatherPress puts an RSVP past
	 * it on the waiting list.
	 */
	public function test_limit_sends_rsvps_past_it_to_the_waiting_list() {
		$event_id = $this->create( array( 'capacity' => 1 ) );
		$first    = self::factory()->user->create();
		$second   = self::factory()->user->create();

		$this->assertSame( 1, $this->capacity( $event_id ) );

		( new Rsvp( $event_id ) )->save( $first, 'attending' );
		( new Rsvp( $event_id ) )->save( $second, 'attending' );

		$this->assertSame( 'attending', ( new Rsvp( $event_id ) )->get( $first )['status'] );
		$this->assertSame( 'waiting_list', ( new Rsvp( $event_id ) )->get( $second )['status'] );
	}

	/**
	 * An empty field means no limit, as before there was one.
	 */
	public function test_no_limit_by_default() {
		$event_id = $this->create( array( 'capacity' => 0 ) );

		$this->assertSame( 0, $this->capacity( $this->create() ) );
		$this->assertSame( 0, $this->capacity( $event_id ) );

		foreach ( self::factory()->user->create_many( 3 ) as $user_id ) {
			( new Rsvp( $event_id ) )->save( $user_id, 'attending' );
			$this->assertSame( 'attending', ( new Rsvp( $event_id ) )->get( $user_id )['status'] );
		}
	}

	/**
	 * Raising the limit lets people in off the waiting list, in order, up to
	 * the new limit.
	 */
	public function test_raising_the_limit_promotes_the_waiting_list() {
		$event_id = $this->create( array( 'capacity' => 1 ) );
		$users    = self::factory()->user->create_many( 3 );

		foreach ( $users as $user_id ) {
			( new Rsvp( $event_id ) )->save( $user_id, 'attending' );
		}

		update_event(
			$this->event_request( $this->base_event_params() + array(
				'id'       => $event_id,
				'capacity' => 2,
			) )
		);

		$rsvp = new Rsvp( $event_id );
		$this->assertSame( 'attending', $rsvp->get( $users[1] )['status'] );
		$this->assertSame( 'waiting_list', $rsvp->get( $users[2] )['status'] );
	}

	/**
	 * Editing can change and clear the limit, and a request without the
	 * field leaves it alone.
	 */
	public function test_update_changes_clears_and_leaves_alone() {
		$event_id = $this->create( array( 'capacity' => 10 ) );

		update_event( $this->event_request( $this->base_event_params() + array( 'id' => $event_id ) ) );
		$this->assertSame( 10, $this->capacity( $event_id ), 'An absent field must not clear the limit.' );

		update_event(
			$this->event_request( $this->base_event_params() + array(
				'id'       => $event_id,
				'capacity' => 20,
			) )
		);
		$this->assertSame( 20, $this->capacity( $event_id ) );

		update_event(
			$this->event_request( $this->base_event_params() + array(
				'id'       => $event_id,
				'capacity' => 0,
			) )
		);
		$this->assertSame( 0, $this->capacity( $event_id ) );
	}

	/**
	 * A draft keeps its limit, so publishing it does not lose it.
	 */
	public function test_draft_keeps_the_limit() {
		$response = save_draft(
			$this->event_request( $this->base_event_params() + array( 'capacity' => 15 ), '/wporg-groups/v1/draft' )
		);

		$this->assertSame( 15, $this->capacity( (int) $response->get_data()['id'] ) );
	}

	/**
	 * The form loads the limit back, a template carries it over, and a blank
	 * new event starts without one.
	 */
	public function test_form_data_returns_the_limit() {
		$event_id = $this->create( array( 'capacity' => 30 ) );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'event_id', $event_id );
		$this->assertSame( 30, get_event_form_data( $request )->get_data()['fields']['capacity'] );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'template_id', $event_id );
		$this->assertSame( 30, get_event_form_data( $request )->get_data()['fields']['capacity'] );

		$new = get_event_form_data( new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' ) )->get_data();
		$this->assertSame( 0, $new['fields']['capacity'] );
	}
}
