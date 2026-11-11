<?php
/**
 * Tests for recurring series in iCalendar feeds and downloads.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events\Tests
 */

namespace WordPressdotorg\GatherPress_Recurring_Events\Tests;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Calendar\Cache;
use GatherPress\Core\Calendar\Calendar;
use GatherPress\Core\Calendar\Endpoint;
use GatherPress\Core\Calendar\Setup;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Event\Query as Event_Query;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Ical;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WordPressdotorg\GatherPress_Recurring_Events\Plugin;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group gatherpress-recurring-events
 */
final class Test_Ical extends WP_UnitTestCase {

	/** Ensure GatherPress tables exist for this suite. */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( class_exists( 'GatherPress\Core\Setup' ) ) {
			\GatherPress\Core\Setup::get_instance()->check_plugin_version();
		}
	}

	/**
	 * Feeds are front-end requests, rendered fresh for each assertion.
	 *
	 * `wordcamp-remote-css/tests/bootstrap.php` defines `WP_ADMIN` for the
	 * whole run, so `is_admin()` is true unless a screen says otherwise.
	 */
	public function set_up() {
		parent::set_up();

		set_current_screen( 'front' );
		add_filter( 'gatherpress_calendar_max_age', '__return_zero' );
	}

	/** Leave the globals as we found them for whatever runs next. */
	public function tear_down() {
		unset( $GLOBALS['current_screen'] );
		Context::set( null );

		parent::tear_down();
	}

	/**
	 * A series stays in the feed while it has dates to come (#1872).
	 *
	 * The feed listed a series on its first date alone, so it left once that
	 * had passed, however many dates were still ahead.
	 */
	public function test_feed_keeps_a_series_past_its_first_date(): void {
		$series  = $this->create_series();
		$one_off = $this->create_event( '+3 days' );
		$over    = $this->create_event( '-3 days' );
		$start   = $this->start( '-7 days', 'UTC' );
		$feed    = $this->feed();

		$this->assertSame( 1, substr_count( $feed, "UID:gatherpress_{$series}\r\n" ), 'A series is one event in the feed.' );
		$this->assertStringContainsString( 'DTSTART:' . $start->format( 'Ymd\THis\Z' ) . "\r\n", $feed );
		$this->assertStringContainsString( 'RRULE:FREQ=WEEKLY;INTERVAL=1;BYDAY=' . $this->weekday( $start ) . "\r\n", $feed );
		$this->assertStringContainsString( 'URL:' . get_permalink( $series ) . "\r\n", $feed, 'A series links to its own page, not to one of its dates.' );
		$this->assertStringNotContainsString( 'BEGIN:VTIMEZONE', $feed, 'UTC needs no definition.' );

		$this->assertStringContainsString( "UID:gatherpress_{$one_off}\r\n", $feed );
		$this->assertStringNotContainsString( "UID:gatherpress_{$over}\r\n", $feed );
		$this->assertSame( 1, substr_count( $feed, 'RRULE:' ), 'Only the series recurs.' );
	}

	/**
	 * A series' dates are written in its own timezone.
	 *
	 * In UTC the rule would repeat at one UTC time all year. 19:00 in New York
	 * is the next day in UTC, so `BYDAY` would name the wrong weekday too.
	 */
	public function test_series_dates_are_written_in_its_timezone(): void {
		$this->create_series( 'America/New_York' );
		$start = $this->start( '-7 days', 'America/New_York' );
		$feed  = $this->feed();

		$this->assertStringContainsString( 'DTSTART;TZID=America/New_York:' . $start->format( 'Ymd\T190000' ) . "\r\n", $feed );
		$this->assertStringContainsString( 'DTEND;TZID=America/New_York:' . $start->format( 'Ymd\T200000' ) . "\r\n", $feed );
		$this->assertStringNotContainsString( "\r\nDTSTART:", $this->events( $feed )[0], 'The UTC start GatherPress wrote is replaced, not added to.' );
		$this->assertStringContainsString( 'BYDAY=' . $this->weekday( $start ), $feed );

		$this->assertSame( 1, substr_count( $feed, "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\n" ) );
		$this->assertLessThan( strpos( $feed, 'BEGIN:VEVENT' ), strpos( $feed, 'END:VTIMEZONE' ), 'A timezone is defined before the events that use it.' );
		$this->assertMatchesRegularExpression( '/BEGIN:STANDARD\r\nDTSTART:\d{8}T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD/', $feed );
		$this->assertMatchesRegularExpression( '/BEGIN:DAYLIGHT\r\nDTSTART:\d{8}T020000\r\nTZOFFSETFROM:-0500\r\nTZOFFSETTO:-0400\r\nEND:DAYLIGHT/', $feed );
	}

	/** A manual offset is defined under a name a `TZID` can hold. */
	public function test_manual_offset_is_defined_as_a_fixed_zone(): void {
		$this->create_series( '+05:30' );
		$feed = $this->feed();

		$this->assertStringContainsString( 'DTSTART;TZID=UTC+0530:', $feed );
		$this->assertStringContainsString(
			"BEGIN:VTIMEZONE\r\nTZID:UTC+0530\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:+0530\r\nTZOFFSETTO:+0530\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n",
			$feed
		);
	}

	/** Two series in one timezone share its definition. */
	public function test_a_timezone_is_defined_once(): void {
		$this->create_series( 'Europe/Berlin' );
		$this->create_series( 'Europe/Berlin' );
		$feed = $this->feed();

		$this->assertSame( 2, substr_count( $feed, 'RRULE:' ) );
		$this->assertSame( 1, substr_count( $feed, 'BEGIN:VTIMEZONE' ) );
	}

	/**
	 * A cancelled date is an exception to the rule, in the timezone the dates are in.
	 *
	 * @dataProvider provide_exception_formats
	 *
	 * @param string $timezone Series timezone.
	 * @param string $prefix   Property and parameters an exception is written with.
	 * @param string $suffix   What follows the date.
	 */
	public function test_cancelled_date_is_an_exception( string $timezone, string $prefix, string $suffix ): void {
		$series    = $this->create_series( $timezone );
		$cancelled = Occurrences::all( $series, 'upcoming', 2 )[1];

		$this->assertStringNotContainsString( 'EXDATE', $this->feed() );

		update_option( Cache::LAST_MODIFIED_OPTION, '2000-01-01 00:00:00' );
		Occurrences::set_status( $series, $cancelled->recurrence_id, 'cancelled' );

		$this->assertSame( 1, substr_count( $this->feed(), 'EXDATE' ) );
		$this->assertStringContainsString( $prefix . $cancelled->recurrence_id . $suffix . "\r\nEND:VEVENT", $this->feed() );
		$this->assertNotSame( '2000-01-01 00:00:00', Cache::get_instance()->get_last_modified(), 'Cancelling a date left cached calendars in place.' );

		Occurrences::set_status( $series, $cancelled->recurrence_id, 'scheduled' );

		$this->assertStringNotContainsString( 'EXDATE', $this->feed() );
	}

	/** Data provider: an exception as each kind of timezone writes it. */
	public function provide_exception_formats(): array {
		return array(
			'UTC'    => array( 'UTC', 'EXDATE:', 'Z' ),
			'region' => array( 'America/New_York', 'EXDATE;TZID=America/New_York:', '' ),
		);
	}

	/**
	 * An ended series stops at its last date, with no exceptions after it.
	 *
	 * Ending a series cancels the dates projected past its end. The rule's
	 * `UNTIL` already leaves those out.
	 */
	public function test_ended_series_lists_no_exceptions_past_its_end(): void {
		$series = $this->create_series();
		$last   = Occurrences::all( $series, 'upcoming', 2 )[1];

		update_option( Cache::LAST_MODIFIED_OPTION, '2000-01-01 00:00:00' );
		$this->assertTrue( Occurrences::end_after( $series, $last->recurrence_id ) );

		$feed = $this->feed();

		$this->assertStringContainsString( 'UNTIL=' . substr( $last->recurrence_id, 0, 8 ) . 'T235959Z', $feed );
		$this->assertStringNotContainsString( 'EXDATE', $feed );
		$this->assertNotSame( '2000-01-01 00:00:00', Cache::get_instance()->get_last_modified(), 'Ending a series left cached calendars in place.' );
	}

	/** A series with nothing left but cancelled dates leaves the feed. */
	public function test_series_with_only_cancelled_dates_ahead_leaves_the_feed(): void {
		$series = $this->create_series();

		foreach ( Occurrences::all( $series ) as $occurrence ) {
			Occurrences::set_status( $series, $occurrence->recurrence_id, 'cancelled' );
		}

		$this->assertStringNotContainsString( "UID:gatherpress_{$series}\r\n", $this->feed() );
	}

	/**
	 * A protected series' schedule stays out of the feed.
	 *
	 * The feed is shared by every reader. GatherPress leaves protected events
	 * out of it since 0.36.1, and listed them on their own date before that.
	 * Either way the series' rule isn't in it, and it isn't kept in the feed
	 * by dates only its password shows.
	 */
	public function test_protected_series_keeps_its_schedule_out_of_the_feed(): void {
		$started  = $this->create_series();
		$upcoming = $this->create_series( 'UTC', '+2 days' );

		foreach ( array( $started, $upcoming ) as $series ) {
			wp_update_post(
				array(
					'ID'            => $series,
					'post_password' => 'secret',
				)
			);
		}

		$feed = $this->feed();

		$this->assertStringNotContainsString( "UID:gatherpress_{$started}\r\n", $feed );
		$this->assertStringNotContainsString( 'RRULE', $feed );

		// A list that does name protected events places the series by its first date, the one date it shows.
		$this->assertSame( array( $upcoming ), Event_Query::get_instance()->get_events_list( 'upcoming', -1 )->posts );

		$calendar = "BEGIN:VCALENDAR\r\n" . ( new Calendar( $upcoming ) )->get_ical_event_string() . "\r\nEND:VCALENDAR";

		$this->assertSame( $calendar, Ical::recur( $calendar ), 'A protected series recurred in a calendar anyone can read.' );
		$this->assertStringContainsString( 'RRULE:FREQ=WEEKLY', Ical::recur( $calendar, true ) );
	}

	/**
	 * A list of event IDs names a series once, by its nearest date either way.
	 *
	 * Front-end lists only: wp-admin keeps one row per series on its first
	 * date, since a date has no edit screen of its own.
	 */
	public function test_id_lists_name_a_series_once(): void {
		$series  = $this->create_series();
		$one_off = $this->create_event( '+3 days' );
		$query   = Event_Query::get_instance();

		$this->assertEqualsCanonicalizing( array( $series, $one_off ), $query->get_events_list( 'upcoming', -1 )->posts );
		$this->assertSame( array( $series ), $query->get_events_list( 'past', -1 )->posts );

		set_current_screen( 'edit-gatherpress_event' );

		$this->assertSame( array( $one_off ), $query->get_events_list( 'upcoming', -1 )->posts );
	}

	/** The feed is only taken over from GatherPress when it holds a series. */
	public function test_feed_body_is_empty_without_a_series(): void {
		$this->create_event( '+3 days' );
		$this->request_feed();

		$this->assertSame( '', Ical::feed_body() );

		$this->create_series();

		$this->assertStringContainsString( 'RRULE:FREQ=WEEKLY', Ical::feed_body() );
		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', Ical::feed_body() );
	}

	/**
	 * Nothing printed while the feed is built reaches the response.
	 *
	 * The groups theme raises a notice on every loop over event IDs, which
	 * landed ahead of the calendar and cut its end off.
	 */
	public function test_output_while_building_the_feed_is_dropped(): void {
		$this->create_series();
		$this->request_feed();

		$print = static function (): void {
			echo 'Notice: something went wrong.';
		};
		add_action( 'loop_start', $print );

		$this->expectOutputString( '' );

		try {
			$this->assertStringStartsWith( 'BEGIN:VCALENDAR', Ical::feed_body() );
		} finally {
			remove_action( 'loop_start', $print );
		}
	}

	/**
	 * The feed is only served from here when GatherPress would serve it.
	 *
	 * WordPress refuses a feed nothing is hooked to, which is how a
	 * `/feed/ical/` URL that isn't a calendar gets turned away.
	 */
	public function test_feed_is_intercepted_only_behind_gatherpress(): void {
		$hook     = 'do_feed_' . Setup::ICAL_SLUG;
		$callback = array( Ical::class, 'serve_feed' );

		$this->request_feed();

		try {
			Ical::intercept_feed();
			$this->assertFalse( has_action( $hook, $callback ), 'Precondition: GatherPress has hooked no template yet.' );

			add_action( $hook, '__return_null' );
			Ical::intercept_feed();
			$this->assertSame( 5, has_action( $hook, $callback ) );
		} finally {
			remove_action( $hook, '__return_null' );
			remove_action( $hook, $callback, 5 );
		}
	}

	/**
	 * The feed is served from here before GatherPress's own template runs.
	 *
	 * Run through GatherPress's own `template_redirect` handlers, since the
	 * interception depends on what they hook and when.
	 */
	public function test_feed_is_intercepted_ahead_of_gatherpress(): void {
		global $wp_filter;

		$hook = 'do_feed_' . Setup::ICAL_SLUG;

		$this->request_feed();
		set_query_var( Setup::QUERY_VAR, Setup::ICAL_SLUG );

		$this->assertSame( 20, has_action( 'template_redirect', array( Ical::class, 'intercept_feed' ) ) );

		try {
			foreach ( $wp_filter['template_redirect']->callbacks[10] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Endpoint ) {
					call_user_func( $callback['function'] );
				}
			}

			$this->assertTrue( has_action( $hook ), 'Precondition: GatherPress hooked its feed template.' );

			Ical::intercept_feed();

			$this->assertSame( array( 5, 10 ), array_keys( $wp_filter[ $hook ]->callbacks ) );
		} finally {
			remove_all_actions( $hook );
			set_query_var( Setup::QUERY_VAR, '' );
		}
	}

	/** A series' own download is the same event its feed entry is. */
	public function test_series_download_matches_its_feed_entry(): void {
		global $wp_query;

		$series = $this->create_series( 'America/New_York' );
		Occurrences::set_status( $series, Occurrences::all( $series, 'upcoming', 1 )[0]->recurrence_id, 'cancelled' );

		$wp_query->queried_object    = get_post( $series );
		$wp_query->queried_object_id = $series;
		set_query_var( 'gatherpress_calendar', 'ical' );

		try {
			$download = Plugin::series_ical_body();
		} finally {
			set_query_var( 'gatherpress_calendar', '' );
			$wp_query->queried_object    = null;
			$wp_query->queried_object_id = 0;
		}

		$this->assertSame( $this->events( $this->feed() ), $this->events( $download ) );
		$this->assertStringContainsString( "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\n", $download );
		$this->assertStringContainsString( 'EXDATE;TZID=America/New_York:', $download );
	}

	/**
	 * Makes the current request one for the site-wide calendar feed.
	 *
	 * Set on the query rather than through `go_to()`, which runs `wp` and
	 * with it lookups on sites this suite doesn't create.
	 */
	private function request_feed(): void {
		global $wp_query;

		$wp_query->is_feed = true;
		set_query_var( 'feed', Setup::ICAL_SLUG );

		$this->assertTrue( is_feed( Setup::ICAL_SLUG ), 'Precondition: this is a calendar feed request.' );
	}

	/**
	 * Renders the site-wide calendar feed the way a subscriber gets it.
	 *
	 * @return string Feed body.
	 */
	private function feed(): string {
		return Ical::recur( Setup::get_instance()->get_ical_feed() );
	}

	/**
	 * Picks the events out of a calendar.
	 *
	 * @param string $calendar Calendar body.
	 * @return string[] `VEVENT` components.
	 */
	private function events( string $calendar ): array {
		preg_match_all( '/^BEGIN:VEVENT\r\n.*?\r\nEND:VEVENT/ms', $calendar, $matches );

		return $matches[0];
	}

	/**
	 * Gets a 19:00 start relative to today in a timezone.
	 *
	 * @param string $offset   Relative date of the start.
	 * @param string $timezone Timezone name or offset.
	 * @return DateTimeImmutable Start.
	 */
	private function start( string $offset, string $timezone ): DateTimeImmutable {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( $timezone ) ) )->modify( $offset )->setTime( 19, 0 );
	}

	/**
	 * Gets a date's weekday as a rule spells it.
	 *
	 * @param DateTimeImmutable $date Date.
	 * @return string Two-letter weekday.
	 */
	private function weekday( DateTimeImmutable $date ): string {
		return strtoupper( substr( $date->format( 'D' ), 0, 2 ) );
	}

	/**
	 * Creates a published one-hour event.
	 *
	 * @param string $offset   Relative date of the event.
	 * @param string $timezone Timezone name or offset.
	 * @param array  $meta     Recurrence metadata, for a series.
	 * @return int Event post ID.
	 */
	private function create_event( string $offset, string $timezone = 'UTC', array $meta = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);
		$start   = $this->start( $offset, $timezone );

		( new Event( $post_id ) )->save_datetimes(
			array(
				'post_id'        => $post_id,
				'datetime_start' => $start->format( 'Y-m-d H:i:s' ),
				'datetime_end'   => $start->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
				'timezone'       => $timezone,
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, Rule::META_PREFIX . $key, $value );
		}

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		return $post_id;
	}

	/**
	 * Creates a published weekly series with its occurrences projected.
	 *
	 * @param string $timezone Timezone name or offset.
	 * @param string $offset   Relative date of the first occurrence.
	 * @return int Series post ID.
	 */
	private function create_series( string $timezone = 'UTC', string $offset = '-7 days' ): int {
		return $this->create_event(
			$offset,
			$timezone,
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( $this->weekday( $this->start( $offset, $timezone ) ) ),
				'end_type'  => 'never',
				'until'     => '',
			)
		);
	}
}
