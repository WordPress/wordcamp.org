<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Profile_Activity;
use WP_REST_Request;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';
require_once __DIR__ . '/stub-profile-helpers.php';

/**
 * @group groups
 */
class Test_Groups_Profile_Activity extends Groups_TestCase {
	/**
	 * Request bodies sent to Profiles.
	 *
	 * @var array[]
	 */
	protected $requests = array();

	/**
	 * Status code Profiles answers with.
	 *
	 * @var int
	 */
	protected $status = 200;

	/**
	 * Answer Profiles requests locally, on a group that isn't a testing group.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->requests = array();
		$this->status   = 200;

		add_filter( 'pre_http_request', array( $this, 'answer_profiles' ), 10, 3 );
		add_filter( 'wporg_groups_frontend_self_serve_roles_enabled', '__return_false' );
	}

	/**
	 * Drop the filters and any queued jobs.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_profiles' ), 10 );
		remove_filter( 'wporg_groups_frontend_self_serve_roles_enabled', '__return_false' );
		wp_unschedule_hook( Profile_Activity\SYNC_HOOK );
		wp_unschedule_hook( Profile_Activity\RETRACT_HOOK );

		parent::tearDown();
	}

	/**
	 * Record a Profiles request and answer it with `$this->status`.
	 *
	 * @param false|array $pre  Short-circuit value.
	 * @param array       $args Request arguments.
	 * @param string      $url  Request URL.
	 * @return false|array
	 */
	public function answer_profiles( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://profiles.wordpress.org/' ) ) {
			return $pre;
		}

		$this->requests[] = $args['body'];

		return array(
			'headers'  => array(),
			'body'     => 200 === $this->status ? '1' : '-1 nope',
			'response' => array( 'code' => $this->status ),
			'cookies'  => array(),
		);
	}

	/**
	 * Run the queued sync and retract jobs, as cron would once they're due.
	 * Retries, an hour out, are left alone.
	 */
	protected function run_cron(): void {
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( $timestamp > time() + Profile_Activity\SYNC_DELAY ) {
				continue;
			}

			foreach ( array( Profile_Activity\SYNC_HOOK, Profile_Activity\RETRACT_HOOK ) as $hook ) {
				foreach ( $hooks[ $hook ] ?? array() as $job ) {
					wp_unschedule_event( $timestamp, $hook, $job['args'] );
					do_action_ref_array( $hook, $job['args'] );
				}
			}
		}
	}

	/**
	 * Publish an event with the given speakers.
	 *
	 * @param int[] $speakers User IDs.
	 * @return int Event post ID.
	 */
	protected function create_event( array $speakers ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => 'Meetup Night',
			)
		);

		update_post_meta( $event_id, Profile_Activity\SPEAKERS_META, $speakers );

		return $event_id;
	}

	/**
	 * The `command`/`user` pairs sent so far.
	 *
	 * @return string[]
	 */
	protected function sent(): array {
		return array_map(
			static fn( array $body ): string => $body['command'] . ' ' . $body['user'],
			$this->requests
		);
	}

	/**
	 * Publishing an event adds each speaker once, with the event's details.
	 */
	public function test_publishing_adds_each_speaker_once() {
		$speaker  = self::factory()->user->create();
		$event_id = $this->create_event( array( $speaker ) );

		$this->run_cron();

		$this->assertCount( 1, $this->requests );
		$this->assertSame(
			array(
				'action'      => 'wporg_handle_activity',
				'source'      => 'groups',
				'command'     => 'add',
				'role'        => 'speaker',
				'user'        => $speaker,
				'event_id'    => $event_id,
				'group_id'    => get_current_blog_id(),
				'event_title' => 'Meetup Night',
				'url'         => get_permalink( $event_id ),
				'group_name'  => get_bloginfo( 'name' ),
				'group_url'   => home_url( '/' ),
			),
			$this->requests[0]
		);
		$this->assertArrayHasKey( $speaker, Profile_Activity\get_synced( $event_id ) );

		Profile_Activity\sync( $event_id );

		$this->assertCount( 1, $this->requests, 'A sync with nothing changed sends nothing.' );
	}

	/**
	 * The Group Settings form publishes the event, then saves its speakers in
	 * a second request. The sync has to wait for both.
	 */
	public function test_speakers_saved_after_publishing_are_added() {
		$speaker = self::factory()->user->create();
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_title'  => 'Meetup Night',
			)
		);
		update_post_meta( $event_id, Profile_Activity\SPEAKERS_META, array() );

		// Core's tearDown() unregisters meta keys after every test.
		\WordCamp\Groups\GatherPress_Tweaks\register_event_speakers_meta();
		wp_set_current_user( $editor );

		$request = new WP_REST_Request( 'POST', '/wp/v2/gatherpress_events/' . $event_id );
		$request->set_body_params( array( 'meta' => array( '_event_speakers' => array( $speaker ) ) ) );
		rest_do_request( $request );

		wp_set_current_user( 0 );

		$queued = wp_next_scheduled( Profile_Activity\SYNC_HOOK, array( $event_id ) );
		$this->assertGreaterThan( time(), $queued, 'The sync is queued for later, not for now.' );

		$this->run_cron();
		$this->assertSame( array( 'add ' . $speaker ), $this->sent() );
	}

	/**
	 * Removing a speaker, renaming, protecting and unpublishing the event each
	 * bring Profiles back in line.
	 */
	public function test_event_changes_update_profile_activity() {
		$first    = self::factory()->user->create();
		$second   = self::factory()->user->create();
		$event_id = $this->create_event( array( $first, $second ) );
		$this->run_cron();
		$this->requests = array();

		update_post_meta( $event_id, Profile_Activity\SPEAKERS_META, array( $first ) );
		$this->run_cron();
		$this->assertSame( array( 'remove ' . $second ), $this->sent() );

		$this->requests = array();
		wp_update_post(
			array(
				'ID'         => $event_id,
				'post_title' => 'Meetup Night, Renamed',
			)
		);
		$this->run_cron();
		$this->assertSame( array( 'add ' . $first ), $this->sent() );
		$this->assertSame( 'Meetup Night, Renamed', $this->requests[0]['event_title'] );

		$this->requests = array();
		wp_update_post(
			array(
				'ID'            => $event_id,
				'post_password' => 'secret',
			)
		);
		$this->run_cron();
		$this->assertSame( array( 'remove ' . $first ), $this->sent() );

		$this->requests = array();
		wp_update_post(
			array(
				'ID'            => $event_id,
				'post_password' => '',
			)
		);
		$this->run_cron();
		wp_update_post(
			array(
				'ID'          => $event_id,
				'post_status' => 'draft',
			)
		);
		$this->run_cron();
		$this->assertSame( array( 'add ' . $first, 'remove ' . $first ), $this->sent() );
		$this->assertSame( array(), Profile_Activity\get_synced( $event_id ) );
	}

	/**
	 * Only group members are added, but a speaker who leaves the group later
	 * keeps their activity.
	 */
	public function test_only_members_are_added_and_past_speakers_stay() {
		$member     = self::factory()->user->create();
		$non_member = self::factory()->user->create();
		remove_user_from_blog( $non_member, get_current_blog_id() );

		$event_id = $this->create_event( array( $member, $non_member ) );
		$this->run_cron();
		$this->assertSame( array( 'add ' . $member ), $this->sent() );

		$this->requests = array();
		remove_user_from_blog( $member, get_current_blog_id() );
		wp_update_post(
			array(
				'ID'         => $event_id,
				'post_title' => 'Meetup Night, Renamed',
			)
		);
		$this->run_cron();

		$this->assertSame( array( 'add ' . $member ), $this->sent() );
		$this->assertSame( array( $member, $non_member ), get_post_meta( $event_id, Profile_Activity\SPEAKERS_META, true ) );
	}

	/**
	 * Testing groups, where anyone can become an organizer, send nothing.
	 */
	public function test_testing_groups_send_nothing() {
		remove_filter( 'wporg_groups_frontend_self_serve_roles_enabled', '__return_false' );

		$speaker = self::factory()->user->create();
		$this->create_event( array( $speaker ) );
		$this->run_cron();

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A failed add is retried in an hour, and stays pending so a later sync
	 * can remove it if Profiles saved it anyway.
	 */
	public function test_failed_adds_are_retried_and_can_be_removed() {
		$speaker = self::factory()->user->create();

		$this->status = 500;
		$event_id     = $this->create_event( array( $speaker ) );
		$this->run_cron();

		$retry = wp_next_scheduled( Profile_Activity\SYNC_HOOK, array( $event_id, 2 ) );
		$this->assertGreaterThanOrEqual( time() + HOUR_IN_SECONDS - 5, $retry );

		$this->status   = 200;
		$this->requests = array();
		wp_update_post(
			array(
				'ID'            => $event_id,
				'post_password' => 'secret',
			)
		);
		$this->run_cron();

		$this->assertSame( array( 'remove ' . $speaker ), $this->sent() );
		$this->assertSame( array(), Profile_Activity\get_synced( $event_id ) );
	}

	/**
	 * A rejected request isn't retried, and the last allowed try gives up.
	 */
	public function test_rejected_and_final_tries_are_not_retried() {
		$speaker  = self::factory()->user->create();
		$event_id = $this->create_event( array( $speaker ) );
		wp_unschedule_hook( Profile_Activity\SYNC_HOOK );

		set_error_handler( static fn(): bool => true, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only: silences the expected warnings.

		$this->status = 400;
		Profile_Activity\sync( $event_id );

		$this->status = 500;
		Profile_Activity\sync( $event_id, Profile_Activity\MAX_ATTEMPTS );

		restore_error_handler();

		$this->assertCount( 2, $this->requests );
		$this->assertFalse( wp_next_scheduled( Profile_Activity\SYNC_HOOK, array( $event_id, 2 ) ) );
		$this->assertFalse( wp_next_scheduled( Profile_Activity\SYNC_HOOK, array( $event_id, Profile_Activity\MAX_ATTEMPTS + 1 ) ) );
	}

	/**
	 * Deleting an event outright removes its profile activity.
	 */
	public function test_deleting_an_event_retracts_its_activity() {
		$speaker  = self::factory()->user->create();
		$event_id = $this->create_event( array( $speaker ) );
		$this->run_cron();
		$this->requests = array();

		wp_delete_post( $event_id, true );
		$this->run_cron();

		$this->assertSame( array( 'remove ' . $speaker ), $this->sent() );
	}
}
