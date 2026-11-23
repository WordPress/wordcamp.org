<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Profile_Credit;
use WP_REST_Request;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';
require_once __DIR__ . '/stub-profile-helpers.php';

/**
 * @group groups
 */
class Test_Groups_Profile_Credit extends Groups_TestCase {
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
	 * Answer Profiles requests locally.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->requests = array();
		$this->status   = 200;

		add_filter( 'pre_http_request', array( $this, 'answer_profiles' ), 10, 3 );
	}

	/**
	 * Drop the filter and any queued jobs.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_profiles' ), 10 );
		wp_unschedule_hook( Profile_Credit\RECONCILE_HOOK );
		wp_unschedule_hook( Profile_Credit\RETRACT_HOOK );

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
	 * Run the due credit jobs, as cron would.
	 */
	protected function run_cron(): void {
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( $timestamp > time() ) {
				continue;
			}

			foreach ( array( Profile_Credit\RECONCILE_HOOK, Profile_Credit\RETRACT_HOOK ) as $hook ) {
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

		update_post_meta( $event_id, Profile_Credit\SPEAKERS_META, $speakers );

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
	 * Speakers saved through the REST API are limited to group members.
	 */
	public function test_speakers_saved_over_rest_are_group_members() {
		$member     = self::factory()->user->create();
		$non_member = self::factory()->user->create();
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$event_id   = self::factory()->post->create( array( 'post_type' => 'gatherpress_event' ) );

		// Core's tearDown() unregisters meta keys after every test.
		\WordCamp\Groups\GatherPress_Tweaks\register_event_speakers_meta();
		remove_user_from_blog( $non_member, get_current_blog_id() );
		wp_set_current_user( $editor );

		$request = new WP_REST_Request( 'POST', '/wp/v2/gatherpress_events/' . $event_id );
		$request->set_body_params( array( 'meta' => array( '_event_speakers' => array( $member, $non_member, $member ) ) ) );
		$response = rest_do_request( $request );

		wp_set_current_user( 0 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $member ), get_post_meta( $event_id, '_event_speakers', true ) );
	}

	/**
	 * Publishing an event credits each speaker once, with the event's details.
	 */
	public function test_publishing_credits_each_speaker_once() {
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
		$this->assertArrayHasKey( $speaker, Profile_Credit\get_credits( $event_id ) );

		// Saving again without a change sends nothing.
		update_post_meta( $event_id, '_event_speakers', array( $speaker ) );
		wp_update_post( array(
			'ID' => $event_id, 'post_content' => 'More details.',
		) );
		$this->run_cron();

		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Removing a speaker, renaming, protecting and unpublishing the event each
	 * bring Profiles back in line.
	 */
	public function test_event_changes_update_the_credits() {
		$first    = self::factory()->user->create();
		$second   = self::factory()->user->create();
		$event_id = $this->create_event( array( $first, $second ) );
		$this->run_cron();
		$this->requests = array();

		update_post_meta( $event_id, '_event_speakers', array( $first ) );
		$this->run_cron();
		$this->assertSame( array( 'remove ' . $second ), $this->sent() );

		$this->requests = array();
		wp_update_post( array(
			'ID' => $event_id, 'post_title' => 'Meetup Night, Renamed',
		) );
		$this->run_cron();
		$this->assertSame( array( 'add ' . $first ), $this->sent() );
		$this->assertSame( 'Meetup Night, Renamed', $this->requests[0]['event_title'] );

		$this->requests = array();
		wp_update_post( array(
			'ID' => $event_id, 'post_password' => 'secret',
		) );
		$this->run_cron();
		$this->assertSame( array( 'remove ' . $first ), $this->sent() );

		$this->requests = array();
		wp_update_post( array(
			'ID' => $event_id, 'post_password' => '',
		) );
		$this->run_cron();
		wp_update_post( array(
			'ID' => $event_id, 'post_status' => 'draft',
		) );
		$this->run_cron();
		$this->assertSame( array( 'add ' . $first, 'remove ' . $first ), $this->sent() );
		$this->assertSame( array(), Profile_Credit\get_credits( $event_id ) );
	}

	/**
	 * A failed request leaves the credits alone and is tried again in an hour.
	 */
	public function test_failed_requests_are_retried() {
		$speaker = self::factory()->user->create();

		$this->status = 500;
		$event_id     = $this->create_event( array( $speaker ) );
		$this->run_cron();

		$this->assertSame( array(), Profile_Credit\get_credits( $event_id ) );
		$this->assertNotFalse( wp_next_scheduled( Profile_Credit\RECONCILE_HOOK, array( $event_id, 2 ) ) );
	}

	/**
	 * Rejected requests schedule no retry.
	 */
	public function test_rejected_requests_are_not_retried() {
		$speaker  = self::factory()->user->create();
		$event_id = $this->create_event( array( $speaker ) );
		wp_unschedule_hook( Profile_Credit\RECONCILE_HOOK );

		$this->status = 400;
		set_error_handler( static fn(): bool => true, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only: silences the expected warning.
		Profile_Credit\reconcile( $event_id );
		restore_error_handler();

		$this->assertCount( 1, $this->requests );
		$this->assertFalse( wp_next_scheduled( Profile_Credit\RECONCILE_HOOK, array( $event_id, 2 ) ) );
		$this->assertSame( array(), Profile_Credit\get_credits( $event_id ) );
	}

	/**
	 * Deleting an event outright removes its credits.
	 */
	public function test_deleting_an_event_retracts_its_credits() {
		$speaker  = self::factory()->user->create();
		$event_id = $this->create_event( array( $speaker ) );
		$this->run_cron();
		$this->requests = array();

		wp_delete_post( $event_id, true );
		$this->run_cron();

		$this->assertSame( array( 'remove ' . $speaker ), $this->sent() );
	}
}
