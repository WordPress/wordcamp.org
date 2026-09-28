<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\REST\save_rsvp;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * The meeting link in the RSVP endpoint's response.
 *
 * The online-event line is attendees-only, so a page load is the only thing
 * that can decide whether it shows the meeting URL. The RSVP block updates in
 * place, which used to leave that line behind: someone who had just RSVP'd
 * still saw "Online event" and no way through (#2094). The link now comes back
 * with the RSVP so the page can catch up without a reload — on exactly the
 * terms the block renders it with, which is the part worth pinning.
 *
 * @group groups
 */
class Test_Groups_RSVP_Online_Link extends Groups_TestCase {

	const MEETING_URL = 'https://meet.example.test/online-meetup';

	/**
	 * The screen this suite runs on, restored after each test.
	 *
	 * @var \WP_Screen|null
	 */
	private $previous_screen = null;

	/**
	 * `Event::maybe_get_online_event_link()` withholds the link only when
	 * `is_admin()` is false; in the admin it hands it to everybody. This
	 * bootstrap defines `WP_ADMIN`, so without a front-end screen every test
	 * here would see the attending answer and none would be testing anything.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->previous_screen = $GLOBALS['current_screen'] ?? null;

		set_current_screen( 'front' );
	}

	/**
	 * Put the screen back so a front-end screen doesn't leak into the rest of
	 * the suite.
	 */
	protected function tearDown(): void {
		if ( null === $this->previous_screen ) {
			unset( $GLOBALS['current_screen'] );
		} else {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the screen this test replaced.
			$GLOBALS['current_screen'] = $this->previous_screen;
		}

		parent::tearDown();
	}

	/**
	 * Create a published online event with a meeting link.
	 *
	 * @param bool $past Whether the event has already happened.
	 *
	 * @return int The event post ID.
	 */
	private function create_online_event( bool $past = false ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		$offset = $past ? '-30 days' : '+30 days';

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( $offset ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( $offset . ' +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		update_post_meta( $event_id, 'gatherpress_online_event_link', self::MEETING_URL );

		return $event_id;
	}

	/**
	 * Build a POST /event/{id}/rsvp request.
	 */
	private function rsvp_request( int $event_id, string $status ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', "/wporg-groups/v1/event/{$event_id}/rsvp" );
		$request->set_param( 'id', $event_id );
		$request->set_param( 'status', $status );

		return $request;
	}

	/**
	 * The response has to reflect the RSVP it just saved, not the state the
	 * request arrived in — otherwise the first RSVP still shows no link and
	 * only a reload fixes it, which is the bug.
	 */
	public function test_rsvp_response_carries_the_link_for_a_new_attendee() {
		$event_id = $this->create_online_event();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = save_rsvp( $this->rsvp_request( $event_id, 'attending' ) );

		$this->assertNotWPError( $response );

		$data = $response->get_data();

		$this->assertSame( 'attending', $data['status'] );
		$this->assertSame( self::MEETING_URL, $data['online_event_link'] );
	}

	/**
	 * Cancelling withdraws the link, so the page can put the description back.
	 */
	public function test_cancelling_an_rsvp_withdraws_the_link() {
		$event_id = $this->create_online_event();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		save_rsvp( $this->rsvp_request( $event_id, 'attending' ) );

		$response = save_rsvp( $this->rsvp_request( $event_id, 'not_attending' ) );

		$this->assertNotWPError( $response );

		$data = $response->get_data();

		$this->assertSame( 'not_attending', $data['status'] );
		$this->assertSame( '', $data['online_event_link'] );
	}

	/**
	 * An event with no meeting URL has nothing to reveal. The field is still
	 * present and empty, which is what tells the page to leave the line alone
	 * rather than guess.
	 */
	public function test_an_event_without_a_meeting_url_returns_an_empty_link() {
		$event_id = $this->create_online_event();
		delete_post_meta( $event_id, 'gatherpress_online_event_link' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = save_rsvp( $this->rsvp_request( $event_id, 'attending' ) );

		$this->assertNotWPError( $response );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'online_event_link', $data );
		$this->assertSame( '', $data['online_event_link'] );
	}
}
