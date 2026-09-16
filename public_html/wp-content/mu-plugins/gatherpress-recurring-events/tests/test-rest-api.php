<?php
/**
 * Integration tests for the occurrence-aware REST API.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events\Tests
 */

namespace WordPressdotorg\GatherPress_Recurring_Events\Tests;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Event\Event;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * These hit the real registered routes through rest_do_request(), the same
 * way a browser would — unlike the rest of this suite, which calls plugin
 * classes directly. That matters here specifically: a unit test calling
 * Rest_API::update_rsvp() directly would have passed even with the
 * $event->rsvp->statuses bug this file's own first run caught (a real
 * GatherPress\Core\Rsvp\Rsvp instance doesn't warn on an undefined property
 * access in a way PHPUnit fails on by default) — only actually dispatching
 * the request through WP_REST_Server surfaces the resulting fatal as a
 * failed assertion on the response.
 *
 * @group gatherpress-recurring-events
 */
final class Test_Rest_Api extends WP_UnitTestCase {

	/** Ensure GatherPress tables exist for this suite. */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( class_exists( 'GatherPress\Core\Setup' ) ) {
			\GatherPress\Core\Setup::get_instance()->check_plugin_version();
		}
	}

	/** Spins up a fresh REST server and registers routes for each test. */
	protected function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/** Tears down the REST server between tests. */
	protected function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );

		parent::tearDown();
	}

	/** Lists upcoming occurrence rows for a published recurring series. */
	public function test_occurrences_lists_upcoming_rows_for_a_published_series(): void {
		$post_id = $this->create_future_weekly_series();

		$response = rest_do_request( new WP_REST_Request( 'GET', "/gpre/v1/occurrences/{$post_id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 4, $data );
		$this->assertSame( 'scheduled', $data[0]['status'] );
	}

	/** 404s for an event that doesn't have a recurrence rule. */
	public function test_occurrences_404s_for_a_non_recurring_event(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', "/gpre/v1/occurrences/{$post_id}" ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/** Saves an RSVP for a future occurrence and returns a success response. */
	public function test_update_rsvp_saves_and_returns_success(): void {
		$post_id    = $this->create_future_weekly_series();
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];
		wp_set_current_user( self::factory()->user->create() );

		$request = new WP_REST_Request( 'POST', "/gpre/v1/event/{$occurrence->recurrence_id}/rsvp" );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'status', 'attending' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'], 'Expected a successful RSVP response — got: ' . wp_json_encode( $data ) );
		$this->assertSame( 'attending', $data['status'] );
	}

	/** Rejects an RSVP write once the event is no longer published. */
	public function test_update_rsvp_rejects_an_unpublished_event(): void {
		$post_id    = $this->create_future_weekly_series();
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( self::factory()->user->create() );

		$request = new WP_REST_Request( 'POST', "/gpre/v1/event/{$occurrence->recurrence_id}/rsvp" );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'status', 'attending' );

		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/** Rejects an RSVP write from a logged-out visitor. */
	public function test_update_rsvp_requires_authentication(): void {
		$post_id    = $this->create_future_weekly_series();
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];

		$request = new WP_REST_Request( 'POST', "/gpre/v1/event/{$occurrence->recurrence_id}/rsvp" );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'status', 'attending' );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/** Returns occurrence-scoped RSVP response data. */
	public function test_responses_returns_occurrence_scoped_data(): void {
		$post_id    = $this->create_future_weekly_series();
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];

		$request = new WP_REST_Request( 'GET', "/gpre/v1/event/{$occurrence->recurrence_id}/rsvp-responses" );
		$request->set_param( 'post_id', $post_id );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/** Cancels a future occurrence for an authorized editor. */
	public function test_status_cancels_a_future_occurrence(): void {
		$post_id    = $this->create_future_weekly_series();
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', "/gpre/v1/occurrence/{$post_id}/{$occurrence->recurrence_id}/status" );
		$request->set_param( 'status', 'cancelled' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( 'cancelled', Occurrences::get( $post_id, $occurrence->recurrence_id )->status );
	}

	/** Rejects a status change from a user without edit_post capability. */
	public function test_status_requires_edit_post_capability(): void {
		$post_id    = $this->create_future_weekly_series();
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'POST', "/gpre/v1/occurrence/{$post_id}/{$occurrence->recurrence_id}/status" );
		$request->set_param( 'status', 'cancelled' );

		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'scheduled', Occurrences::get( $post_id, $occurrence->recurrence_id )->status );
	}

	/** Ending a series cancels its later projected occurrences. */
	public function test_end_series_cancels_later_occurrences(): void {
		$post_id     = $this->create_future_weekly_series();
		$occurrences = Occurrences::all( $post_id, 'upcoming' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', "/gpre/v1/series/{$post_id}/{$occurrences[0]->recurrence_id}/end" );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( 'cancelled', Occurrences::get( $post_id, $occurrences[1]->recurrence_id )->status );
	}

	/** Withholds the roster of a password-protected series from a visitor who hasn't unlocked it. */
	public function test_responses_denies_a_password_protected_event(): void {
		$post_id    = $this->create_future_weekly_series( 'secret-pass' );
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];

		$request = new WP_REST_Request( 'GET', "/gpre/v1/event/{$occurrence->recurrence_id}/rsvp-responses" );
		$request->set_param( 'post_id', $post_id );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/** Serves the roster once the visitor has satisfied the password gate. */
	public function test_responses_allows_a_password_protected_event_once_unlocked(): void {
		$post_id    = $this->create_future_weekly_series( 'secret-pass' );
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];
		$this->satisfy_post_password( 'secret-pass' );

		$request = new WP_REST_Request( 'GET', "/gpre/v1/event/{$occurrence->recurrence_id}/rsvp-responses" );
		$request->set_param( 'post_id', $post_id );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/** Refuses an RSVP write from a logged-in user who hasn't unlocked the series. */
	public function test_update_rsvp_denies_a_password_protected_event(): void {
		$post_id    = $this->create_future_weekly_series( 'secret-pass' );
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];
		wp_set_current_user( self::factory()->user->create() );

		$request = new WP_REST_Request( 'POST', "/gpre/v1/event/{$occurrence->recurrence_id}/rsvp" );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'status', 'attending' );

		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/** Withholds the occurrence schedule of a password-protected series. */
	public function test_occurrences_denies_a_password_protected_event(): void {
		$post_id = $this->create_future_weekly_series( 'secret-pass' );

		$response = rest_do_request( new WP_REST_Request( 'GET', "/gpre/v1/occurrences/{$post_id}" ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/** Lets an editor through the password gate on every occurrence-aware read. */
	public function test_password_protected_reads_stay_open_to_editors(): void {
		$post_id    = $this->create_future_weekly_series( 'secret-pass' );
		$occurrence = Occurrences::all( $post_id, 'upcoming' )[0];
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'GET', "/gpre/v1/event/{$occurrence->recurrence_id}/rsvp-responses" );
		$request->set_param( 'post_id', $post_id );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', "/gpre/v1/occurrences/{$post_id}" ) )->get_status() );
	}

	/** Keeps the occurrence schedule readable on a site with RSVPs switched off. */
	public function test_occurrences_survives_rsvp_being_disabled(): void {
		$post_id = $this->create_future_weekly_series();
		remove_post_type_support( 'gatherpress_event', 'gatherpress-rsvp' );

		$response = rest_do_request( new WP_REST_Request( 'GET', "/gpre/v1/occurrences/{$post_id}" ) );

		add_post_type_support( 'gatherpress_event', 'gatherpress-rsvp' );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Plants the cookie WordPress reads to decide a post password has been
	 * entered, the same value wp-login.php?action=postpass would set.
	 *
	 * @param string $password Plain-text post password.
	 */
	private function satisfy_post_password( string $password ): void {
		require_once ABSPATH . WPINC . '/class-phpass.php';

		$hasher = new \PasswordHash( 8, true );

		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( $password );
	}

	/**
	 * Creates a published weekly series whose occurrences are always in the
	 * future relative to whenever the test suite happens to run, backed by
	 * a real GatherPress event date row (Event::save_datetimes()) the same
	 * way the block editor creates one.
	 *
	 * @param string $password Optional post password to gate the series behind.
	 * @return int Series post ID.
	 */
	private function create_future_weekly_series( string $password = '' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'     => 'gatherpress_event',
				'post_status'   => 'draft',
				'post_password' => $password,
			)
		);

		$start = ( new DateTimeImmutable( 'next monday 10:00', new DateTimeZone( 'UTC' ) ) );

		( new Event( $post_id ) )->save_datetimes(
			array(
				'post_id'        => $post_id,
				'datetime_start' => $start->format( 'Y-m-d H:i:s' ),
				'datetime_end'   => $start->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
				'timezone'       => 'UTC',
			)
		);

		update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', 'weekly' );
		update_post_meta( $post_id, Rule::META_PREFIX . 'interval', 1 );
		update_post_meta( $post_id, Rule::META_PREFIX . 'weekdays', array( 'MO' ) );
		update_post_meta( $post_id, Rule::META_PREFIX . 'end_type', 'count' );
		update_post_meta( $post_id, Rule::META_PREFIX . 'count', 4 );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		return $post_id;
	}
}
