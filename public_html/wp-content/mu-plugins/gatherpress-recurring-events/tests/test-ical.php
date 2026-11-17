<?php
/**
 * Tests for recurring series in iCalendar feeds.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events\Tests
 */

namespace WordPressdotorg\GatherPress_Recurring_Events\Tests;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Calendar\Cache;
use GatherPress\Core\Calendar\Endpoint;
use GatherPress\Core\Calendar\Setup;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Event\Query as Event_Query;
use WordPressdotorg\GatherPress_Recurring_Events\Ical;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
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

	/** Feeds are front-end requests, but `WP_ADMIN` is defined for the whole run unless a screen says otherwise. */
	public function set_up() {
		parent::set_up();

		set_current_screen( 'front' );
	}

	/** A series stays in the feed, as one event that recurs, while it has dates to come (#1872). */
	public function test_feed_keeps_a_series_past_its_first_date(): void {
		$series  = $this->create_series();
		$one_off = $this->create_event( '+3 days' );
		$over    = $this->create_event( '-3 days' );
		$start   = $this->start( '-7 days', 'UTC' );
		$feed    = $this->feed();

		$this->assertSame( 1, substr_count( $feed, "UID:gatherpress_{$series}\r\n" ) );
		$this->assertStringContainsString( 'DTSTART:' . $start->format( 'Ymd\THis\Z' ) . "\r\n", $feed );
		$this->assertStringContainsString( 'RRULE:FREQ=WEEKLY;INTERVAL=1;BYDAY=' . $this->weekday( $start ) . "\r\n", $feed );
		$this->assertStringContainsString( 'URL:' . get_permalink( $series ) . "\r\n", $feed );
		$this->assertStringNotContainsString( 'BEGIN:VTIMEZONE', $feed );

		$this->assertStringContainsString( "UID:gatherpress_{$one_off}\r\n", $feed );
		$this->assertStringNotContainsString( "UID:gatherpress_{$over}\r\n", $feed );
		$this->assertSame( 1, substr_count( $feed, 'RRULE:' ) );
	}

	/** A series' dates are in its own timezone: in UTC a weekly rule drifts and can name the wrong weekday. */
	public function test_series_dates_are_written_in_its_timezone(): void {
		$this->create_series( 'America/New_York' );
		$start = $this->start( '-7 days', 'America/New_York' );
		$feed  = $this->feed();

		$this->assertStringContainsString( 'DTSTART;TZID=America/New_York:' . $start->format( 'Ymd\T190000' ) . "\r\n", $feed );
		$this->assertStringContainsString( 'DTEND;TZID=America/New_York:' . $start->format( 'Ymd\T200000' ) . "\r\n", $feed );
		$this->assertStringNotContainsString( 'DTSTART:' . $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' ), $feed );
		$this->assertStringContainsString( 'BYDAY=' . $this->weekday( $start ), $feed );

		$this->assertSame( 1, substr_count( $feed, "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\n" ) );
		$this->assertLessThan( strpos( $feed, 'BEGIN:VEVENT' ), strpos( $feed, 'END:VTIMEZONE' ) );
		$this->assertMatchesRegularExpression( '/BEGIN:STANDARD\r\nDTSTART:\d{8}T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD/', $feed );
		$this->assertMatchesRegularExpression( '/BEGIN:DAYLIGHT\r\nDTSTART:\d{8}T020000\r\nTZOFFSETFROM:-0500\r\nTZOFFSETTO:-0400\r\nEND:DAYLIGHT/', $feed );
	}

	/** A manual offset is a timezone too: on a UTC start the rule's weekday can be the day before or after. */
	public function test_series_on_a_manual_offset_keeps_its_local_start(): void {
		$this->create_series( '-10:00' );
		$start = $this->start( '-7 days', '-10:00' );
		$feed  = $this->feed();

		$this->assertStringContainsString( 'DTSTART;TZID=GMT-1000:' . $start->format( 'Ymd\T190000' ) . "\r\n", $feed );
		$this->assertStringContainsString( 'BYDAY=' . $this->weekday( $start ), $feed );
		$this->assertStringContainsString( "BEGIN:VTIMEZONE\r\nTZID:GMT-1000\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:-1000\r\nTZOFFSETTO:-1000\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n", $feed );
	}

	/**
	 * A cancelled date is an exception written the way the start is, or calendars ignore it.
	 *
	 * @dataProvider provide_exception_formats
	 */
	public function test_cancelled_date_is_an_exception( string $timezone, string $prefix, string $suffix ): void {
		$series    = $this->create_series( $timezone );
		$cancelled = Occurrences::all( $series, 'upcoming', 2 )[1];

		$this->assertStringNotContainsString( 'EXDATE', $this->feed() );

		update_option( Cache::LAST_MODIFIED_OPTION, '2000-01-01 00:00:00' );
		Occurrences::set_status( $series, $cancelled->recurrence_id, 'cancelled' );

		$feed = $this->feed();

		$this->assertSame( 1, substr_count( $feed, 'EXDATE' ) );
		$this->assertStringContainsString( $prefix . $cancelled->recurrence_id . $suffix . "\r\nEND:VEVENT", $feed );
		$this->assertNotSame( '2000-01-01 00:00:00', Cache::get_instance()->get_last_modified(), 'Cancelling a date left cached calendars in place.' );

		Occurrences::set_status( $series, $cancelled->recurrence_id, 'scheduled' );

		$this->assertStringNotContainsString( 'EXDATE', $this->feed() );
	}

	/** A series' timezone, and what an exception's date is written between. */
	public function provide_exception_formats(): array {
		return array(
			'UTC'    => array( 'UTC', 'EXDATE:', 'Z' ),
			'region' => array( 'America/New_York', 'EXDATE;TZID=America/New_York:', '' ),
			'offset' => array( '-10:00', 'EXDATE;TZID=GMT-1000:', '' ),
		);
	}

	/** Ending a series cancels its later dates, so cancelled dates alone don't keep it in the feed. */
	public function test_series_with_only_cancelled_dates_ahead_leaves_the_feed(): void {
		$series = $this->create_series();

		foreach ( Occurrences::all( $series ) as $occurrence ) {
			Occurrences::set_status( $series, $occurrence->recurrence_id, 'cancelled' );
		}

		// From 19:00 to 20:00 UTC today's date is under way: not a later date, but not over, so it keeps the series listed.
		Occurrences::set_status( $series, Occurrences::select_for_series( $series )->recurrence_id, 'cancelled' );

		$this->assertStringNotContainsString( "UID:gatherpress_{$series}\r\n", $this->feed() );
	}

	/** An ID list names a series once, by its nearest date. wp-admin stays on the first date. */
	public function test_id_lists_name_a_series_once(): void {
		$series  = $this->create_series();
		$one_off = $this->create_event( '+3 days' );
		$query   = Event_Query::get_instance();

		$this->assertEqualsCanonicalizing( array( $series, $one_off ), $query->get_events_list( 'upcoming', -1 )->posts );
		$this->assertSame( array( $series ), $query->get_events_list( 'past', -1 )->posts );

		set_current_screen( 'edit-gatherpress_event' );

		$this->assertSame( array( $one_off ), $query->get_events_list( 'upcoming', -1 )->posts );
	}

	/** Output printed while the feed is built is dropped: a notice ahead of the calendar cut its end off. */
	public function test_output_while_building_the_feed_is_dropped(): void {
		$this->create_series();
		$this->request_feed();

		add_action(
			'loop_start',
			static function (): void {
				echo 'Notice: something went wrong.';
			}
		);

		$this->expectOutputString( '' );
		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', Ical::feed_body() );
	}

	/** A site can turn calendar caching off, and its feeds still recur. */
	public function test_feed_recurs_with_caching_off(): void {
		$this->create_series();
		$this->request_feed();

		add_filter( 'gatherpress_calendar_max_age', '__return_zero' );

		$this->assertStringContainsString( 'RRULE:', Ical::feed_body() );
	}

	/** The feed is served ahead of GatherPress's template, going by what GatherPress itself hooks. */
	public function test_feed_is_intercepted_ahead_of_gatherpress(): void {
		global $wp_filter;

		$hook = 'do_feed_' . Setup::ICAL_SLUG;

		$this->request_feed();
		set_query_var( Setup::QUERY_VAR, Setup::ICAL_SLUG );

		$this->assertSame( 20, has_action( 'template_redirect', array( Ical::class, 'intercept_feed' ) ) );

		foreach ( $wp_filter['template_redirect']->callbacks[10] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Endpoint ) {
				call_user_func( $callback['function'] );
			}
		}

		$this->assertTrue( has_action( $hook ), 'Precondition: GatherPress hooked its feed template.' );

		Ical::intercept_feed();

		$this->assertSame( array( 5, 10 ), array_keys( $wp_filter[ $hook ]->callbacks ) );
	}

	/** Makes this a site-wide feed request. `go_to()` would run lookups on sites the suite doesn't create. */
	private function request_feed(): void {
		global $wp_query;

		$wp_query->is_feed = true;
		set_query_var( 'feed', Setup::ICAL_SLUG );
	}

	/** Renders the site-wide feed with its series recurring. */
	private function feed(): string {
		return Ical::recur( Setup::get_instance()->get_ical_feed() );
	}

	/** Gets a 19:00 start relative to today in a timezone. */
	private function start( string $offset, string $timezone ): DateTimeImmutable {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( $timezone ) ) )->modify( $offset )->setTime( 19, 0 );
	}

	/** Gets a date's weekday as a rule spells it. */
	private function weekday( DateTimeImmutable $date ): string {
		return strtoupper( substr( $date->format( 'D' ), 0, 2 ) );
	}

	/** Creates a published one-hour event, a series when given recurrence metadata. */
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

	/** Creates a published weekly series that started a week ago. */
	private function create_series( string $timezone = 'UTC' ): int {
		return $this->create_event(
			'-7 days',
			$timezone,
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'end_type'  => 'never',
				'until'     => '',
			)
		);
	}
}
