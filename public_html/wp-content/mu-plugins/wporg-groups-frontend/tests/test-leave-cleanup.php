<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;
use WordCamp\Groups\Frontend\Members\Members_Controller;

use function WordCamp\Groups\Frontend\Leave_Cleanup\cancel_future_rsvps;
use function WordCamp\Groups\Frontend\Leave_Cleanup\get_rsvp_comments;
use function WordCamp\Groups\Frontend\Leave_Cleanup\is_future_rsvp;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 *
 * Covers #2022: leaving a group gives up the member's seat on dates that
 * haven't happened yet, and leaves their attendance history alone.
 */
class Test_Groups_Leave_Cleanup extends Groups_TestCase {

	/**
	 * @var Members_Controller
	 */
	protected static $controller;

	/**
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		parent::wpSetUpBeforeClass( $factory );
		self::$controller = new Members_Controller();
	}

	/**
	 * An event whose date sits either side of now.
	 *
	 * @param bool $past Whether the event has already finished.
	 * @return int
	 */
	private function create_event( bool $past ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		$offset = $past ? '-2 days' : '+2 days';

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( $offset ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( $offset . ' +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * A member of this group site.
	 *
	 * @return int
	 */
	private function create_member(): int {
		$user_id = self::factory()->user->create();
		add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );

		return $user_id;
	}

	/**
	 * How many RSVPs GatherPress counts as attending for an event.
	 *
	 * Read through GatherPress's own `responses()` rather than the comment
	 * rows, since that is what the event page and the attendee list render.
	 *
	 * @param int $event_id Event to count.
	 * @return int
	 */
	private function attending_count( int $event_id ): int {
		$rsvp = new \GatherPress\Core\Rsvp\Rsvp( $event_id );

		return (int) ( $rsvp->responses()['attending']['count'] ?? 0 );
	}

	/**
	 * The headline case: a seat on an upcoming date is given back.
	 */
	public function test_cancels_rsvp_to_an_upcoming_event() {
		$user_id  = $this->create_member();
		$event_id = $this->create_event( false );

		( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->save( $user_id, 'attending' );

		$this->assertSame( 1, $this->attending_count( $event_id ), 'Fixture should start with one attendee.' );

		$this->assertSame( 1, cancel_future_rsvps( $user_id ) );
		$this->assertSame( 0, $this->attending_count( $event_id ) );
	}

	/**
	 * Attendance history survives. Leaving a group is not a way to erase
	 * having been somewhere, and #2089's events-attended list reads these.
	 */
	public function test_keeps_rsvp_to_a_past_event() {
		$user_id  = $this->create_member();
		$event_id = $this->create_event( true );

		( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->save( $user_id, 'attending' );

		$this->assertSame( 0, cancel_future_rsvps( $user_id ) );
		$this->assertSame( 1, $this->attending_count( $event_id ) );
	}

	/**
	 * Both at once, so the split is exercised in one pass rather than
	 * inferred from two separate happy paths.
	 */
	public function test_cancels_only_the_future_half() {
		$user_id = $this->create_member();
		$past_id = $this->create_event( true );
		$next_id = $this->create_event( false );

		( new \GatherPress\Core\Rsvp\Rsvp( $past_id ) )->save( $user_id, 'attending' );
		( new \GatherPress\Core\Rsvp\Rsvp( $next_id ) )->save( $user_id, 'attending' );

		$this->assertSame( 1, cancel_future_rsvps( $user_id ) );
		$this->assertSame( 1, $this->attending_count( $past_id ) );
		$this->assertSame( 0, $this->attending_count( $next_id ) );
	}

	/**
	 * A waiting-list place is a seat too: holding one after leaving keeps
	 * somebody else out of the event.
	 */
	public function test_cancels_a_waiting_list_place() {
		$user_id  = $this->create_member();
		$event_id = $this->create_event( false );

		( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->save( $user_id, 'waiting_list' );

		$this->assertNotEmpty( get_rsvp_comments( $user_id ) );
		$this->assertSame( 1, cancel_future_rsvps( $user_id ) );
		$this->assertEmpty( get_rsvp_comments( $user_id ) );
	}

	/**
	 * Only the departing member's RSVPs go. Everyone else keeps their seat.
	 */
	public function test_leaves_other_members_rsvps_alone() {
		$leaver_id = $this->create_member();
		$stayer_id = $this->create_member();
		$event_id  = $this->create_event( false );

		( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->save( $leaver_id, 'attending' );
		( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->save( $stayer_id, 'attending' );

		$this->assertSame( 2, $this->attending_count( $event_id ) );
		$this->assertSame( 1, cancel_future_rsvps( $leaver_id ) );
		$this->assertSame( 1, $this->attending_count( $event_id ) );
		$this->assertNotEmpty( get_rsvp_comments( $stayer_id ) );
	}

	/**
	 * A member with nothing to give up is not an error, and costs no writes.
	 */
	public function test_member_without_rsvps_is_a_no_op() {
		$this->assertSame( 0, cancel_future_rsvps( $this->create_member() ) );
		$this->assertSame( 0, cancel_future_rsvps( 0 ) );
	}

	/**
	 * End-to-end through the REST handler that the "Leave group" button
	 * calls, rather than the cleanup function on its own.
	 */
	public function test_leave_endpoint_cancels_and_reports() {
		$user_id  = $this->create_member();
		$event_id = $this->create_event( false );

		( new \GatherPress\Core\Rsvp\Rsvp( $event_id ) )->save( $user_id, 'attending' );

		wp_set_current_user( $user_id );
		$response = self::$controller->leave_group(
			new WP_REST_Request( 'DELETE', '/wporg-groups/v1/members/leave' )
		);
		wp_set_current_user( 0 );

		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['rsvps_cancelled'] );
		$this->assertSame( 0, $this->attending_count( $event_id ) );
		$this->assertFalse( is_user_member_of_blog( $user_id, get_current_blog_id() ) );
	}

	/**
	 * The occurrence rule, unit-tested directly: an RSVP pinned to a date
	 * is upcoming only while that date is still in the upcoming set. A
	 * cancelled or finished occurrence is not a seat to give back.
	 */
	public function test_mapped_rsvp_follows_its_occurrence() {
		$series      = array( 7 => (object) array( 'datetime_end_gmt' => '2000-01-01 00:00:00' ) );
		$occurrences = array( 7 => array( '20991231T180000' => (object) array( 'recurrence_id' => '20991231T180000' ) ) );
		$now         = '2026-09-21 00:00:00';

		$this->assertTrue( is_future_rsvp( 7, '20991231T180000', $series, $occurrences, $now ) );
		$this->assertFalse( is_future_rsvp( 7, '20200101T180000', $series, $occurrences, $now ) );
	}

	/**
	 * An unmapped RSVP is the event's own date, and must not borrow the
	 * series' next occurrence the way the my-events block's
	 * `filter_to_upcoming()` deliberately does -- that would read an RSVP
	 * to a finished date as upcoming and delete attendance history.
	 */
	public function test_unmapped_rsvp_uses_the_events_own_date() {
		$occurrences = array( 7 => array( '20991231T180000' => (object) array( 'recurrence_id' => '20991231T180000' ) ) );
		$now         = '2026-09-21 00:00:00';

		$finished = array( 7 => (object) array( 'datetime_end_gmt' => '2000-01-01 00:00:00' ) );
		$this->assertFalse( is_future_rsvp( 7, '', $finished, $occurrences, $now ) );

		$ahead = array( 7 => (object) array( 'datetime_end_gmt' => '2099-01-01 00:00:00' ) );
		$this->assertTrue( is_future_rsvp( 7, '', $ahead, $occurrences, $now ) );

		$this->assertFalse( is_future_rsvp( 7, '', array(), $occurrences, $now ) );
	}
}
