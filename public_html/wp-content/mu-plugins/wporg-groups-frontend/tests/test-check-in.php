<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;
use WP_REST_Request;

use function WordCamp\Groups\Frontend\Check_In\event_has_check_ins;
use function WordCamp\Groups\Frontend\Check_In\is_checked_in;
use function WordCamp\Groups\Frontend\Export\collect_export_data;
use function WordCamp\Groups\Frontend\My_Events\get_past_events;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Coverage for event check-in and walk-ins (#2130), and for the two places
 * that read attendance from it: "Events I attended" and the export.
 *
 * Route authorization across every role is in Test_Groups_REST_Authorization;
 * this covers what the routes do once allowed.
 *
 * @group groups
 */
class Test_Groups_Check_In extends Groups_TestCase {

	/**
	 * The Event Organizer (author) who owns the events below.
	 *
	 * @var int
	 */
	private $author_id = 0;

	/**
	 * Register the routes against this test's server and act as the author.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author_id );
	}

	/**
	 * Create a published event owned by the author.
	 *
	 * @param string $starts `strtotime()` offset for the start; it runs two hours.
	 */
	private function create_event( string $starts = '-1 day' ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( $starts ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( $starts . ' +2 hours' ) ),
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * RSVP a new member to an event.
	 *
	 * @return array{0: int, 1: int} User ID and RSVP comment ID.
	 */
	private function rsvp( int $event_id, string $status = 'attending' ): array {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$record  = ( new Rsvp( $event_id ) )->save( $user_id, $status );

		return array( $user_id, (int) $record['comment_id'] );
	}

	/**
	 * Dispatch a check-in toggle.
	 */
	private function toggle( int $event_id, int $comment_id, bool $checked_in ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/wporg-groups/v1/event/{$event_id}/check-in/{$comment_id}" );
		$request->set_body_params( array( 'checked_in' => $checked_in ) );

		return rest_do_request( $request );
	}

	/**
	 * Dispatch a walk-in.
	 */
	private function walk_in( int $event_id, string $login ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/wporg-groups/v1/event/{$event_id}/walk-in" );
		$request->set_body_params( array( 'login' => $login ) );

		return rest_do_request( $request );
	}

	/**
	 * An Event Organizer checks an attendee in on their own event, and the
	 * list reflects it.
	 */
	public function test_check_in_on_own_event() {
		$event_id                   = $this->create_event();
		list( , $comment_id )       = $this->rsvp( $event_id );
		list( , $other_comment_id ) = $this->rsvp( $event_id );

		$response = $this->toggle( $event_id, $comment_id, true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( is_checked_in( $comment_id ) );
		$this->assertFalse( is_checked_in( $other_comment_id ) );
		$this->assertTrue( event_has_check_ins( $event_id ) );

		$data = $response->get_data();
		$this->assertSame( 1, $data['checkedInCount'] );
		$this->assertCount( 2, $data['attendees'] );
		$this->assertTrue( $data['isOpen'] );
	}

	/**
	 * Undoing the last check-in clears the record that the event used
	 * check-in, so "attended" falls back to RSVPs again.
	 */
	public function test_undo_clears_the_event_flag() {
		$event_id             = $this->create_event();
		list( , $comment_id ) = $this->rsvp( $event_id );

		$this->toggle( $event_id, $comment_id, true );
		$response = $this->toggle( $event_id, $comment_id, false );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( is_checked_in( $comment_id ) );
		$this->assertFalse( event_has_check_ins( $event_id ) );
		$this->assertSame( 0, $response->get_data()['checkedInCount'] );
	}

	/**
	 * The permission check is against the event in the URL, so an RSVP from
	 * a different event must be refused, not checked in.
	 */
	public function test_rsvp_from_another_event_is_refused() {
		$event_id = $this->create_event();

		// Owned by someone else, so the author couldn't check it in directly.
		$other_event_id       = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ),
			)
		);
		list( , $comment_id ) = $this->rsvp( $other_event_id );

		$response = $this->toggle( $event_id, $comment_id, true );

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( is_checked_in( $comment_id ) );
	}

	/**
	 * A comment that isn't an RSVP is refused too.
	 */
	public function test_non_rsvp_comment_is_refused() {
		$event_id   = $this->create_event();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $event_id ) );

		$this->assertSame( 404, $this->toggle( $event_id, $comment_id, true )->get_status() );
	}

	/**
	 * Before check-in opens, the list can be read but nothing can change.
	 */
	public function test_future_event_is_not_open() {
		$event_id             = $this->create_event( '+3 days' );
		list( , $comment_id ) = $this->rsvp( $event_id );
		$walk_in_login        = get_userdata( self::factory()->user->create() )->user_login;

		$list = rest_do_request( new WP_REST_Request( 'GET', "/wporg-groups/v1/event/{$event_id}/check-in" ) );
		$this->assertSame( 200, $list->get_status() );
		$this->assertFalse( $list->get_data()['isOpen'] );

		$this->assertSame( 400, $this->toggle( $event_id, $comment_id, true )->get_status() );
		$this->assertSame( 400, $this->walk_in( $event_id, $walk_in_login )->get_status() );
		$this->assertFalse( is_checked_in( $comment_id ) );
	}

	/**
	 * Check-in opens an hour before the start, for organizers at the door.
	 */
	public function test_opens_an_hour_before_the_start() {
		$event_id             = $this->create_event( '+30 minutes' );
		list( , $comment_id ) = $this->rsvp( $event_id );

		$this->assertSame( 200, $this->toggle( $event_id, $comment_id, true )->get_status() );
	}

	/**
	 * A walk-in found by username gets an RSVP and is checked in, without
	 * being made a member of the group.
	 */
	public function test_walk_in_by_username() {
		$event_id = $this->create_event();
		$user_id  = self::factory()->user->create( array( 'display_name' => 'Walk In' ) );
		remove_user_from_blog( $user_id, get_current_blog_id() );

		$response = $this->walk_in( $event_id, get_userdata( $user_id )->user_login );

		$this->assertSame( 200, $response->get_status() );

		$record = ( new Rsvp( $event_id ) )->get( $user_id );
		$this->assertSame( 'attending', $record['status'] );
		$this->assertTrue( is_checked_in( (int) $record['comment_id'] ) );
		$this->assertSame( (int) $record['comment_id'], $response->get_data()['added'] );
		$this->assertSame( 'Walk In is checked in.', $response->get_data()['message'] );
		$this->assertFalse( is_user_member_of_blog( $user_id, get_current_blog_id() ) );
	}

	/**
	 * A walk-in can be found by email address.
	 */
	public function test_walk_in_by_email() {
		$event_id = $this->create_event();
		$user_id  = self::factory()->user->create( array( 'user_email' => 'walkin@example.org' ) );

		$this->assertSame( 200, $this->walk_in( $event_id, 'walkin@example.org' )->get_status() );
		$this->assertTrue( is_checked_in( (int) ( new Rsvp( $event_id ) )->get( $user_id )['comment_id'] ) );
	}

	/**
	 * Only exact matches: the field is not a way to search accounts.
	 */
	public function test_walk_in_needs_an_exact_match() {
		$event_id = $this->create_event();
		self::factory()->user->create( array( 'user_login' => 'walkinperson' ) );

		$response = $this->walk_in( $event_id, 'walkin' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wporg_groups_walk_in_not_found', $response->get_data()['code'] );
	}

	/**
	 * Someone already attending keeps their RSVP and is just checked in.
	 */
	public function test_walk_in_who_already_rsvpd() {
		$event_id                     = $this->create_event();
		list( $user_id, $comment_id ) = $this->rsvp( $event_id );

		$response = $this->walk_in( $event_id, get_userdata( $user_id )->user_login );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $comment_id, $response->get_data()['added'] );
		$this->assertTrue( is_checked_in( $comment_id ) );
		$this->assertCount( 1, $response->get_data()['attendees'] );
	}

	/**
	 * Someone who said they weren't coming but turned up is switched to
	 * attending.
	 */
	public function test_walk_in_who_declined() {
		$event_id         = $this->create_event();
		list( $user_id, ) = $this->rsvp( $event_id, 'not_attending' );

		$this->walk_in( $event_id, get_userdata( $user_id )->user_login );

		$record = ( new Rsvp( $event_id ) )->get( $user_id );
		$this->assertSame( 'attending', $record['status'] );
		$this->assertTrue( is_checked_in( (int) $record['comment_id'] ) );
	}

	/**
	 * A full event puts the walk-in on the waiting list, and they are still
	 * checked in: check-in, not status, records that they came.
	 */
	public function test_walk_in_at_capacity_is_still_checked_in() {
		$event_id = $this->create_event();
		update_post_meta( $event_id, 'gatherpress_max_attendance_limit', 1 );
		$this->rsvp( $event_id );

		$user_id  = self::factory()->user->create();
		$response = $this->walk_in( $event_id, get_userdata( $user_id )->user_login );

		$record = ( new Rsvp( $event_id ) )->get( $user_id );
		$this->assertSame( 'waiting_list', $record['status'] );
		$this->assertTrue( is_checked_in( (int) $record['comment_id'] ) );
		$this->assertSame( 1, $response->get_data()['checkedInCount'] );
	}

	/**
	 * Walk-ins get no RSVP confirmation email: it would arrive after the
	 * event, for an RSVP they didn't make.
	 */
	public function test_walk_in_sends_no_confirmation() {
		$sent = array();
		$trap = static function ( $short_circuit, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		};

		add_filter( 'pre_wp_mail', $trap, 10, 2 );
		add_action( 'set_object_terms', 'WordCamp\Groups\Frontend\RSVP_Confirmation\send_confirmation', 10, 6 );

		$event_id = $this->create_event();
		$this->walk_in( $event_id, get_userdata( self::factory()->user->create() )->user_login );

		remove_filter( 'pre_wp_mail', $trap, 10 );
		remove_action( 'set_object_terms', 'WordCamp\Groups\Frontend\RSVP_Confirmation\send_confirmation', 10 );

		$this->assertSame( array(), $sent );
	}

	/**
	 * On an event with check-ins, "Events I attended" follows them: a no-show
	 * drops out, and a checked-in walk-in on the waiting list counts.
	 */
	public function test_attended_follows_check_ins() {
		$event_id = $this->create_event();

		// Without a limit GatherPress promotes the waiting list straight away.
		update_post_meta( $event_id, 'gatherpress_max_attendance_limit', 2 );

		list( $came_id, $came_comment )           = $this->rsvp( $event_id );
		list( $no_show_id, )                      = $this->rsvp( $event_id );
		list( $waitlisted_id, $waitlist_comment ) = $this->rsvp( $event_id, 'waiting_list' );

		$this->assertSame( 'waiting_list', ( new Rsvp( $event_id ) )->get( $waitlisted_id )['status'] );

		$this->toggle( $event_id, $came_comment, true );
		$this->toggle( $event_id, $waitlist_comment, true );

		$this->assertSame( array( $event_id ), array_column( get_past_events( $came_id ), 'event_id' ) );
		$this->assertSame( array( $event_id ), array_column( get_past_events( $waitlisted_id ), 'event_id' ) );
		$this->assertSame( array(), get_past_events( $no_show_id ) );
	}

	/**
	 * On an event nobody was checked in on, an attending RSVP still counts,
	 * and a waiting-list one still doesn't.
	 */
	public function test_attended_falls_back_to_rsvps() {
		$event_id = $this->create_event();
		update_post_meta( $event_id, 'gatherpress_max_attendance_limit', 1 );

		list( $attendee_id, )   = $this->rsvp( $event_id );
		list( $waitlisted_id, ) = $this->rsvp( $event_id, 'waiting_list' );

		$this->assertSame( array( $event_id ), array_column( get_past_events( $attendee_id ), 'event_id' ) );
		$this->assertSame( array(), get_past_events( $waitlisted_id ) );
	}

	/**
	 * The export says yes or no per RSVP on an event with check-ins, and
	 * leaves it unknown on one without.
	 */
	public function test_export_reports_check_ins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$checked_event          = $this->create_event( '-2 days' );
		list( , $came_comment ) = $this->rsvp( $checked_event );
		$this->rsvp( $checked_event );
		$unchecked_event = $this->create_event( '-1 day' );
		$this->rsvp( $unchecked_event );

		$this->toggle( $checked_event, $came_comment, true );

		$events = array_column( collect_export_data()['events'], null, 'id' );

		$this->assertSame( 1, $events[ $checked_event ]['counts']['checked_in'] );
		$this->assertEqualsCanonicalizing( array( true, false ), array_column( $events[ $checked_event ]['rsvps'], 'checked_in' ) );

		$this->assertNull( $events[ $unchecked_event ]['counts']['checked_in'] );
		$this->assertSame( array( null ), array_column( $events[ $unchecked_event ]['rsvps'], 'checked_in' ) );
	}
}
