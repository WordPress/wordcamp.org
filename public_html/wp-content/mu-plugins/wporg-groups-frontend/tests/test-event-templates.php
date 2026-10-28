<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\Defaults\get_default_event_data;
use function WordCamp\Groups\Frontend\REST\create_event;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Starting a new event from a past one (#1892).
 *
 * The create form can be filled from any published event in the group. It
 * copies what the event is, not when it is: the date and start time stay the
 * new-event defaults, and only the template's duration carries over.
 * Who may do this is covered by the authorization matrix in
 * `test-rest-authorization.php`.
 *
 * @group groups
 */
class Test_Groups_Event_Templates extends Groups_TestCase {

	/**
	 * The organizer who created the template event.
	 *
	 * @var int
	 */
	private $organiser_id;

	/**
	 * An Event Organizer (author) who didn't create it.
	 *
	 * @var int
	 */
	private $event_organiser_id;

	/**
	 * Create the two actors.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->organiser_id       = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->event_organiser_id = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Create and publish an event through the form's own write path.
	 *
	 * @param array $params Params overriding the defaults.
	 *
	 * @return int The event post ID.
	 */
	private function create_event( array $params ): int {
		wp_set_current_user( $this->organiser_id );

		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event' );
		$params += array(
			'title'      => 'Monthly Meetup',
			'date'       => current_datetime()->modify( '+2 weeks' )->format( 'Y-m-d' ),
			'time_start' => '18:00',
			'time_end'   => '20:00',
		);
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = create_event( $request );
		$this->assertNotWPError( $response );

		return (int) $response->get_data()['id'];
	}

	/**
	 * Load the create form from a template, as a given user.
	 *
	 * @param int $template_id The event to start from.
	 * @param int $user_id     The user loading the form.
	 *
	 * @return array The response data.
	 */
	private function load_template( int $template_id, int $user_id ): array {
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'template_id', $template_id );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * Everything about the event carries over, for an organizer who didn't
	 * create it too.
	 */
	public function test_template_prefills_the_event() {
		$template_id = $this->create_event(
			array(
				'title'             => 'WordPress Workshop',
				'description'       => "<!-- wp:paragraph -->\n<p>Bring a laptop.</p>\n<!-- /wp:paragraph -->",
				'is_online'         => true,
				'online_event_link' => 'https://meet.example.com/workshop',
				'language'          => 'fr',
				'rsvp_questions'    => array(
					array(
						'label'    => 'What do you want to learn?',
						'required' => true,
					),
				),
			)
		);

		$data   = $this->load_template( $template_id, $this->event_organiser_id );
		$fields = $data['fields'];

		$this->assertFalse( $data['is_editing'], 'A template fills a new event, not an edit of the old one.' );
		$this->assertSame( 0, $data['event_id'] );
		$this->assertSame( 'WordPress Workshop', $fields['title'] );
		$this->assertStringContainsString( 'Bring a laptop.', $fields['description'] );
		$this->assertTrue( $fields['is_online'] );
		$this->assertSame( 'https://meet.example.com/workshop', $fields['online_event_link'] );
		$this->assertSame( 'fr', $fields['language'] );
		$this->assertSame( array( 'What do you want to learn?' ), wp_list_pluck( $fields['rsvp_questions'], 'label' ) );
		$this->assertFalse( $fields['recurrence']['locked'], 'The new event\'s recurrence is still open.' );
	}

	/**
	 * The date and start time are the new-event defaults; only the length of
	 * the template carries over, applied to the default start.
	 */
	public function test_template_keeps_default_date_and_start_and_copies_duration() {
		$template_id = $this->create_event(
			array(
				'date'       => current_datetime()->modify( '+3 weeks' )->format( 'Y-m-d' ),
				'time_start' => '19:00',
				'time_end'   => '21:30',
			)
		);

		// The group's most recently set up event is what the defaults read
		// the start time from, so make that a different event.
		$this->create_event(
			array(
				'time_start' => '18:15',
				'time_end'   => '19:00',
			)
		);

		wp_set_current_user( $this->organiser_id );
		$defaults = get_default_event_data();
		$this->assertSame( '18:15', $defaults['time_start'], 'Precondition: the default start comes from the latest event.' );

		$fields = $this->load_template( $template_id, $this->organiser_id )['fields'];

		$this->assertSame( $defaults['date'], $fields['date'] );
		$this->assertSame( '18:15', $fields['time_start'] );
		$this->assertSame( '20:45', $fields['time_end'], '18:15 plus the template\'s two and a half hours.' );
	}

	/**
	 * A late event's duration wraps past midnight rather than producing an
	 * invalid time.
	 */
	public function test_template_duration_wraps_past_midnight() {
		$template_id = $this->create_event(
			array(
				'time_start' => '10:00',
				'time_end'   => '17:00',
			)
		);
		$this->create_event(
			array(
				'time_start' => '20:00',
				'time_end'   => '21:00',
			)
		);

		$fields = $this->load_template( $template_id, $this->organiser_id )['fields'];

		$this->assertSame( '20:00', $fields['time_start'] );
		$this->assertSame( '03:00', $fields['time_end'] );
	}

	/**
	 * The picker lists published events most recently set up first, whatever
	 * their dates, and leaves drafts out.
	 */
	public function test_templates_list_published_events_most_recent_first() {
		$far_future = $this->create_event(
			array(
				'title' => 'Far Future Event',
				'date'  => current_datetime()->modify( '+1 year' )->format( 'Y-m-d' ),
			)
		);
		$latest     = $this->create_event(
			array(
				'title' => 'Latest Event',
				'date'  => current_datetime()->modify( '+2 weeks' )->format( 'Y-m-d' ),
			)
		);
		wp_update_post(
			array(
				'ID'            => $far_future,
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			)
		);
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
				'post_author' => $this->organiser_id,
				'post_title'  => 'Draft Event',
			)
		);

		wp_set_current_user( $this->event_organiser_id );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wporg-groups/v1/event-templates' ) );

		$this->assertSame( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertSame( array( $latest, $far_future ), array_values( array_intersect( $ids, array( $latest, $far_future ) ) ) );
		$this->assertNotContains( $draft, $ids );
	}

	/**
	 * Asking for an edit and a template at once is refused rather than
	 * quietly picking one.
	 */
	public function test_event_id_and_template_id_together_are_refused() {
		$event_id = $this->create_event( array() );

		wp_set_current_user( $this->organiser_id );

		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/event-form-data' );
		$request->set_param( 'event_id', $event_id );
		$request->set_param( 'template_id', $event_id );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}
}
