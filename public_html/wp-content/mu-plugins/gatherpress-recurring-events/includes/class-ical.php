<?php
/**
 * Recurring series in iCalendar feeds and downloads.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Calendar\Cache;
use GatherPress\Core\Calendar\Setup;

defined( 'WPINC' ) || die();

/**
 * A series is one `VEVENT` that recurs, in a feed and in its own download
 * alike: the series' `UID` and URL, its first date as `DTSTART`, its rule as
 * `RRULE`, and an `EXDATE` for each cancelled date. That is the shape
 * GatherPress plans for recurrence (GatherPress/gatherpress#1684), and it
 * keeps a feed the same size however far ahead the dates are projected.
 */
final class Ical {

	/**
	 * Makes every recurring series in a calendar recur.
	 *
	 * GatherPress writes a series as a single event on its first date. This
	 * rewrites each of those in place and leaves every other event alone.
	 *
	 * @param string $calendar A `VCALENDAR` as GatherPress renders it.
	 * @param bool   $unlocked Whether the reader may see a protected series' schedule.
	 * @return string The calendar, unchanged when it holds no series.
	 */
	public static function recur( string $calendar, bool $unlocked = false ): string {
		$zones    = array();
		$calendar = (string) preg_replace_callback(
			'/^BEGIN:VEVENT\r\n.*?\r\nEND:VEVENT/ms',
			static function ( array $matches ) use ( &$zones, $unlocked ): string {
				if ( ! preg_match( '/^UID:gatherpress_(\d+)\r?$/m', $matches[0], $uid ) ) {
					return $matches[0];
				}

				$post_id = (int) $uid[1];

				// A feed is shared by every reader, so it gives away no schedule a password guards.
				if ( ! Rule::is_recurring( $post_id ) || ( ! $unlocked && '' !== get_post_field( 'post_password', $post_id ) ) ) {
					return $matches[0];
				}

				return self::series_event( $post_id, $matches[0], $zones );
			},
			$calendar
		);

		if ( ! $zones || ! preg_match( '/^BEGIN:VEVENT\r\n/m', $calendar, $first, PREG_OFFSET_CAPTURE ) ) {
			return $calendar;
		}

		$components = '';
		foreach ( $zones as $tzid => $zone ) {
			$components .= self::timezone_component( $tzid, $zone['timezone'], $zone['from'] ) . "\r\n";
		}

		return substr_replace( $calendar, $components, $first[0][1], 0 );
	}

	/**
	 * Builds the body of the calendar feed being requested.
	 *
	 * Whatever gets printed along the way is dropped, as GatherPress drops it
	 * for its own feeds. A notice raised while the events are looped over
	 * would otherwise go out ahead of the calendar, and push its end past the
	 * length the response declares.
	 *
	 * @return string The feed, or an empty string when it holds no series and GatherPress can serve it as is.
	 */
	public static function feed_body(): string {
		$setup = Setup::get_instance();

		ob_start();

		try {
			return Cache::get_instance()->remember(
				'gpre:' . $setup->get_ics_cache_key(),
				static function () use ( $setup ): string {
					$body      = $setup->get_ics_body();
					$recurring = self::recur( $body );

					return $recurring === $body ? '' : $recurring;
				}
			);
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Gets ahead of GatherPress on a calendar feed it is about to serve.
	 *
	 * GatherPress hooks its feed template while `template_redirect` runs, and
	 * WordPress refuses a feed nothing is hooked to. Hooking only once
	 * GatherPress has keeps that refusal for every other `/feed/ical/` URL.
	 */
	public static function intercept_feed(): void {
		$hook = 'do_feed_' . Setup::ICAL_SLUG;

		if ( is_feed( Setup::ICAL_SLUG ) && has_action( $hook ) ) {
			add_action( $hook, array( self::class, 'serve_feed' ), 5 );
		}
	}

	/**
	 * Serves a calendar feed that holds a recurring series.
	 *
	 * GatherPress has no filter on a feed's body, so the response is sent from
	 * here, with the headers and validators GatherPress would have sent.
	 */
	public static function serve_feed(): void {
		$body = self::feed_body();

		if ( '' === $body ) {
			return;
		}

		$setup         = Setup::get_instance();
		$filename      = $setup->generate_ics_filename();
		$etag          = $setup->get_etag( $body );
		$last_modified = Cache::get_instance()->get_last_modified();

		$setup->send_ics_headers( $filename, $etag, $last_modified );

		if ( $setup->is_not_modified( $etag, $last_modified ) ) {
			status_header( 304 );
			exit;
		}

		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 5545 payload from GatherPress.
		exit;
	}

	/**
	 * Drops cached calendars when a date is cancelled or restored, or a series ends.
	 *
	 * Neither touches the post or its GatherPress meta, which is what
	 * GatherPress watches to know a calendar changed.
	 */
	public static function mark_changed(): void {
		Cache::get_instance()->mark_changed();
	}

	/**
	 * Turns a series' first-date event into one that recurs.
	 *
	 * GatherPress writes times in UTC, where a rule would repeat at one UTC
	 * time all year: an hour off for half of it wherever clocks change, and
	 * on the wrong weekday where the local day isn't the UTC day. The dates
	 * are written in the series' own timezone instead.
	 *
	 * @param int    $post_id Series post ID.
	 * @param string $event   The series' `VEVENT` as GatherPress renders it.
	 * @param array  $zones   Timezones the calendar has to define, added to as needed.
	 * @return string The recurring `VEVENT`.
	 */
	private static function series_event( int $post_id, string $event, array &$zones ): string {
		$rrule = (string) get_post_meta( $post_id, Rule::META_PREFIX . 'rrule', true );

		if ( '' === $rrule ) {
			return $event;
		}

		$master  = Occurrences::master_datetime( $post_id );
		$all_day = str_contains( $event, "\r\nDTSTART;VALUE=DATE:" );
		$tzid    = $master && ! $all_day ? self::tzid( $master['timezone'] ) : '';

		if ( '' !== $tzid ) {
			$start = $master['start'];
			$event = preg_replace(
				array( '/^DTSTART:[^\r\n]*/m', '/^DTEND:[^\r\n]*/m' ),
				array(
					"DTSTART;TZID={$tzid}:" . $start->format( 'Ymd\THis' ),
					"DTEND;TZID={$tzid}:" . $start->add( $master['duration'] )->format( 'Ymd\THis' ),
				),
				$event,
				1
			);

			$zones[ $tzid ] = array(
				'timezone' => $master['timezone'],
				'from'     => min( $zones[ $tzid ]['from'] ?? PHP_INT_MAX, $start->getTimestamp() ),
			);
		}

		$lines = array( 'RRULE:' . $rrule );
		$rule  = Rule::from_post( $post_id );
		$until = 'until' === $rule['end_type'] ? $rule['until'] : '';

		foreach ( Occurrences::cancelled( $post_id ) as $occurrence ) {
			// Ending a series cancels the dates after its last one, and the rule already stops short of those.
			if ( $until && substr( $occurrence->datetime_start, 0, 10 ) > $until ) {
				continue;
			}

			// An exception has to be written the way `DTSTART` is to match one of its dates.
			if ( $all_day ) {
				$lines[] = 'EXDATE;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $occurrence->datetime_start . ' UTC' ) );
			} elseif ( '' !== $tzid ) {
				$lines[] = "EXDATE;TZID={$tzid}:" . gmdate( 'Ymd\THis', strtotime( $occurrence->datetime_start . ' UTC' ) );
			} else {
				$lines[] = 'EXDATE:' . gmdate( 'Ymd\THis\Z', strtotime( $occurrence->datetime_start_gmt . ' UTC' ) );
			}
		}

		return substr( $event, 0, -strlen( 'END:VEVENT' ) ) . implode( "\r\n", $lines ) . "\r\nEND:VEVENT";
	}

	/**
	 * Names a timezone for a `TZID`.
	 *
	 * @param DateTimeZone $timezone A series' timezone.
	 * @return string The name, or an empty string for UTC, which needs none.
	 */
	private static function tzid( DateTimeZone $timezone ): string {
		$name = $timezone->getName();

		if ( 'UTC' === $name ) {
			return '';
		}

		// A manual offset is spelled `+05:30`, and a colon would end the parameter.
		return preg_match( '/^[+-]\d\d:\d\d$/', $name ) ? 'UTC' . str_replace( ':', '', $name ) : $name;
	}

	/**
	 * Defines a timezone, which a calendar must do for each `TZID` it uses.
	 *
	 * Every clock change is listed on its own date, from the series' first
	 * date to five years out, rather than as a rule of the zone's own.
	 *
	 * @param string       $tzid     Name the events refer to the zone by.
	 * @param DateTimeZone $timezone The zone.
	 * @param int          $from     Timestamp of the earliest date in the zone.
	 * @return string The `VTIMEZONE` component.
	 */
	private static function timezone_component( string $tzid, DateTimeZone $timezone, int $from ): string {
		// A manual offset has no clock changes to list, only the offset it always has.
		$changes = $timezone->getTransitions( $from, time() + 5 * YEAR_IN_SECONDS ) ?: array(
			array(
				'ts'     => $from,
				'offset' => $timezone->getOffset( new DateTimeImmutable( '@' . $from ) ),
				'isdst'  => false,
			),
		);

		$lines  = array( 'BEGIN:VTIMEZONE', 'TZID:' . $tzid );
		$offset = $changes[0]['offset'];

		foreach ( $changes as $index => $change ) {
			$type = $change['isdst'] ? 'DAYLIGHT' : 'STANDARD';

			array_push(
				$lines,
				'BEGIN:' . $type,
				// A change is dated in the local time it leaves behind. The first entry is no change, only where the zone stood.
				'DTSTART:' . ( $index ? gmdate( 'Ymd\THis', $change['ts'] + $offset ) : '19700101T000000' ),
				'TZOFFSETFROM:' . self::utc_offset( $offset ),
				'TZOFFSETTO:' . self::utc_offset( $change['offset'] ),
				'END:' . $type
			);

			$offset = $change['offset'];
		}

		$lines[] = 'END:VTIMEZONE';

		return implode( "\r\n", $lines );
	}

	/**
	 * Formats a UTC offset the way a `VTIMEZONE` spells it.
	 *
	 * @param int $seconds Offset from UTC in seconds.
	 * @return string The offset, e.g. `-0500`.
	 */
	private static function utc_offset( int $seconds ): string {
		return sprintf( '%s%02d%02d', $seconds < 0 ? '-' : '+', intdiv( abs( $seconds ), HOUR_IN_SECONDS ), intdiv( abs( $seconds ) % HOUR_IN_SECONDS, MINUTE_IN_SECONDS ) );
	}
}
