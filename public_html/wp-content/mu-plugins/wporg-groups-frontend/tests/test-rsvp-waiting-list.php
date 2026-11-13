<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Rsvp;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * How the RSVP block and My events show the waiting list.
 *
 * @group groups
 */
class Test_Groups_RSVP_Waiting_List extends Groups_TestCase {

	/**
	 * A future event with room for one, already taken.
	 */
	private function create_full_event( string $title = 'Full meetup', int $start_offset_days = 30 ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		( new Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => gmdate( 'Y-m-d H:i:s', strtotime( "+{$start_offset_days} days" ) ),
				'datetime_end'   => gmdate( 'Y-m-d H:i:s', strtotime( "+{$start_offset_days} days +2 hours" ) ),
				'timezone'       => 'UTC',
			)
		);

		update_post_meta( $event_id, 'gatherpress_capacity', 1 );
		$this->rsvp_as( $event_id, $this->create_member( 'Seat Holder' ) );

		return $event_id;
	}

	/**
	 * A subscriber of the current site.
	 */
	private function create_member( string $name ): int {
		return self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => $name,
			)
		);
	}

	/**
	 * RSVP as `attending`, which GatherPress turns into `waiting_list` when full.
	 */
	private function rsvp_as( int $event_id, int $user_id ): string {
		wp_set_current_user( $user_id );

		return ( new Rsvp( $event_id ) )->save( $user_id, 'attending' )['status'];
	}

	/**
	 * Render the RSVP block as the current user.
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
	 * Someone on the waiting list is told so, and offered a way off it.
	 */
	public function test_waitlisted_member_sees_their_status() {
		$event_id  = $this->create_full_event();
		$member_id = $this->create_member( 'Waiting Member' );

		$this->assertSame( 'waiting_list', $this->rsvp_as( $event_id, $member_id ) );

		$output = $this->render_rsvp_block( $event_id );

		$this->assertSame( 'On the waiting list', $this->main_button_label( $output ) );
		$this->assertMatchesRegularExpression( '/wporg-event-rsvp__cancel"[^>]*>\s*Leave waiting list\s*</', $output );
		$this->assertMatchesRegularExpression( '/wporg-event-rsvp__modal-status"[^>]*>\s*You&#039;re on the waiting list\./', $output );
		$this->assertMatchesRegularExpression( '/class="wp-block-button is-style-outline is-waiting"/', $output );
	}

	/**
	 * A full event says so, and the button says what it will do.
	 */
	public function test_full_event_offers_the_waiting_list() {
		$event_id = $this->create_full_event();
		$this->rsvp_as( $event_id, $this->create_member( 'Waiting Member' ) );

		wp_set_current_user( $this->create_member( 'Newcomer' ) );
		$output = $this->render_rsvp_block( $event_id );

		$this->assertSame( 'Join waiting list', $this->main_button_label( $output ) );
		$this->assertMatchesRegularExpression( '/class="wporg-event-rsvp__waiting"[^>]*>Full · 1 on the waiting list</', $output );
	}

	/**
	 * With room left there's no waiting line and the button is the usual one.
	 */
	public function test_event_with_room_has_no_waiting_line() {
		$event_id = $this->create_full_event();
		update_post_meta( $event_id, 'gatherpress_capacity', 10 );

		wp_set_current_user( $this->create_member( 'Newcomer' ) );
		$output = $this->render_rsvp_block( $event_id );

		$this->assertStringContainsString( 'class="wporg-event-rsvp__waiting is-hidden"', $output );
		$this->assertSame( 'RSVP', $this->main_button_label( $output ) );
	}

	/**
	 * The text of the block's main RSVP button.
	 */
	private function main_button_label( string $output ): string {
		preg_match( '/<button[^>]*wp-block-button__link[^>]*>(.*?)<\/button>/s', $output, $matches );

		return trim( $matches[1] ?? '' );
	}

	/**
	 * Organizers see who is waiting; members only see how many.
	 */
	public function test_waiting_names_are_for_organizers_only() {
		$event_id = $this->create_full_event();
		$this->rsvp_as( $event_id, $this->create_member( 'Patient Person' ) );

		wp_set_current_user( $this->create_member( 'Curious Member' ) );
		$member_view = $this->render_rsvp_block( $event_id );

		$this->assertStringNotContainsString( 'Patient Person', $member_view );
		$this->assertStringNotContainsString( 'Waiting list (1)', $member_view );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$organizer_view = $this->render_rsvp_block( $event_id );

		$this->assertStringContainsString( 'Waiting list (1)', $organizer_view );
		$this->assertStringContainsString( 'Patient Person', $organizer_view );
	}

	/**
	 * Organizers see the waiting list in the order it will be promoted.
	 */
	public function test_waiting_list_is_in_queue_order() {
		$event_id = $this->create_full_event();
		$this->rsvp_as( $event_id, $this->create_member( 'Zed First In Line' ) );
		$this->rsvp_as( $event_id, $this->create_member( 'Amy Second In Line' ) );

		// The RSVP timestamps share a second; spread them so the order is unambiguous.
		foreach ( get_comments( array(
			'post_id' => $event_id, 'type' => 'gatherpress_rsvp',
		) ) as $comment ) {
			$name = get_userdata( (int) $comment->user_id )->display_name;
			$date = str_starts_with( $name, 'Zed' ) ? '2026-01-01 10:00:00' : '2026-01-01 11:00:00';
			wp_update_comment( array(
				'comment_ID' => $comment->comment_ID, 'comment_date' => $date, 'comment_date_gmt' => $date,
			) );
		}
		\GatherPress\Core\Rsvp\Cache::delete( $event_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$output = $this->render_rsvp_block( $event_id );

		$this->assertLessThan( strpos( $output, 'Amy Second In Line' ), strpos( $output, 'Zed First In Line' ) );
	}

	/**
	 * A waitlisted date stays in My upcoming events, marked as such.
	 */
	public function test_my_events_lists_a_waitlisted_date() {
		$event_id  = $this->create_full_event( 'Popular workshop' );
		$member_id = $this->create_member( 'Waiting Member' );
		$this->rsvp_as( $event_id, $member_id );

		$output = do_blocks( '<!-- wp:wporg/my-events /-->' );

		$this->assertStringContainsString( 'My upcoming events', $output );
		$this->assertStringContainsString( 'Popular workshop', $output );
		$this->assertStringContainsString( 'wporg-my-events__status', $output );
	}

	/**
	 * Attending dates don't get the tag.
	 */
	public function test_my_events_does_not_tag_an_attending_date() {
		$event_id = $this->create_full_event( 'Roomy workshop' );
		update_post_meta( $event_id, 'gatherpress_capacity', 10 );
		$this->rsvp_as( $event_id, $this->create_member( 'Attending Member' ) );

		$output = do_blocks( '<!-- wp:wporg/my-events /-->' );

		$this->assertStringContainsString( 'Roomy workshop', $output );
		$this->assertStringNotContainsString( 'wporg-my-events__status', $output );
	}
}
