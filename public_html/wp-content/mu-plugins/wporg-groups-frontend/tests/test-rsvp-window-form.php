<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\get_event_form_data;
use function WordCamp\Groups\Frontend\REST\save_draft;
use function WordCamp\Groups\Frontend\REST\update_event;
use function WordCamp\Groups\Frontend\RSVP_Window\get_settings;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Setting when RSVPs open and close from the event form (#2397).
 *
 * @group groups
 */
class Test_Groups_RSVP_Window_Form extends Groups_TestCase {

	/**
	 * Creates the organizer each test acts as.
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * A minimal valid set of event params, for tests to override from.
	 */
	private function base_event_params(): array {
		return array(
			'title'      => 'Test Event',
			'date'       => current_datetime()->modify( '+3 weeks' )->format( 'Y-m-d' ),
			'time_start' => '18:00',
			'time_end'   => '20:00',
		);
	}

	/**
	 * An event request run through the route's own schema.
	 *
	 * @param array  $params Body params.
	 * @param string $route  Route.
	 */
	private function event_request( array $params, string $route = '/wporg-groups/v1/event' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );
		$request->set_attributes( array( 'args' => \WordCamp\Groups\Frontend\REST\event_args_schema() ) );
		$request->sanitize_params();

		return $request;
	}

	/**
	 * The form's fields for an event, or for a new one from a template.
	 *
	 * @param array $query Query params.
	 */
	private function form_fields( array $query ): array {
		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return get_event_form_data( $request )->get_data()['fields'];
	}

	/**
	 * Creating an event stores both settings, and the form loads them back.
	 */
	public function test_create_saves_the_window_and_the_form_loads_it() {
		$response = create_event(
			$this->event_request(
				$this->base_event_params() + array(
					'rsvp_opens_before'  => 2 * WEEK_IN_SECONDS,
					'rsvp_closes_before' => DAY_IN_SECONDS,
				)
			)
		);
		$event_id = (int) $response->get_data()['id'];

		$expected = array(
			'rsvp_opens_before'  => 2 * WEEK_IN_SECONDS,
			'rsvp_closes_before' => DAY_IN_SECONDS,
		);
		$this->assertSame( $expected, get_settings( $event_id ) );

		$fields = $this->form_fields( array( 'event_id' => $event_id ) );
		$this->assertSame( 2 * WEEK_IN_SECONDS, $fields['rsvp_opens_before'] );
		$this->assertSame( DAY_IN_SECONDS, $fields['rsvp_closes_before'] );

		$copy = $this->form_fields(
			array(
				'template_id' => $event_id,
				'copy'        => 1,
			)
		);
		$this->assertSame( DAY_IN_SECONDS, $copy['rsvp_closes_before'], 'A copy keeps the window.' );
	}

	/**
	 * An absent field leaves a setting alone; null clears it.
	 */
	public function test_update_leaves_alone_changes_and_clears() {
		$response = create_event(
			$this->event_request( $this->base_event_params() + array( 'rsvp_closes_before' => DAY_IN_SECONDS ) )
		);
		$event_id = (int) $response->get_data()['id'];

		update_event( $this->event_request( $this->base_event_params() + array( 'id' => $event_id ) ) );
		$this->assertSame( DAY_IN_SECONDS, get_settings( $event_id )['rsvp_closes_before'] );

		update_event(
			$this->event_request(
				$this->base_event_params() + array(
					'id'                 => $event_id,
					'rsvp_closes_before' => 3 * HOUR_IN_SECONDS,
				)
			)
		);
		$this->assertSame( 3 * HOUR_IN_SECONDS, get_settings( $event_id )['rsvp_closes_before'] );

		update_event(
			$this->event_request(
				$this->base_event_params() + array(
					'id'                 => $event_id,
					'rsvp_closes_before' => null,
				)
			)
		);
		$this->assertNull( get_settings( $event_id )['rsvp_closes_before'] );
	}

	/**
	 * A window that closes before it opens is refused, and nothing is saved.
	 */
	public function test_window_that_closes_before_it_opens_is_refused() {
		$response = create_event(
			$this->event_request(
				$this->base_event_params() + array(
					'rsvp_opens_before'  => DAY_IN_SECONDS,
					'rsvp_closes_before' => WEEK_IN_SECONDS,
				)
			)
		);

		$this->assertWPError( $response );
		$this->assertSame( 'wporg_groups_invalid_rsvp_window', $response->get_error_code() );
	}

	/**
	 * The check counts the setting already saved when only one is sent.
	 */
	public function test_validation_uses_the_saved_setting() {
		$response = create_event(
			$this->event_request( $this->base_event_params() + array( 'rsvp_closes_before' => 2 * DAY_IN_SECONDS ) )
		);
		$event_id = (int) $response->get_data()['id'];

		$response = update_event(
			$this->event_request(
				$this->base_event_params() + array(
					'id'                => $event_id,
					'rsvp_opens_before' => DAY_IN_SECONDS,
				)
			)
		);

		$this->assertWPError( $response );
		$this->assertNull( get_settings( $event_id )['rsvp_opens_before'] );
	}

	/**
	 * A draft keeps the window, so publishing it doesn't lose it.
	 */
	public function test_draft_keeps_the_window() {
		$response = save_draft(
			$this->event_request(
				$this->base_event_params() + array( 'rsvp_opens_before' => WEEK_IN_SECONDS ),
				'/wporg-groups/v1/draft'
			)
		);

		$this->assertSame( WEEK_IN_SECONDS, get_settings( (int) $response->get_data()['id'] )['rsvp_opens_before'] );
	}

	/**
	 * A negative or too-long setting fails the route's schema.
	 */
	public function test_schema_bounds_the_settings() {
		$args = \WordCamp\Groups\Frontend\REST\event_args_schema();

		$this->assertTrue( rest_validate_value_from_schema( null, $args['rsvp_closes_before'] ) );
		$this->assertTrue( rest_validate_value_from_schema( 0, $args['rsvp_closes_before'] ) );
		$this->assertWPError( rest_validate_value_from_schema( -1, $args['rsvp_closes_before'] ) );
		$this->assertWPError( rest_validate_value_from_schema( 2 * YEAR_IN_SECONDS, $args['rsvp_opens_before'] ) );
	}
}
