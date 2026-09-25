<?php
/**
 * Supported recurrence rule expansion.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

defined( 'WPINC' ) || die();

final class Rule {

	const META_PREFIX = '_gpre_';

	/**
	 * Checks whether an event has a supported recurrence frequency.
	 *
	 * @param int $post_id Event post ID.
	 * @return bool Whether the event recurs.
	 */
	public static function is_recurring( int $post_id ): bool {
		return in_array( get_post_meta( $post_id, self::META_PREFIX . 'frequency', true ), self::frequencies(), true );
	}

	/** Gets the supported recurrence frequencies. */
	public static function frequencies(): array {
		return array( 'weekly', 'monthly', 'yearly' );
	}

	/**
	 * Reads a normalized recurrence rule from event metadata.
	 *
	 * @param int $post_id Event post ID.
	 * @return array Normalized rule.
	 */
	public static function from_post( int $post_id ): array {
		$weekdays        = get_post_meta( $post_id, self::META_PREFIX . 'weekdays', true );
		$monthly_weekday = strtoupper( sanitize_key( get_post_meta( $post_id, self::META_PREFIX . 'monthly_weekday', true ) ) );

		return array(
			'frequency'       => sanitize_key( get_post_meta( $post_id, self::META_PREFIX . 'frequency', true ) ),
			'interval'        => max( 1, (int) get_post_meta( $post_id, self::META_PREFIX . 'interval', true ) ),
			'weekdays'        => is_array( $weekdays ) ? array_values( array_intersect( self::weekdays(), $weekdays ) ) : array(),
			'monthly_mode'    => sanitize_key( get_post_meta( $post_id, self::META_PREFIX . 'monthly_mode', true ) ),
			'monthly_day'     => (int) get_post_meta( $post_id, self::META_PREFIX . 'monthly_day', true ),
			'monthly_order'   => sanitize_key( get_post_meta( $post_id, self::META_PREFIX . 'monthly_order', true ) ),
			'monthly_weekday' => in_array( $monthly_weekday, self::weekdays(), true ) ? $monthly_weekday : 'MO',
			'end_type'        => sanitize_key( get_post_meta( $post_id, self::META_PREFIX . 'end_type', true ) ),
			'until'           => sanitize_text_field( get_post_meta( $post_id, self::META_PREFIX . 'until', true ) ),
			'count'           => max( 1, (int) get_post_meta( $post_id, self::META_PREFIX . 'count', true ) ),
		);
	}

	/** Gets RFC weekday abbreviations in week order. */
	public static function weekdays(): array {
		return array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' );
	}

	/**
	 * Creates an RFC-compatible recurrence identifier.
	 *
	 * @param DateTimeImmutable $start   Occurrence start.
	 * @param bool              $all_day Whether the occurrence is all-day.
	 * @return string Recurrence identifier.
	 */
	public static function recurrence_id( DateTimeImmutable $start, bool $all_day = false ): string {
		return $start->format( $all_day ? 'Ymd' : 'Ymd\\THis' );
	}

	/**
	 * Serializes the supported rule subset as an RRULE value.
	 *
	 * @param array             $rule  Normalized rule.
	 * @param DateTimeImmutable $start Series start.
	 * @return string RRULE value without the property name.
	 */
	public static function to_rrule( array $rule, DateTimeImmutable $start ): string {
		$parts = array(
			'FREQ=' . strtoupper( $rule['frequency'] ),
			'INTERVAL=' . max( 1, (int) $rule['interval'] ),
		);

		if ( 'weekly' === $rule['frequency'] ) {
			$days    = $rule['weekdays'] ?: array( self::weekday_code( $start ) );
			$parts[] = 'BYDAY=' . implode( ',', $days );
		} elseif ( 'monthly' === $rule['frequency'] ) {
			if ( 'weekday' === $rule['monthly_mode'] ) {
				$orders  = array(
					'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'last' => -1,
				);
				$order   = $orders[ $rule['monthly_order'] ] ?? 1;
				$weekday = in_array( $rule['monthly_weekday'], self::weekdays(), true ) ? $rule['monthly_weekday'] : self::weekday_code( $start );
				$parts[] = 'BYDAY=' . $order . $weekday;
			} else {
				$day     = $rule['monthly_day'] ?: (int) $start->format( 'j' );
				$parts[] = 'BYMONTHDAY=' . min( 31, max( 1, $day ) );
			}
		} elseif ( 'yearly' === $rule['frequency'] ) {
			$parts[] = 'BYMONTH=' . $start->format( 'n' );
			$parts[] = 'BYMONTHDAY=' . $start->format( 'j' );
		}

		if ( 'until' === $rule['end_type'] && $rule['until'] ) {
			$until = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $rule['until'] . ' 23:59:59', $start->getTimezone() );
			if ( $until ) {
				$parts[] = 'UNTIL=' . $until->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
			}
		} elseif ( 'count' === $rule['end_type'] ) {
			$parts[] = 'COUNT=' . max( 1, (int) $rule['count'] );
		}

		return implode( ';', $parts );
	}

	/**
	 * Expands a rule through a rolling horizon.
	 *
	 * @param DateTimeImmutable $start   Series start.
	 * @param array             $rule    Normalized rule.
	 * @param DateTimeImmutable $through Projection horizon.
	 * @param int               $minimum Minimum projected occurrences.
	 * @return DateTimeImmutable[] Occurrence starts.
	 */
	public static function expand( DateTimeImmutable $start, array $rule, DateTimeImmutable $through, int $minimum = 12 ): array {
		$results   = array();
		$candidate = $start;
		$limit     = 10000;
		$count     = 0;
		$until     = self::until( $rule, $start->getTimezone() );

		while ( $limit-- > 0 ) {
			$matches = self::matches( $candidate, $start, $rule );

			if ( $matches ) {
				++$count;
				if ( ! $until || $candidate <= $until ) {
					$results[] = $candidate;
				}
			}

			if ( ( 'count' === $rule['end_type'] && $count >= $rule['count'] ) || ( $until && $candidate > $until ) ) {
				break;
			}

			if ( $candidate > $through && count( $results ) >= $minimum ) {
				break;
			}

			$candidate = $candidate->add( new DateInterval( 'P1D' ) );
		}

		return $results;
	}

	/**
	 * Checks whether a candidate date belongs to a rule.
	 *
	 * @param DateTimeImmutable $candidate Candidate local date and time.
	 * @param DateTimeImmutable $start     Series start.
	 * @param array             $rule      Normalized rule.
	 * @return bool Whether the candidate matches.
	 */
	private static function matches( DateTimeImmutable $candidate, DateTimeImmutable $start, array $rule ): bool {
		if ( $candidate < $start ) {
			return false;
		}

		// DTSTART is always part of an RFC recurrence set, even when a BY* rule
		// would not independently select it.
		if ( $candidate->getTimestamp() === $start->getTimestamp() ) {
			return true;
		}

		$interval = max( 1, (int) $rule['interval'] );

		if ( 'weekly' === $rule['frequency'] ) {
			$weekdays       = $rule['weekdays'] ?: array( self::weekday_code( $start ) );
			$start_week     = $start->sub( new DateInterval( 'P' . ( (int) $start->format( 'N' ) - 1 ) . 'D' ) );
			$candidate_week = $candidate->sub( new DateInterval( 'P' . ( (int) $candidate->format( 'N' ) - 1 ) . 'D' ) );
			$weeks          = intdiv( (int) $start_week->diff( $candidate_week )->format( '%a' ), 7 );

			return 0 === $weeks % $interval && in_array( self::weekday_code( $candidate ), $weekdays, true );
		}

		if ( 'monthly' === $rule['frequency'] ) {
			$months = ( (int) $candidate->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12
				+ (int) $candidate->format( 'n' ) - (int) $start->format( 'n' );
			if ( 0 !== $months % $interval ) {
				return false;
			}

			if ( 'weekday' !== $rule['monthly_mode'] ) {
				$day = $rule['monthly_day'] ?: (int) $start->format( 'j' );
				return (int) $candidate->format( 'j' ) === $day;
			}

			return self::is_ordinal_weekday( $candidate, $rule, $start );
		}

		$years = (int) $candidate->format( 'Y' ) - (int) $start->format( 'Y' );
		return 0 === $years % $interval
			&& $candidate->format( 'm-d' ) === $start->format( 'm-d' );
	}

	/**
	 * Checks an ordinal weekday monthly pattern.
	 *
	 * @param DateTimeImmutable $date  Candidate date.
	 * @param array             $rule  Normalized rule.
	 * @param DateTimeImmutable $start Series start.
	 * @return bool Whether the date matches.
	 */
	private static function is_ordinal_weekday( DateTimeImmutable $date, array $rule, DateTimeImmutable $start ): bool {
		$weekday = in_array( $rule['monthly_weekday'], self::weekdays(), true ) ? $rule['monthly_weekday'] : self::weekday_code( $start );
		if ( self::weekday_code( $date ) !== $weekday ) {
			return false;
		}

		$order = $rule['monthly_order'] ?: 'first';
		if ( 'last' === $order ) {
			return $date->modify( '+7 days' )->format( 'n' ) !== $date->format( 'n' );
		}

		$orders = array(
			'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4,
		);
		return (int) ceil( (int) $date->format( 'j' ) / 7 ) === ( $orders[ $order ] ?? 1 );
	}

	/**
	 * Resolves the inclusive rule end in local time.
	 *
	 * @param array        $rule     Normalized rule.
	 * @param DateTimeZone $timezone Series timezone.
	 * @return DateTimeImmutable|null Inclusive end or null.
	 */
	private static function until( array $rule, DateTimeZone $timezone ): ?DateTimeImmutable {
		if ( 'until' !== $rule['end_type'] || empty( $rule['until'] ) ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $rule['until'] . ' 23:59:59', $timezone );
		} catch ( Exception $exception ) {
			return null;
		}
	}

	/**
	 * Converts a date to an RFC weekday abbreviation.
	 *
	 * @param DateTimeImmutable $date Date to convert.
	 * @return string Weekday abbreviation.
	 */
	private static function weekday_code( DateTimeImmutable $date ): string {
		return self::weekdays()[ (int) $date->format( 'N' ) - 1 ];
	}
}
