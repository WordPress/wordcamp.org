<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Rsvp\Rsvp;
use WP_REST_Request;

use function WordCamp\Groups\Frontend\REST\save_rsvp;
use function WordCamp\Groups\Frontend\RSVP_Window\check_rsvp;
use function WordCamp\Groups\Frontend\RSVP_Window\get_state;

use const WordCamp\Groups\Frontend\RSVP_Window\CLOSED;
use const WordCamp\Groups\Frontend\RSVP_Window\CLOSES_META;
use const WordCamp\Groups\Frontend\RSVP_Window\NOT_YET;
use const WordCamp\Groups\Frontend\RSVP_Window\OPEN;
use const WordCamp\Groups\Frontend\RSVP_Window\OPENS_META;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * Opening and closing RSVPs at set times (#2397).
 *
 * @group groups
 */
class Test_Groups_RSVP_Window extends Groups_TestCase {

	/**
	 * A published event starting `$starts` from now.
	 *
	 * @param string $starts A relative time, such as '+30 days'.
	 * @return int Event post ID.
	 */
	private function create_event( string $starts = '+30 days' ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
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
	 * A POST /event/{id}/rsvp request.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $status   Requested status.
	 * @return WP_REST_Request The request.
	 */
	private function rsvp_request( int $event_id, string $status ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', "/wporg-groups/v1/event/{$event_id}/rsvp" );
		$request->set_param( 'id', $event_id );
		$request->set_param( 'status', $status );
		$request->set_param( 'answers', array() );

		return $request;
	}

	/**
	 * Render the `wporg/event-rsvp` block for an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return string Block markup.
	 */
	private function render_rsvp_block( int $event_id ): string {
		global $post;

		$post = get_post( $event_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$output = (string) do_blocks( '<!-- wp:wporg/event-rsvp /-->' );

		wp_reset_postdata();

		return $output;
	}

	/**
	 * Without settings, an event takes RSVPs until it ends, as before.
	 */
	public function test_events_are_open_by_default() {
		$this->assertSame( OPEN, get_state( $this->create_event() ) );
	}

	/**
	 * Both settings are times before the start, so they follow the date.
	 */
	public function test_window_is_relative_to_the_start() {
		$event_id = $this->create_event( '+10 days' );
		update_post_meta( $event_id, OPENS_META, 7 * DAY_IN_SECONDS );
		update_post_meta( $event_id, CLOSES_META, DAY_IN_SECONDS );

		$this->assertSame( NOT_YET, get_state( $event_id ) );
		$this->assertSame( OPEN, get_state( $event_id, strtotime( '+5 days' ) ) );
		$this->assertSame( CLOSED, get_state( $event_id, strtotime( '+9 days +1 hour' ) ) );
	}

	/**
	 * Before RSVPs open, the refusal says when they will.
	 */
	public function test_rsvp_before_the_window_says_when_it_opens() {
		$event_id = $this->create_event( '+30 days' );
		update_post_meta( $event_id, OPENS_META, 7 * DAY_IN_SECONDS );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = save_rsvp( $this->rsvp_request( $event_id, 'attending' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wporg_groups_rsvp_not_open', $result->get_error_code() );
		$this->assertStringContainsString(
			wp_date( 'M j, Y', strtotime( '+23 days' ), new \DateTimeZone( 'UTC' ) ),
			$result->get_error_message()
		);
	}

	/**
	 * After RSVPs close, a new RSVP is refused.
	 */
	public function test_rsvp_after_the_window_is_refused() {
		$event_id = $this->create_event( '+12 hours' );
		update_post_meta( $event_id, CLOSES_META, DAY_IN_SECONDS );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = save_rsvp( $this->rsvp_request( $event_id, 'attending' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wporg_groups_rsvp_closed', $result->get_error_code() );
	}

	/**
	 * Someone already attending can still cancel, and can still save their
	 * answers, which re-sends "attending".
	 */
	public function test_existing_rsvps_still_work_after_the_window_closes() {
		$event_id = $this->create_event( '+12 hours' );
		$user_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		( new Rsvp( $event_id ) )->save( $user_id, 'attending' );
		update_post_meta( $event_id, CLOSES_META, DAY_IN_SECONDS );

		$this->assertTrue( check_rsvp( $event_id, 'attending' ) );

		$result = save_rsvp( $this->rsvp_request( $event_id, 'not_attending' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'not_attending', $result->get_data()['status'] );

		// Having cancelled, they are a new RSVP again.
		$this->assertWPError( check_rsvp( $event_id, 'attending' ) );
	}

	/**
	 * GatherPress's own RSVP route is held to the same window.
	 */
	public function test_gatherpress_route_is_held_to_the_window() {
		$endpoints = apply_filters(
			'rest_endpoints',
			array(
				'/gatherpress/v1/event/rsvp' => array(
					array( 'permission_callback' => '__return_true' ),
				),
			)
		);
		$callback  = $endpoints['/gatherpress/v1/event/rsvp'][0]['permission_callback'];

		$event_id = $this->create_event( '+12 hours' );
		update_post_meta( $event_id, CLOSES_META, DAY_IN_SECONDS );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp' );
		$request->set_param( 'post_id', $event_id );
		$request->set_param( 'status', 'attending' );

		$result = call_user_func( $callback, $request );

		$this->assertWPError( $result );
		$this->assertSame( 'wporg_groups_rsvp_closed', $result->get_error_code() );

		$request->set_param( 'status', 'not_attending' );

		$this->assertTrue( call_user_func( $callback, $request ) );
	}

	/**
	 * Before RSVPs open, the button is disabled and the block says when they
	 * open.
	 */
	public function test_rsvp_block_explains_when_rsvps_open() {
		$event_id = $this->create_event( '+30 days' );
		update_post_meta( $event_id, OPENS_META, 7 * DAY_IN_SECONDS );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$output = $this->render_rsvp_block( $event_id );

		$this->assertStringContainsString( 'RSVPs not open yet', $output );
		$this->assertStringContainsString( 'RSVPs open on', $output );
		$this->assertMatchesRegularExpression( '/<button[^>]*data-wp-on--click="actions.handleRsvpButton"[^>]*\sdisabled[\s=>]/', $output );
	}

	/**
	 * After RSVPs close, an attendee still sees their status and can cancel;
	 * the closed note is there, hidden, for after they cancel.
	 */
	public function test_rsvp_block_keeps_an_attendee_able_to_cancel() {
		$event_id = $this->create_event( '+12 hours' );
		$user_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		( new Rsvp( $event_id ) )->save( $user_id, 'attending' );
		update_post_meta( $event_id, CLOSES_META, DAY_IN_SECONDS );

		$output = $this->render_rsvp_block( $event_id );

		$this->assertMatchesRegularExpression( '/class="wporg-event-rsvp__status"/', $output );
		$this->assertMatchesRegularExpression( '/class="[^"]*\bwporg-event-rsvp__cancel\b[^"]*"/', $output );
		$this->assertMatchesRegularExpression( '/class="wporg-event-rsvp__window is-hidden"/', $output );
		$this->assertDoesNotMatchRegularExpression( '/<button[^>]*data-wp-on--click="actions.handleRsvpButton"[^>]*\sdisabled[\s=>]/', $output );
	}
}
