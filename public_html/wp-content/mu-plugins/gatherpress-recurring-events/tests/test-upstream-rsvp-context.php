<?php
/**
 * Integration tests for occurrence context on GatherPress's own REST routes.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events\Tests
 */

namespace WordPressdotorg\GatherPress_Recurring_Events\Tests;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Blocks\Rsvp_Template;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Cache;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WordPressdotorg\GatherPress_Recurring_Events\Rsvp_Cache;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * GatherPress's RSVP view modules call `gatherpress/v1/event/*` with a post ID
 * and nothing else, and `rsvp-status-html` re-renders the attendee list from
 * that answer on load. Unscoped, those answers are the whole series' roster
 * (#2072), so these dispatch the real upstream routes the way a browser does
 * and assert the date survives the round trip.
 *
 * @group gatherpress-recurring-events
 */
final class Test_Upstream_Rsvp_Context extends WP_UnitTestCase {

	/** @var string The suite's own REQUEST_URI, restored after each test. */
	private static string $request_uri = '';

	/** Ensure GatherPress tables exist for this suite. */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );

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

	/** Clears request-scoped state that outlives a single dispatch. */
	protected function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		Context::set( null );
		Rsvp_Cache::reset();
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_REFERER'] );
		$_SERVER['REQUEST_URI'] = self::$request_uri;

		parent::tearDown();
	}

	/** Answers with the roster of the date the request names, not the series'. */
	public function test_responses_are_scoped_to_the_requested_occurrence(): void {
		$series = $this->create_series_with_rsvps();

		$first = $this->responses( $series['post_id'], $series['dates'][0]->recurrence_id );
		$this->assertSame( 1, $first['attending']['count'] );
		$this->assertSame(
			array( $series['first_user'] ),
			wp_list_pluck( $first['attending']['records'], 'userId' )
		);

		$second = $this->responses( $series['post_id'], $series['dates'][1]->recurrence_id );
		$this->assertSame( 1, $second['attending']['count'] );
		$this->assertSame(
			array( $series['second_user'] ),
			wp_list_pluck( $second['attending']['records'], 'userId' )
		);
	}

	/**
	 * Falls back to the page the request came from.
	 *
	 * Covers a browser still running a copy of `view.js` from before this
	 * change, or one served from the service worker's asset cache.
	 */
	public function test_responses_fall_back_to_the_referring_occurrence(): void {
		$series = $this->create_series_with_rsvps();

		$_SERVER['REQUEST_URI'] = '/wp-json/gatherpress/v1/event/rsvp-responses';

		// The occurrence permalink, and the query variable the same page is
		// addressed by on a site without pretty permalinks.
		$referers = array(
			home_url( sprintf( '/event/%s/%s/', get_post_field( 'post_name', $series['post_id'] ), $series['dates'][1]->recurrence_id ) ),
			add_query_arg( 'gpre_occurrence', $series['dates'][1]->recurrence_id, get_permalink( $series['post_id'] ) ),
		);

		foreach ( $referers as $referer ) {
			Context::set( null );
			Cache::delete( $series['post_id'] );
			$_SERVER['HTTP_REFERER'] = $referer;

			$data = $this->responses( $series['post_id'] );

			$this->assertSame( 1, $data['attending']['count'], "Roster was not scoped to the referring page: {$referer}" );
			$this->assertSame(
				array( $series['second_user'] ),
				wp_list_pluck( $data['attending']['records'], 'userId' )
			);
		}
	}

	/**
	 * The endpoint behind the bug renders only the requested date's attendees.
	 *
	 * `rsvp-status-html` is what GatherPress's `rsvp-template` view module
	 * calls on every page load, and what it returns replaces the server's own
	 * correctly scoped list -- so this is the assertion that #2072 is fixed
	 * rather than merely scoped somewhere else.
	 */
	public function test_rendered_attendee_markup_is_scoped_to_the_requested_occurrence(): void {
		$series   = $this->create_series_with_rsvps();
		$template = wp_json_encode(
			array(
				'blockName'    => 'gatherpress/rsvp-template',
				'attrs'        => array(),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'core/comment-author-name',
						'attrs'        => array(),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					),
				),
				'innerHTML'    => '',
				'innerContent' => array( null ),
			)
		);

		$request = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp-status-html' );
		$request->set_param( 'post_id', $series['post_id'] );
		$request->set_param( 'status', 'attending' );
		$request->set_param( 'block_data', $template );
		$request->set_param( 'block_signature', Rsvp_Template::sign_template( $template, $series['post_id'] ) );
		$request->set_param( 'limit_enabled', false );
		$request->set_param( 'limit', 8 );
		$request->set_param( 'gpre_occurrence', $series['dates'][1]->recurrence_id );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['responses']['attending']['count'] );
		$this->assertStringContainsString(
			get_userdata( $series['second_user'] )->display_name,
			$data['content']
		);
		$this->assertStringNotContainsString(
			get_userdata( $series['first_user'] )->display_name,
			$data['content'],
			'The rendered list included an attendee from another date, which is the bug this fixes.'
		);
	}

	/** Ignores a recurrence identifier that was never issued for this series. */
	public function test_an_unknown_recurrence_identifier_is_ignored(): void {
		$series = $this->create_series_with_rsvps();

		$data = $this->responses( $series['post_id'], '20991231T235900' );

		// Unscoped, as it was before: refusing outright would break RSVPs for
		// anyone whose cached script sends a stale identifier. What matters is
		// that it neither errors nor answers as though it were a real date.
		$this->assertSame( 2, $data['attending']['count'] );
	}

	/**
	 * Ignores a recurrence identifier that belongs to a different series.
	 *
	 * This is the path that matters if the identifier is ever attacker-chosen,
	 * whether through the parameter or a forged referer: `Occurrences::get()`
	 * pairs it with the series post ID, so one series' date can never scope
	 * another's roster. The two series repeat on different weekdays, so no
	 * identifier of one is accidentally also a date of the other.
	 */
	public function test_a_recurrence_identifier_from_another_series_is_ignored(): void {
		$series = $this->create_series_with_rsvps();
		$other  = $this->create_event( true, 'TU' );
		$theirs = Occurrences::all( $other, 'upcoming' );

		$this->assertNotEmpty( $theirs, 'Precondition: the other series projected some dates.' );
		$this->assertNotContains(
			$theirs[0]->recurrence_id,
			wp_list_pluck( $series['dates'], 'recurrence_id' ),
			'Precondition: the two series must not share a date, or this proves nothing.'
		);

		$data = $this->responses( $series['post_id'], $theirs[0]->recurrence_id );

		// Unscoped, exactly as for any identifier this series never issued.
		$this->assertSame( 2, $data['attending']['count'] );

		// And the request established no context at all. Asserting on the
		// count alone is not enough: `Comments::prepare_query()` would ignore
		// a foreign occurrence anyway, so a resolver that stopped pairing the
		// identifier with the series would still leave the roster looking
		// right while setting context other readers act on.
		$this->assertNull(
			Context::get(),
			"A date belonging to another series was accepted as this one's."
		);
	}

	/**
	 * Reads the series from `comment_post_ID` on the form route.
	 *
	 * The RSVP form posts under that name rather than `post_id`, so it needs
	 * its own branch in `upstream_context()`. The submission itself is refused
	 * here because the Groups network forces open RSVP off, which is why this
	 * asserts on the context the request established rather than on its
	 * outcome.
	 */
	public function test_the_form_route_resolves_its_occurrence_from_comment_post_id(): void {
		$series = $this->create_series_with_rsvps();
		$user   = get_userdata( $series['second_user'] );

		foreach ( array( $series['dates'][1]->recurrence_id, '' ) as $recurrence_id ) {
			Context::set( null );
			Rsvp_Cache::reset();

			$request = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp-form' );
			$request->set_param( 'comment_post_ID', $series['post_id'] );
			$request->set_param( 'author', $user->display_name );
			$request->set_param( 'email', $user->user_email );

			if ( '' !== $recurrence_id ) {
				$request->set_param( 'gpre_occurrence', $recurrence_id );
			}

			rest_do_request( $request );

			$context = Context::get();

			if ( '' === $recurrence_id ) {
				$this->assertNull( $context, 'A form post naming no date must not inherit one.' );
			} else {
				$this->assertNotNull( $context, 'The form route did not resolve its occurrence.' );
				$this->assertSame( $recurrence_id, $context->recurrence_id );
			}
		}
	}

	/**
	 * An RSVP made through GatherPress's own route lands on the date in view.
	 *
	 * Its RSVP block posts to `gatherpress/v1/event/rsvp`, which knows nothing
	 * about occurrences, so without the context those RSVPs went in unmapped
	 * -- and an unmapped RSVP is invisible on every date rather than wrong on
	 * one.
	 */
	public function test_an_rsvp_through_the_upstream_route_is_mapped_to_its_date(): void {
		$series    = $this->create_series_with_rsvps();
		$recurring = $series['dates'][2]->recurrence_id;
		$user_id   = self::factory()->user->create();

		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp' );
		$request->set_param( 'post_id', $series['post_id'] );
		$request->set_param( 'status', 'attending' );
		$request->set_param( 'gpre_occurrence', $recurring );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'Upstream RSVP route did not answer: ' . wp_json_encode( $response->get_data() ) );

		// The roster of the date it was made on, and of no other.
		Context::set( null );
		Rsvp_Cache::reset();
		$this->assertSame(
			array( $user_id ),
			wp_list_pluck( $this->responses( $series['post_id'], $recurring )['attending']['records'], 'userId' ),
			'The RSVP did not land on the date it was made on.'
		);

		Context::set( null );
		Rsvp_Cache::reset();
		$this->assertNotContains(
			$user_id,
			wp_list_pluck( $this->responses( $series['post_id'], $series['dates'][0]->recurrence_id )['attending']['records'], 'userId' ),
			'The RSVP also showed up on a date it was not made on.'
		);
	}

	/**
	 * A roster computed outside REST is guarded too.
	 *
	 * `Rsvp_Cache::guard()` is called from `Comments::prepare_query()` rather
	 * than only from the REST hook precisely so that cron and the notification
	 * emails cannot leave a series-wide roster behind either. Nothing else in
	 * this file would notice if that call were removed.
	 */
	public function test_a_roster_computed_outside_rest_is_not_cached(): void {
		$series = $this->create_series_with_rsvps();

		Context::set( null );
		Rsvp_Cache::reset();
		Cache::delete( $series['post_id'] );

		// What a notification email or a cron job does: ask for the roster
		// with no request and no occurrence anywhere in sight.
		$responses = ( new Event( $series['post_id'] ) )->rsvp->responses();

		$this->assertSame( 2, $responses['attending']['count'], 'Precondition: an unscoped read still answers for the whole series.' );
		$this->assertNull(
			Cache::get( $series['post_id'] ),
			'A series-wide roster computed outside REST was cached where the next occurrence page would be served it.'
		);
	}

	/** An unscoped read leaves no series-wide roster behind for the next visitor. */
	public function test_an_unscoped_read_is_not_cached(): void {
		$series = $this->create_series_with_rsvps();

		$this->assertSame( 2, $this->responses( $series['post_id'] )['attending']['count'] );

		$this->assertNull(
			Cache::get( $series['post_id'] ),
			'A series-wide roster was cached under the series key, where the next occurrence page would be served it.'
		);
	}

	/** An unscoped read also clears a scoped roster rather than being served it. */
	public function test_an_unscoped_read_clears_a_cached_scoped_roster(): void {
		$series = $this->create_series_with_rsvps();

		// What an occurrence page render leaves behind.
		$this->responses( $series['post_id'], $series['dates'][0]->recurrence_id );
		$this->assertNotNull( Cache::get( $series['post_id'] ), 'Precondition: the scoped read cached its roster.' );

		Context::set( null );

		$this->assertSame( 2, $this->responses( $series['post_id'] )['attending']['count'] );
		$this->assertNull( Cache::get( $series['post_id'] ) );
	}

	/** A date's own roster is still cached, so the guard costs nothing when scoped. */
	public function test_a_scoped_read_is_cached(): void {
		$series = $this->create_series_with_rsvps();

		$this->responses( $series['post_id'], $series['dates'][0]->recurrence_id );

		$cached = Cache::get( $series['post_id'] );

		$this->assertIsArray( $cached );
		$this->assertSame( 1, $cached['attending']['count'] );
	}

	/** A one-off event has no dates to confuse, so its cache is untouched. */
	public function test_a_non_recurring_event_is_left_alone(): void {
		$post_id = $this->create_event();
		( new Event( $post_id ) )->rsvp->save( self::factory()->user->create(), 'attending' );

		$this->assertSame( 1, $this->responses( $post_id )['attending']['count'] );
		$this->assertNotNull( Cache::get( $post_id ) );
	}

	/**
	 * Dispatches GatherPress's own roster route.
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Recurrence identifier, or none.
	 * @return array Response payload.
	 */
	private function responses( int $post_id, string $recurrence_id = '' ): array {
		$request = new WP_REST_Request( 'GET', '/gatherpress/v1/event/rsvp-responses' );
		$request->set_param( 'post_id', $post_id );

		if ( '' !== $recurrence_id ) {
			$request->set_param( 'gpre_occurrence', $recurrence_id );
		}

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'Upstream roster route did not answer: ' . wp_json_encode( $response->get_data() ) );

		return $response->get_data()['data'];
	}

	/**
	 * Creates a weekly series with one attendee on each of its first two dates.
	 *
	 * @return array{post_id:int, dates:array, first_user:int, second_user:int}
	 */
	private function create_series_with_rsvps(): array {
		$post_id = $this->create_event( true );
		$dates   = Occurrences::all( $post_id, 'upcoming' );

		$users = array(
			self::factory()->user->create(),
			self::factory()->user->create(),
		);

		foreach ( $users as $index => $user_id ) {
			Context::set( $dates[ $index ] );
			( new Event( $post_id ) )->rsvp->save( $user_id, 'attending' );
		}

		Context::set( null );
		Cache::delete( $post_id );

		return array(
			'post_id'     => $post_id,
			'dates'       => $dates,
			'first_user'  => $users[0],
			'second_user' => $users[1],
		);
	}

	/**
	 * Creates a published event, optionally repeating weekly.
	 *
	 * Dates are relative to whenever the suite runs, so the occurrences are
	 * always in the future and RSVPs are always accepted.
	 *
	 * @param bool   $recurring Whether to give the event a weekly rule.
	 * @param string $weekday   Two-letter weekday the series repeats on.
	 * @return int Event post ID.
	 */
	private function create_event( bool $recurring = false, string $weekday = 'MO' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);

		$days  = array(
			'MO' => 'monday',
			'TU' => 'tuesday',
			'WE' => 'wednesday',
			'TH' => 'thursday',
			'FR' => 'friday',
		);
		$start = new DateTimeImmutable( 'next ' . $days[ $weekday ] . ' 10:00', new DateTimeZone( 'UTC' ) );

		( new Event( $post_id ) )->save_datetimes(
			array(
				'post_id'        => $post_id,
				'datetime_start' => $start->format( 'Y-m-d H:i:s' ),
				'datetime_end'   => $start->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
				'timezone'       => 'UTC',
			)
		);

		if ( $recurring ) {
			update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', 'weekly' );
			update_post_meta( $post_id, Rule::META_PREFIX . 'interval', 1 );
			update_post_meta( $post_id, Rule::META_PREFIX . 'weekdays', array( $weekday ) );
			update_post_meta( $post_id, Rule::META_PREFIX . 'end_type', 'count' );
			update_post_meta( $post_id, Rule::META_PREFIX . 'count', 4 );
		}

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		return $post_id;
	}
}
