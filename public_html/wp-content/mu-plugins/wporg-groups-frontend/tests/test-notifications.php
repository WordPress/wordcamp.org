<?php

namespace WordCamp\Groups\Tests;

use WP_REST_Request;

use function WordCamp\Groups\Frontend\Notifications\pending_notification_queue;
use function WordCamp\Groups\Frontend\Notifications\schedule_new_event_notification;
use function WordCamp\Groups\Frontend\Notifications\send_pending_new_event_notifications;
use function WordCamp\Groups\Frontend\REST\publish_draft;
use const WordCamp\Groups\Frontend\Notifications\PUBLISH_NOTIFICATION_SCHEDULED_META;
use const WordCamp\Groups\Frontend\Notifications\GATHERPRESS_OPT_IN_META_KEY;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 *
 * Covers `schedule_new_event_notification()` (#1829): sending GatherPress's
 * "all members" email exactly once, synchronously, the first time an event
 * is published.
 */
class Test_Groups_Notifications extends Groups_TestCase {

	/**
	 * Emails captured during the current test, via `pre_wp_mail`.
	 *
	 * @var array[]
	 */
	protected $sent_mail = array();

	/**
	 * Intercept outgoing mail, and re-add the real hook this class's own
	 * `Groups_TestCase::setUp()` removes for every other test in the suite
	 * (see the comment there).
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->sent_mail = array();

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		add_action( 'transition_post_status', 'WordCamp\Groups\Frontend\Notifications\schedule_new_event_notification', 10, 3 );
	}

	/**
	 * Remove the mail interceptor and the hook re-added above. Draining the
	 * request-static queue keeps events enqueued here from leaking into the
	 * next test (or into PHPUnit's own real shutdown).
	 */
	protected function tearDown(): void {
		pending_notification_queue( null, true );
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		remove_action( 'transition_post_status', 'WordCamp\Groups\Frontend\Notifications\schedule_new_event_notification', 10 );

		parent::tearDown();
	}

	/**
	 * Record mail instead of sending it.
	 *
	 * @param null|bool $short_circuit Whether to short-circuit `wp_mail()`.
	 * @param array     $atts          `wp_mail()` arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ) {
		$this->sent_mail[] = $atts;

		return true;
	}

	/**
	 * Whether the "all members" notification has been sent for the given event.
	 *
	 * `send_pending_new_event_notifications()` calls `Rest_Api::send_emails()`
	 * directly and synchronously (no cron hand-off — see its own docblock),
	 * so the post meta flag it sets on success is the authoritative signal,
	 * same as before.
	 */
	private function notification_was_sent( int $event_id ): bool {
		return '1' === get_post_meta( $event_id, PUBLISH_NOTIFICATION_SCHEDULED_META, true );
	}

	/**
	 * An event with saved datetimes, so the drain's dateless guard passes.
	 * Creating it as `publish` enqueues it through the real hook.
	 *
	 * @param string $status Post status.
	 * @return int
	 */
	private function create_dated_event( string $status = 'publish' ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => $status,
			)
		);

		( new \GatherPress\Core\Event\Event( $event_id ) )->save_datetimes(
			array(
				'post_id'        => $event_id,
				'datetime_start' => '2031-05-20 10:00:00',
				'datetime_end'   => '2031-05-20 12:00:00',
				'timezone'       => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * A group member the drain will email (no opt-in meta: the default is opted-in),
	 * so mail-count assertions don't depend on incidental fixture-site users.
	 *
	 * @return int
	 */
	private function create_member(): int {
		$user_id = self::factory()->user->create();
		add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );

		return $user_id;
	}

	/**
	 * A first-time `draft` -> `publish` transition on an event queues the
	 * "all members" email; nothing sends inside the publish request itself,
	 * and the shutdown drain then sends and marks it as sent.
	 */
	public function test_sends_notification_on_first_publish() {
		$this->create_member();
		$event_id = $this->create_dated_event( 'draft' );

		wp_update_post(
			array(
				'ID'          => $event_id,
				'post_status' => 'publish',
			)
		);

		$this->assertEmpty( $this->sent_mail, 'Nothing may send inside the publish request itself.' );
		$this->assertFalse( $this->notification_was_sent( $event_id ) );

		send_pending_new_event_notifications();

		$this->assertTrue( $this->notification_was_sent( $event_id ) );
		$this->assertNotEmpty( $this->sent_mail );
	}

	/**
	 * The "already published" guard: an edit that keeps `post_status` at
	 * `publish` (`$old_status` is also `publish`) must not send again.
	 *
	 * Created as `draft`, not `publish` -- the factory's own insert would
	 * otherwise fire the real `transition_post_status` hook as `new` ->
	 * `publish`, which itself sends the notification and defeats the
	 * point of this test (it's about the function's own `$old_status`
	 * guard, exercised directly, not the real hook).
	 */
	public function test_does_not_resend_when_already_published() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);

		schedule_new_event_notification( 'publish', 'publish', get_post( $event_id ) );
		send_pending_new_event_notifications();

		$this->assertFalse( $this->notification_was_sent( $event_id ) );
		$this->assertEmpty( $this->sent_mail );
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
		send_pending_new_event_notifications();

		$this->assertFalse( $this->notification_was_sent( $post_id ) );
		$this->assertEmpty( $this->sent_mail );
	}

	/**
	 * The meta-flag guard on its own, independent of `$old_status`: even a
	 * draft-to-publish transition must not send a second time if the meta
	 * is already set (e.g. a previous publish already sent it, then the
	 * event was unpublished and republished).
	 */
	public function test_does_not_resend_when_meta_already_set() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $event_id, PUBLISH_NOTIFICATION_SCHEDULED_META, 1 );

		schedule_new_event_notification( 'publish', 'draft', get_post( $event_id ) );
		send_pending_new_event_notifications();

		$this->assertEmpty( $this->sent_mail );
	}

	/**
	 * End-to-end through the real `transition_post_status` hook (not a
	 * direct function call): publishing sends exactly one notification, and
	 * a later edit that keeps the event published does not send a second
	 * one. Mirrors the PR's own manual test plan (step 4).
	 */
	public function test_publish_then_edit_via_real_hook_does_not_duplicate() {
		$this->create_member();
		$event_id = $this->create_dated_event( 'draft' );

		wp_update_post(
			array(
				'ID'          => $event_id,
				'post_status' => 'publish',
			)
		);
		send_pending_new_event_notifications();

		$this->assertTrue( $this->notification_was_sent( $event_id ), 'Publishing should send the notification.' );
		$count_after_publish = count( $this->sent_mail );
		$this->assertGreaterThan( 0, $count_after_publish );

		wp_update_post(
			array(
				'ID'           => $event_id,
				'post_status'  => 'publish',
				'post_excerpt' => 'Edited without changing publish state.',
			)
		);
		send_pending_new_event_notifications();

		$this->assertSame(
			$count_after_publish,
			count( $this->sent_mail ),
			'Editing an already-published event must not send another notification.'
		);
	}

	/**
	 * WordPress core's `wp_schedule_single_event()` cron store is an
	 * unsynchronized read-modify-write of a single `cron` option -- two
	 * events publishing close together can silently clobber each other's
	 * scheduled job, at any point up until wp-cron actually gets around to
	 * running it (see `send_pending_new_event_notifications()`'s own docblock).
	 * This is why that path calls `Rest_Api::send_emails()` directly and
	 * synchronously instead: confirm nothing in the enqueue-plus-drain flow
	 * goes through `wp_schedule_single_event()` / the `gatherpress_send_emails`
	 * cron hook for this at all, since a regression back to that dispatch
	 * path would silently reintroduce the race.
	 */
	public function test_does_not_use_wp_cron() {
		$this->create_dated_event();
		send_pending_new_event_notifications();

		$this->assertFalse(
			wp_next_scheduled( 'gatherpress_send_emails' ),
			'The notification must be sent synchronously, not handed off to wp-cron.'
		);
	}

	/**
	 * An event that reaches the drain without saved datetimes must be
	 * skipped with a warning rather than emailed dateless -- and the meta
	 * flag must stay unset so the failure is not recorded as a send.
	 *
	 * Uses a temporary `set_error_handler()` rather than PHPUnit's
	 * warning-to-exception conversion: this repo's own error handler
	 * (`0-error-handling.php`) is registered ahead of PHPUnit's and handles
	 * `trigger_error()` itself, so nothing reaches PHPUnit as a catchable
	 * exception to assert against.
	 */
	public function test_dateless_event_is_skipped_with_warning() {
		$this->create_member();
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		$captured = null;
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intentional, test-only: capturing the warning under test, not debug code.
			static function ( $errno, $errstr ) use ( &$captured ) {
				$captured = array( $errno, $errstr );
				return true;
			}
		);

		try {
			send_pending_new_event_notifications();
		} finally {
			restore_error_handler();
		}

		$this->assertNotNull( $captured, 'send_pending_new_event_notifications() should have triggered a warning.' );
		$this->assertSame( E_USER_WARNING, $captured[0] );
		$this->assertStringContainsString( (string) $event_id, $captured[1] );

		$this->assertEmpty( $this->sent_mail );
		$this->assertSame( '', get_post_meta( $event_id, PUBLISH_NOTIFICATION_SCHEDULED_META, true ) );
	}

	/**
	 * The regression this file exists for (#1862 follow-up): the email must
	 * render the data the publish request persisted, not whatever the draft
	 * held when `transition_post_status` fired inside `wp_insert_post()`.
	 * Publishes through the real REST path (`publish_draft()` ->
	 * `persist_event()`) with a draft that carries stale datetime meta.
	 */
	public function test_rest_publish_sends_the_persisted_data() {
		$this->create_member();
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);
		( new \GatherPress\Core\Event\Event( $draft_id ) )->save_datetimes(
			array(
				'post_id'        => $draft_id,
				'datetime_start' => '2030-01-01 10:00:00',
				'datetime_end'   => '2030-01-01 12:00:00',
				'timezone'       => 'UTC',
			)
		);

		$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/draft/publish' );
		$request->set_param( 'id', $draft_id );
		$request->set_param( 'title', 'Persisted Data Event' );
		$request->set_param( 'date', '2031-05-20' );
		$request->set_param( 'time_start', '10:00' );
		$request->set_param( 'time_end', '12:00' );
		$request->set_param( 'new_venue_name', 'Persisted Venue' );
		$request->set_param( 'new_venue_address', '1 Persisted St' );

		$response = publish_draft( $request );
		$this->assertNotWPError( $response );
		$this->assertEmpty( $this->sent_mail, 'Nothing may send inside the publish request itself.' );

		send_pending_new_event_notifications();

		$this->assertTrue( $this->notification_was_sent( $draft_id ) );
		$this->assertNotEmpty( $this->sent_mail );

		$body = $this->sent_mail[0]['message'];
		$this->assertStringContainsString( 'May 20, 2031', $body );
		$this->assertStringContainsString( 'Persisted Venue', $body );
		$this->assertStringNotContainsString( 'January 1, 2030', $body );
	}

	/**
	 * One drain sends everything queued in the request, exactly once.
	 */
	public function test_drain_consumes_the_queue() {
		$this->create_member();
		$first_id  = $this->create_dated_event();
		$second_id = $this->create_dated_event();

		send_pending_new_event_notifications();

		$this->assertTrue( $this->notification_was_sent( $first_id ) );
		$this->assertTrue( $this->notification_was_sent( $second_id ) );
		$count_after_drain = count( $this->sent_mail );
		$this->assertGreaterThan( 0, $count_after_drain );

		send_pending_new_event_notifications();

		$this->assertSame( $count_after_drain, count( $this->sent_mail ) );
	}

	/**
	 * Event-updates opt-in is scoped per group: each group site keeps an
	 * independent value, because GatherPress's own
	 * `gatherpress_event_updates_opt_in` user meta is network-wide (not
	 * per-site) and would otherwise leak a member's choice on one group to
	 * every other group they belong to.
	 */
	public function test_opt_in_preference_is_isolated_per_group() {
		$user_id = self::factory()->user->create();

		update_user_meta( $user_id, GATHERPRESS_OPT_IN_META_KEY, 1 );
		$this->assertSame( '1', get_user_meta( $user_id, GATHERPRESS_OPT_IN_META_KEY, true ) );

		$other_group_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/other-group/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		switch_to_blog( $other_group_id );
		\GatherPress\Core\Setup::get_instance()->check_plugin_version();

		update_user_meta( $user_id, GATHERPRESS_OPT_IN_META_KEY, 0 );
		$this->assertSame(
			'0',
			get_user_meta( $user_id, GATHERPRESS_OPT_IN_META_KEY, true ),
			"The other group must not inherit the first group's opt-in."
		);

		restore_current_blog();

		$this->assertSame(
			'1',
			get_user_meta( $user_id, GATHERPRESS_OPT_IN_META_KEY, true ),
			"Switching back must not have lost the first group's opt-in."
		);

		wp_delete_site( $other_group_id );
	}

	/**
	 * Until a member makes an explicit choice on a given group's site, the
	 * preference falls back to any pre-existing network-wide value, so
	 * moving to per-group storage doesn't silently reset existing opt-outs.
	 */
	public function test_opt_in_falls_back_to_legacy_global_value_when_unset_for_this_group() {
		$user_id = self::factory()->user->create();

		// `add_user_meta()` bypasses the `update_user_metadata` redirect --
		// it fires a different, unhooked filter -- so this seeds the real
		// legacy network-wide meta key directly, as if it were written
		// before per-group scoping existed.
		add_user_meta( $user_id, GATHERPRESS_OPT_IN_META_KEY, '0' );

		$this->assertSame( '0', get_user_meta( $user_id, GATHERPRESS_OPT_IN_META_KEY, true ) );
		$this->assertFalse( \GatherPress\Core\User::get_instance()->has_event_updates_opt_in( $user_id ) );
	}

	/**
	 * A group-specific override, once made, takes priority over the legacy
	 * network-wide value for that group -- read through GatherPress's own
	 * `has_event_updates_opt_in()`, the actual integration point that
	 * decides whether an email goes out.
	 */
	public function test_opt_in_override_takes_priority_over_legacy_value_through_gatherpress_core() {
		$user_id = self::factory()->user->create();

		add_user_meta( $user_id, GATHERPRESS_OPT_IN_META_KEY, '1' );
		update_user_meta( $user_id, GATHERPRESS_OPT_IN_META_KEY, 0 );

		$this->assertFalse( \GatherPress\Core\User::get_instance()->has_event_updates_opt_in( $user_id ) );
	}
}
