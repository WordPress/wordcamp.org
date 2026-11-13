<?php
/**
 * Recurring series in iCalendar feeds and downloads.
 *
 * A series is one `VEVENT` with an `RRULE`, the shape GatherPress plans (GatherPress/gatherpress#1684).
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use DateTimeZone;
use GatherPress\Core\Calendar\Cache;
use GatherPress\Core\Calendar\Setup;

defined( 'WPINC' ) || die();

final class Ical {

	/** Makes each series in a calendar GatherPress rendered an event that recurs. */
	public static function recur( string $calendar ): string {
		$zones    = array();
		$calendar = (string) preg_replace_callback(
			'/^BEGIN:VEVENT\r\n.*?\r\nEND:VEVENT/ms',
			static function ( array $matches ) use ( &$zones ): string {
				$is_series = preg_match( '/^UID:gatherpress_(\d+)\r?$/m', $matches[0], $uid ) && Rule::is_recurring( (int) $uid[1] );

				return $is_series ? self::series_event( (int) $uid[1], $matches[0], $zones ) : $matches[0];
			},
			$calendar
		);

		$definitions = '';
		foreach ( $zones as $tzid => $from ) {
			$definitions .= self::timezone_component( $tzid, $from ) . "\r\n";
		}

		// A timezone is defined ahead of the events that use it.
		return (string) preg_replace( '/^BEGIN:VEVENT\r\n/m', $definitions . '$0', $calendar, 1 );
	}

	/** Hands GatherPress the feed through its cache, the one place it reads a feed's body from: it has no filter on it. */
	public static function intercept_feed(): void {
		if ( is_feed( Setup::ICAL_SLUG ) ) {
			$key = Cache::get_instance()->get_versioned_key( Setup::get_instance()->get_ics_cache_key() );

			add_filter( 'pre_transient_' . $key, array( self::class, 'feed_body' ), 10, 0 );
		}
	}

	/** Builds the requested feed with its series recurring, cached the way GatherPress caches its own. */
	public static function feed_body(): string {
		$setup = Setup::get_instance();

		return Cache::get_instance()->remember(
			'gpre:' . $setup->get_ics_cache_key(),
			static fn(): string => self::recur( $setup->get_ical_feed() )
		);
	}

	/** Drops GatherPress's cached calendars: cancelling a date or ending a series changes no post for it to notice. */
	public static function mark_changed(): void {
		Cache::get_instance()->mark_changed();
	}

	/** Adds a series' rule and cancelled dates to its event, with the dates in the series' timezone. */
	private static function series_event( int $post_id, string $event, array &$zones ): string {
		$rrule = (string) get_post_meta( $post_id, Rule::META_PREFIX . 'rrule', true );

		if ( '' === $rrule ) {
			return $event;
		}

		$lines  = array( 'RRULE:' . $rrule );
		$master = Occurrences::master_datetime( $post_id );
		$tzid   = $master ? $master['timezone']->getName() : 'UTC';

		// GatherPress writes UTC, where a weekly rule drifts across clock changes and can name the wrong weekday.
		// Only a named zone has clock changes to define, so UTC and manual offsets stay as they are.
		$local = str_contains( $tzid, '/' );

		if ( $local ) {
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

			$zones[ $tzid ] = min( $zones[ $tzid ] ?? PHP_INT_MAX, $start->getTimestamp() );
		}

		// An exception only matches a date written the way `DTSTART` is.
		foreach ( Occurrences::cancelled( $post_id ) as $occurrence ) {
			$lines[] = $local
				? "EXDATE;TZID={$tzid}:" . $occurrence->recurrence_id
				: 'EXDATE:' . gmdate( 'Ymd\THis\Z', strtotime( $occurrence->datetime_start_gmt . ' UTC' ) );
		}

		return substr( $event, 0, -strlen( 'END:VEVENT' ) ) . implode( "\r\n", $lines ) . "\r\nEND:VEVENT";
	}

	/** Defines a timezone, as a calendar must for each `TZID`, by its clock changes from a date to five years out. */
	private static function timezone_component( string $tzid, int $from ): string {
		$changes = ( new DateTimeZone( $tzid ) )->getTransitions( $from, time() + 5 * YEAR_IN_SECONDS );
		$lines   = array( 'BEGIN:VTIMEZONE', 'TZID:' . $tzid );
		$offset  = $changes[0]['offset'];

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

	/** Formats a UTC offset as `-0500`. */
	private static function utc_offset( int $seconds ): string {
		return sprintf( '%s%02d%02d', $seconds < 0 ? '-' : '+', intdiv( abs( $seconds ), HOUR_IN_SECONDS ), intdiv( abs( $seconds ) % HOUR_IN_SECONDS, MINUTE_IN_SECONDS ) );
	}
}
