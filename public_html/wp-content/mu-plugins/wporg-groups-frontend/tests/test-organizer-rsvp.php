<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Rsvp\Rsvp;
use WordPressdotorg\GatherPress_Recurring_Events\Database as Recurring_Events_Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WP_REST_Request;

use function WordCamp\Groups\Frontend\My_Events\get_comment_recurrence_ids;
use function WordCamp\Groups\Frontend\REST\create_event;
use function WordCamp\Groups\Frontend\REST\publish_draft;
use function WordCamp\Groups\Frontend\REST\save_draft;
use function WordCamp\Groups\Frontend\REST\update_event;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * RSVPing the organizer to an event they publish (#2255).
 *
 * @group groups
 */
class Test_Groups_Organizer_RSVP extends Groups_TestCase {

	/**
	 * The organizer publishing the events.
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
	 * Builds an event request run through the route's own schema.
	 */
	private function event_request( array $params, string $route = '/wporg-groups/v1/event' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );
		$request->set_attributes( array( 'args' => \WordCamp\Groups\Frontend\REST\event_args_schema() ) );
		$request->sanitize_params();

		return $request;
	}

	/**
	 * The organizer's RSVP comments on an event.
	 *
	 * @return \WP_Comment[]
	 */
	private function organizer_rsvps( int $event_id ): array {
		return get_comments(
			array(
				'post_id' => $event_id,
				'user_id' => $this->editor_id,
				'type'    => Rsvp::COMMENT_TYPE,
				'status'  => 'all',
			)
		);
	}

	/**
	 * Publishing with the box ticked RSVPs the organizer as attending.
	 */
	public function test_create_rsvps_the_organizer() {
		$response = create_event( $this->event_request( $this->base_event_params() + array( 'rsvp_organizer' => true ) ) );
		$event_id = (int) $response->get_data()['id'];

		$this->assertSame( 'attending', ( new Rsvp( $event_id ) )->get( $this->editor_id )['status'] );
	}

	/**
	 * Unticked, or not sent at all, nobody is RSVP'd.
	 */
	public function test_no_rsvp_unless_asked() {
		$unticked = create_event( $this->event_request( $this->base_event_params() + array( 'rsvp_organizer' => false ) ) );
		$absent   = create_event( $this->event_request( $this->base_event_params() ) );

		$this->assertSame( array(), $this->organizer_rsvps( (int) $unticked->get_data()['id'] ) );
		$this->assertSame( array(), $this->organizer_rsvps( (int) $absent->get_data()['id'] ) );
	}

	/**
	 * Saving an edit doesn't put back an RSVP the organizer cancelled.
	 */
	public function test_editing_a_published_event_leaves_the_rsvp_alone() {
		$response = create_event( $this->event_request( $this->base_event_params() + array( 'rsvp_organizer' => true ) ) );
		$event_id = (int) $response->get_data()['id'];

		( new Rsvp( $event_id ) )->save( $this->editor_id, 'not_attending' );

		update_event(
			$this->event_request(
				$this->base_event_params() + array(
					'id'             => $event_id,
					'rsvp_organizer' => true,
				)
			)
		);

		$this->assertSame( 'not_attending', ( new Rsvp( $event_id ) )->get( $this->editor_id )['status'] );
	}

	/**
	 * A draft RSVPs nobody until it's published.
	 */
	public function test_draft_rsvps_on_publish_only() {
		$draft    = save_draft(
			$this->event_request( $this->base_event_params() + array( 'rsvp_organizer' => true ), '/wporg-groups/v1/draft' )
		);
		$draft_id = (int) $draft->get_data()['id'];

		$this->assertSame( array(), $this->organizer_rsvps( $draft_id ) );

		publish_draft(
			$this->event_request(
				$this->base_event_params() + array(
					'id'             => $draft_id,
					'rsvp_organizer' => true,
				),
				"/wporg-groups/v1/draft/{$draft_id}/publish"
			)
		);

		$this->assertSame( 'attending', ( new Rsvp( $draft_id ) )->get( $this->editor_id )['status'] );
	}

	/**
	 * A series RSVPs the organizer to its first date only.
	 */
	public function test_series_rsvps_the_first_date() {
		$date    = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$weekday = strtoupper( substr( gmdate( 'D', strtotime( $date ) ), 0, 2 ) );

		Recurring_Events_Database::maybe_install();

		$response = create_event(
			$this->event_request(
				array_merge(
					$this->base_event_params(),
					array(
						'date'           => $date,
						'rsvp_organizer' => true,
						'recurrence'     => array(
							'frequency' => 'weekly',
							'interval'  => 1,
							'weekdays'  => array( $weekday ),
							'end_type'  => 'count',
							'count'     => 3,
						),
					)
				)
			)
		);
		$event_id = (int) $response->get_data()['id'];
		$rsvps    = $this->organizer_rsvps( $event_id );
		$first    = Occurrences::all( $event_id )[0];

		$this->assertCount( 1, $rsvps );
		$this->assertSame(
			array( (int) $rsvps[0]->comment_ID => (string) $first->recurrence_id ),
			get_comment_recurrence_ids( array( (int) $rsvps[0]->comment_ID ) )
		);
	}
}
