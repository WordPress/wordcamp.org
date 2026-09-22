<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use WP_REST_Request;

use function WordCamp\Groups\Frontend\Defaults\get_default_event_data;
use function WordCamp\Groups\Frontend\Event_Timezone\get_allowed;
use function WordCamp\Groups\Frontend\Event_Timezone\get_choices;
use function WordCamp\Groups\Frontend\Event_Timezone\get_default;
use function WordCamp\Groups\Frontend\Event_Timezone\get_event_timezone;
use function WordCamp\Groups\Frontend\Event_Timezone\sanitize;
use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\event_args_schema;
use function WordCamp\Groups\Frontend\REST\get_event_form_data;
use function WordCamp\Groups\Frontend\REST\save_draft;
use function WordCamp\Groups\Frontend\REST\update_event;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Event_Timezone extends Groups_TestCase {

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
	}

	/**
	 * Restores the site timezone any test may have moved.
	 */
	protected function tearDown(): void {
		delete_option( 'timezone_string' );
		update_option( 'gmt_offset', 0 );

		parent::tearDown();
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
	 * Builds a request run through the route's own schema, so the
	 * `sanitize_callback` applies the way it does in a real request.
	 */
	private function event_request( array $params, string $route = '/wporg-groups/v1/event' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );
		$request->set_attributes( array( 'args' => event_args_schema() ) );
		$request->sanitize_params();

		return $request;
	}

	/**
	 * Create a published event in a timezone.
	 */
	private function create_event_in( string $timezone ): int {
		wp_set_current_user( $this->editor_id );

		$response = create_event(
			$this->event_request( $this->base_event_params() + array( 'timezone' => $timezone ) )
		);

		return (int) $response->get_data()['id'];
	}

	/**
	 * The choices are grouped the way core groups them, so the select can
	 * render optgroups rather than one flat list of 400-odd identifiers.
	 */
	public function test_choices_are_grouped() {
		$choices = get_choices();

		$this->assertNotEmpty( $choices );
		$this->assertArrayHasKey( 'Australia', $choices );
		$this->assertArrayHasKey( 'Australia/Brisbane', $choices['Australia'] );
		$this->assertSame( 'Brisbane', $choices['Australia']['Australia/Brisbane'] );
	}

	/**
	 * Only values GatherPress itself accepts survive, so anything written
	 * here round-trips through its events table rather than making a later
	 * read of the event's date throw.
	 */
	public function test_sanitize_accepts_only_values_the_control_offers() {
		$this->assertSame( 'Australia/Brisbane', sanitize( 'Australia/Brisbane' ) );
		$this->assertSame( 'UTC', sanitize( 'UTC' ) );
		$this->assertSame( 'UTC+5.5', sanitize( 'UTC+5.5' ) );
		$this->assertSame( 'Australia/Brisbane', sanitize( '  Australia/Brisbane  ' ) );
		$this->assertSame( '', sanitize( 'Not/A_Real_Zone' ) );
		$this->assertSame( '', sanitize( 'australia/brisbane' ) );
		$this->assertSame( '', sanitize( '' ) );
	}

	/**
	 * A stored offset has to resolve back to the choice the control offers.
	 *
	 * GatherPress spells an offset `UTC+10` in the choices core builds and
	 * `+10:00` in its events table, and collapses a zero offset to `UTC` on
	 * the way in while `wp_timezone_string()` keeps saying `+00:00`. A stored
	 * value that matched no option left the `<select>` falling back to its
	 * first one, so opening an existing event and saving it rescheduled it
	 * into Africa/Abidjan without the organizer touching the control.
	 *
	 * @dataProvider provide_stored_spellings
	 *
	 * @param string $stored   The spelling in the events table.
	 * @param string $expected The choice the control offers for it.
	 */
	public function test_a_stored_spelling_resolves_to_an_offerable_choice( string $stored, string $expected ) {
		$this->assertSame( $expected, sanitize( $stored ) );
		$this->assertContains( sanitize( $stored ), get_allowed() );
	}

	/**
	 * Data provider for the stored-spelling round trip.
	 */
	public function provide_stored_spellings(): array {
		return array(
			'zero offset'       => array( '+00:00', 'UTC' ),
			'whole hours'       => array( '-12:00', 'UTC-12' ),
			'half hour'         => array( '+05:30', 'UTC+5.5' ),
			'quarter hour'      => array( '+08:45', 'UTC+8.75' ),
			'named zone'        => array( 'Australia/Brisbane', 'Australia/Brisbane' ),
		);
	}

	/**
	 * Every value the control offers survives a write and a read back, so
	 * picking any option and saving cannot land on a different one.
	 */
	public function test_every_offered_choice_round_trips() {
		wp_set_current_user( $this->editor_id );

		foreach ( array( 'UTC', 'UTC+0', 'UTC-12', 'UTC+5.5', 'UTC+8.75', 'Australia/Brisbane', 'Europe/Skopje' ) as $choice ) {
			$event_id = $this->create_event_in( $choice );

			$this->assertSame(
				sanitize( $choice ),
				get_event_timezone( $event_id ),
				"Choosing {$choice} and reading it back must land on the same option."
			);
		}
	}

	/**
	 * The default is the site's own zone, which for a group site is whatever
	 * the network admin chose at provisioning.
	 */
	public function test_default_follows_the_site_timezone() {
		update_option( 'timezone_string', 'Europe/Skopje' );

		$this->assertSame( 'Europe/Skopje', get_default() );
	}

	/**
	 * A site left on a bare GMT offset still resolves to something writable
	 * rather than to an empty zone.
	 */
	public function test_default_falls_back_when_the_site_has_no_named_zone() {
		delete_option( 'timezone_string' );
		update_option( 'gmt_offset', 0 );

		$this->assertContains( get_default(), array( 'UTC', '+00:00' ) );
	}

	/**
	 * Creating an event through the front-end form schedules it in the
	 * chosen zone.
	 */
	public function test_create_event_stores_the_chosen_timezone() {
		$event_id = $this->create_event_in( 'Australia/Brisbane' );

		$this->assertSame( 'Australia/Brisbane', get_event_timezone( $event_id ) );
	}

	/**
	 * The submitted times are wall-clock times in the chosen zone, not UTC,
	 * so the zone must not shift what the organizer typed.
	 */
	public function test_the_chosen_timezone_does_not_move_the_entered_time() {
		$event_id = $this->create_event_in( 'Australia/Brisbane' );
		$datetime = ( new Event( $event_id ) )->get_datetime();

		$this->assertStringContainsString( '18:00:00', $datetime['datetime_start'] );
		$this->assertStringContainsString( '20:00:00', $datetime['datetime_end'] );

		// Brisbane is UTC+10 year round, so the stored UTC start is 08:00.
		$this->assertStringContainsString( '08:00:00', $datetime['datetime_start_gmt'] );
	}

	/**
	 * An unrecognized zone falls back to the site's own rather than failing
	 * the save of everything else on the form.
	 */
	public function test_create_event_falls_back_on_an_unrecognized_timezone() {
		update_option( 'timezone_string', 'Europe/Skopje' );

		$event_id = $this->create_event_in( 'Not/A_Real_Zone' );

		$this->assertSame( 'Europe/Skopje', get_event_timezone( $event_id ) );
		$this->assertSame( 'Test Event', get_the_title( $event_id ) );
	}

	/**
	 * An event saved before there was a control keeps working: no submitted
	 * zone means the site's own, which is what it already had.
	 */
	public function test_an_omitted_timezone_uses_the_site_zone() {
		update_option( 'timezone_string', 'Europe/Skopje' );

		wp_set_current_user( $this->editor_id );
		$response = create_event( $this->event_request( $this->base_event_params() ) );

		$this->assertSame( 'Europe/Skopje', get_event_timezone( (int) $response->get_data()['id'] ) );
	}

	/**
	 * Editing an event can move it to another zone.
	 */
	public function test_update_event_changes_the_timezone() {
		$event_id = $this->create_event_in( 'Australia/Brisbane' );

		wp_set_current_user( $this->editor_id );
		update_event(
			$this->event_request(
				$this->base_event_params() + array(
					'id'       => $event_id,
					'timezone' => 'Europe/Skopje',
				)
			)
		);

		$this->assertSame( 'Europe/Skopje', get_event_timezone( $event_id ) );
	}

	/**
	 * A draft keeps the zone, so publishing it does not silently reschedule
	 * the event into the site's own.
	 */
	public function test_draft_keeps_the_timezone() {
		wp_set_current_user( $this->editor_id );

		$response = save_draft(
			$this->event_request(
				$this->base_event_params() + array( 'timezone' => 'Australia/Brisbane' ),
				'/wporg-groups/v1/draft'
			)
		);

		$this->assertSame( 'Australia/Brisbane', get_event_timezone( (int) $response->get_data()['id'] ) );
	}

	/**
	 * The form loads an existing event's zone back, and ships the grouped
	 * choices with the rest of its payload.
	 */
	public function test_form_data_returns_the_timezone_and_the_choices() {
		$event_id = $this->create_event_in( 'Australia/Brisbane' );

		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'event_id', $event_id );
		$data = get_event_form_data( $request )->get_data();

		$this->assertSame( 'Australia/Brisbane', $data['fields']['timezone'] );
		$this->assertArrayHasKey( 'Australia', $data['timezones'] );
	}

	/**
	 * A new event is prefilled from the group's last event rather than from
	 * the site zone, so a group that moved keeps the move.
	 */
	public function test_new_event_defaults_to_the_previous_events_timezone() {
		update_option( 'timezone_string', 'Europe/Skopje' );

		$this->assertSame( 'Europe/Skopje', get_default_event_data()['timezone'] );

		$this->create_event_in( 'Australia/Brisbane' );

		$this->assertSame( 'Australia/Brisbane', get_default_event_data()['timezone'] );
	}

	/**
	 * The event page shows the zone on the time line. It is suppressed
	 * network-wide no longer (#2021), and the block-level attribute alone
	 * cannot turn it on: GatherPress reads the global setting first and
	 * formats with an empty string when it is off.
	 */
	public function test_the_time_line_renders_the_timezone() {
		$event_id = $this->create_event_in( 'Australia/Brisbane' );

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );

		$markup = do_blocks(
			'<!-- wp:gatherpress/event-date {"startDateFormat":"g:i A","endDateFormat":"g:i A","showTimezone":"yes"} /-->'
		);

		$this->assertStringContainsString( 'AEST', $markup );
	}

	/**
	 * The date line above it stays clean: the zone qualifies a time, not a
	 * date, and repeating it on both lines is noise.
	 */
	public function test_the_date_line_does_not_repeat_the_timezone() {
		$event_id = $this->create_event_in( 'Australia/Brisbane' );

		$this->go_to( home_url( "?p={$event_id}&post_type=gatherpress_event" ) );

		$markup = do_blocks(
			'<!-- wp:gatherpress/event-date {"displayType":"start","startDateFormat":"l, F j","showTimezone":"no"} /-->'
		);

		$this->assertStringNotContainsString( 'AEST', $markup );
	}

	/**
	 * Event emails render the date through GatherPress's own
	 * `get_display_datetime()` with no arguments, so they follow the global
	 * setting. That is the whole reason the setting is forced on rather than
	 * the templates being patched one by one.
	 */
	public function test_the_default_display_datetime_carries_the_timezone() {
		$event_id = $this->create_event_in( 'Australia/Brisbane' );

		$this->assertStringContainsString(
			'AEST',
			( new Event( $event_id ) )->get_display_datetime()
		);
	}
}
