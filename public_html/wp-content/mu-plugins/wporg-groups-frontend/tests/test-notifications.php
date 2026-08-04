<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Frontend\Notifications\schedule_new_event_notification;
use const WordCamp\Groups\Frontend\Notifications\PUBLISH_NOTIFICATION_SCHEDULED_META;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 *
 * Covers `schedule_new_event_notification()` (#1829): scheduling GatherPress's
 * "all members" email exactly once, the first time an event is published.
 */
class Test_Groups_Notifications extends Groups_TestCase {

	/**
	 * The exact `send` shape `schedule_new_event_notification()` always
	 * passes — kept in one place so tests don't repeat it by hand.
	 */
	private function all_members_recipients(): array {
		return array(
			'all'           => true,
			'attending'     => false,
			'waiting_list'  => false,
			'not_attending' => false,
		);
	}

	/**
	 * Whether the "all members" email is currently scheduled for the given event.
	 */
	private function is_notification_scheduled( int $event_id ) {
		return wp_next_scheduled( 'gatherpress_send_emails', array( $event_id, $this->all_members_recipients(), '' ) );
	}

	/**
	 * A first-time `draft` -> `publish` transition on an event schedules
	 * the "all members" email and marks it as scheduled.
	 */
	public function test_schedules_notification_on_first_publish() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);

		schedule_new_event_notification( 'publish', 'draft', get_post( $event_id ) );

		$this->assertNotFalse( $this->is_notification_scheduled( $event_id ) );
		$this->assertSame( '1', get_post_meta( $event_id, PUBLISH_NOTIFICATION_SCHEDULED_META, true ) );
	}

	/**
	 * The "already published" guard: an edit that keeps `post_status` at
	 * `publish` (`$old_status` is also `publish`) must not schedule again.
	 *
	 * Created as `draft`, not `publish` -- the factory's own insert would
	 * otherwise fire the real `transition_post_status` hook as `new` ->
	 * `publish`, which itself schedules the notification and defeats the
	 * point of this test (it's about the function's own `$old_status`
	 * guard, exercised directly, not the real hook).
	 */
	public function test_does_not_reschedule_when_already_published() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);

		schedule_new_event_notification( 'publish', 'publish', get_post( $event_id ) );

		$this->assertFalse( $this->is_notification_scheduled( $event_id ) );
		$this->assertSame( '', get_post_meta( $event_id, PUBLISH_NOTIFICATION_SCHEDULED_META, true ) );
	}

	/**
	 * Publishing a non-event post type (this hook fires for every post,
	 * network-wide) must be a no-op.
	 */
	public function test_ignores_non_event_post_types() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
			)
		);

		schedule_new_event_notification( 'publish', 'draft', get_post( $post_id ) );

		$this->assertFalse( $this->is_notification_scheduled( $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, PUBLISH_NOTIFICATION_SCHEDULED_META, true ) );
	}

	/**
	 * The meta-flag guard on its own, independent of `$old_status`: even a
	 * draft-to-publish transition must not schedule a second time if the
	 * meta is already set (e.g. a previous publish already sent it, then
	 * the event was unpublished and republished).
	 */
	public function test_does_not_reschedule_when_meta_already_set() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $event_id, PUBLISH_NOTIFICATION_SCHEDULED_META, 1 );

		schedule_new_event_notification( 'publish', 'draft', get_post( $event_id ) );

		$this->assertFalse( $this->is_notification_scheduled( $event_id ) );
	}

	/**
	 * End-to-end through the real `transition_post_status` hook (not a
	 * direct function call): publishing schedules exactly one notification,
	 * and a later edit that keeps the event published does not schedule a
	 * second one. Mirrors the PR's own manual test plan (step 4).
	 */
	public function test_publish_then_edit_via_real_hook_does_not_duplicate() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);

		wp_update_post(
			array(
				'ID'          => $event_id,
				'post_status' => 'publish',
			)
		);

		$first_scheduled = $this->is_notification_scheduled( $event_id );
		$this->assertNotFalse( $first_scheduled, 'Publishing should schedule the notification.' );

		// Clear the one scheduled event so the next assertion can tell
		// "a new one was scheduled" apart from "the first one is still there".
		wp_unschedule_event( $first_scheduled, 'gatherpress_send_emails', array( $event_id, $this->all_members_recipients(), '' ) );

		wp_update_post(
			array(
				'ID'           => $event_id,
				'post_status'  => 'publish',
				'post_excerpt' => 'Edited without changing publish state.',
			)
		);

		$this->assertFalse( $this->is_notification_scheduled( $event_id ), 'Editing an already-published event must not schedule another notification.' );
	}

	/**
	 * When `wp_schedule_single_event()` fails (e.g. blocked by a
	 * `pre_schedule_event` filter, simulated here), a warning must surface
	 * rather than the failure being silent, and the meta flag must stay
	 * unset so a later publish can still retry.
	 *
	 * Uses a temporary `set_error_handler()` rather than PHPUnit's
	 * warning-to-exception conversion: this repo's own error handler
	 * (`0-error-handling.php`) is registered ahead of PHPUnit's and handles
	 * `trigger_error()` itself, so nothing reaches PHPUnit as a catchable
	 * exception to assert against.
	 */
	public function test_logs_a_warning_and_leaves_meta_unset_when_scheduling_fails() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);

		add_filter( 'pre_schedule_event', '__return_false' );

		$captured = null;
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intentional, test-only: capturing the warning under test, not debug code.
			static function ( $errno, $errstr ) use ( &$captured ) {
				$captured = array( $errno, $errstr );
				return true;
			}
		);

		try {
			schedule_new_event_notification( 'publish', 'draft', get_post( $event_id ) );
		} finally {
			restore_error_handler();
			remove_filter( 'pre_schedule_event', '__return_false' );
		}

		$this->assertNotNull( $captured, 'schedule_new_event_notification() should have triggered a warning.' );
		$this->assertSame( E_USER_WARNING, $captured[0] );
		$this->assertStringContainsString( (string) $event_id, $captured[1] );

		$this->assertFalse( $this->is_notification_scheduled( $event_id ) );
		$this->assertSame( '', get_post_meta( $event_id, PUBLISH_NOTIFICATION_SCHEDULED_META, true ) );
	}
}
