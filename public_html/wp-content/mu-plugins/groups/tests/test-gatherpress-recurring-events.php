<?php
/**
 * Tests for GatherPress recurring rule expansion.
 *
 * @package WordCamp\Groups\Tests
 */

namespace WordCamp\Groups\Tests;

use DateTimeImmutable;
use DateTimeZone;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group groups
 * @group gatherpress-recurring-events
 */
final class Test_GatherPress_Recurring_Events extends WP_UnitTestCase {

	/** Weekly expansion keeps local wall time across DST. */
	public function test_weekly_recurrence_preserves_wall_time_across_dst(): void {
		$start = new DateTimeImmutable( '2026-10-26 18:00:00', new DateTimeZone( 'America/Los_Angeles' ) );
		$dates = Rule::expand( $start, $this->rule( 'weekly', 4 ), $start->modify( '+2 months' ) );

		$this->assertSame(
			array( '2026-10-26 18:00 -07:00', '2026-11-02 18:00 -08:00', '2026-11-09 18:00 -08:00', '2026-11-16 18:00 -08:00' ),
			$this->format( $dates, 'Y-m-d H:i P' )
		);
	}

	/** Biweekly intervals align to calendar weeks rather than seven-day buckets. */
	public function test_biweekly_recurrence_uses_week_boundaries(): void {
		$start = new DateTimeImmutable( '2026-08-05 18:00:00', new DateTimeZone( 'UTC' ) );
		$rule  = array_merge(
			$this->rule( 'weekly', 3 ),
			array(
				'interval' => 2,
				'weekdays' => array( 'MO' ),
			)
		);

		$this->assertSame(
			array( '2026-08-05', '2026-08-17', '2026-08-31' ),
			$this->format( Rule::expand( $start, $rule, $start->modify( '+2 months' ) ) )
		);
	}

	/** A day-of-month rule skips months that lack that day. */
	public function test_monthly_31st_skips_short_months(): void {
		$start = new DateTimeImmutable( '2026-01-31 18:00:00', new DateTimeZone( 'UTC' ) );
		$rule  = array_merge( $this->rule( 'monthly', 4 ), array( 'monthly_day' => 31 ) );

		$this->assertSame(
			array( '2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31' ),
			$this->format( Rule::expand( $start, $rule, $start->modify( '+8 months' ) ) )
		);
	}

	/** Ordinal weekday rules calculate the actual last weekday. */
	public function test_last_weekday_of_month(): void {
		$start = new DateTimeImmutable( '2026-01-30 18:00:00', new DateTimeZone( 'UTC' ) );
		$rule  = array_merge(
			$this->rule( 'monthly', 4 ),
			array(
				'monthly_mode' => 'weekday', 'monthly_order' => 'last', 'monthly_weekday' => 'FR',
			)
		);

		$this->assertSame(
			array( '2026-01-30', '2026-02-27', '2026-03-27', '2026-04-24' ),
			$this->format( Rule::expand( $start, $rule, $start->modify( '+5 months' ) ) )
		);
	}

	/** A yearly leap-day rule skips non-leap years. */
	public function test_yearly_leap_day_skips_non_leap_years(): void {
		$start = new DateTimeImmutable( '2024-02-29 18:00:00', new DateTimeZone( 'UTC' ) );

		$this->assertSame(
			array( '2024-02-29', '2028-02-29', '2032-02-29' ),
			$this->format( Rule::expand( $start, $this->rule( 'yearly', 3 ), $start->modify( '+9 years' ) ) )
		);
	}

	/**
	 * Builds a complete normalized rule for tests.
	 *
	 * @param string $frequency Frequency.
	 * @param int    $count     Occurrence count.
	 * @return array Normalized rule.
	 */
	private function rule( string $frequency, int $count ): array {
		return array(
			'frequency'       => $frequency,
			'interval'        => 1,
			'weekdays'        => array( 'MO' ),
			'monthly_mode'    => 'day',
			'monthly_day'     => 1,
			'monthly_order'   => 'first',
			'monthly_weekday' => 'MO',
			'end_type'        => 'count',
			'until'           => '',
			'count'           => $count,
		);
	}

	/**
	 * Formats expanded dates for exact comparisons.
	 *
	 * @param DateTimeImmutable[] $dates  Dates.
	 * @param string              $format PHP date format.
	 * @return string[] Formatted dates.
	 */
	private function format( array $dates, string $format = 'Y-m-d' ): array {
		return array_map( static fn( DateTimeImmutable $date ): string => $date->format( $format ), $dates );
	}
}
