<?php
/**
 * Occurrence projection and retrieval.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

defined( 'WPINC' ) || die();

final class Occurrences {

	const CRON_HOOK = 'gpre_project_occurrences';

	/** Ensures the per-site daily projection job is scheduled. */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Removes the per-site occurrence projection job. */
	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Projects every published recurring series on the current site. */
	public static function project_all(): void {
		$post_ids = get_posts(
			array(
				'post_type'              => 'gatherpress_event',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => Rule::META_PREFIX . 'frequency',
						'value'   => Rule::frequencies(),
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $post_ids as $post_id ) {
			self::project( (int) $post_id );
		}
	}

	/**
	 * Projects one published series into lightweight occurrence rows.
	 *
	 * @param int $post_id Series post ID.
	 */
	public static function project( int $post_id ): void {
		if ( 'publish' !== get_post_status( $post_id ) || ! Rule::is_recurring( $post_id ) ) {
			return;
		}

		$event = self::master_datetime( $post_id );
		if ( ! $event ) {
			return;
		}

		$months  = max( 1, (int) apply_filters( 'gpre_projection_months', 6, $post_id ) );
		$minimum = max( 1, (int) apply_filters( 'gpre_projection_minimum_future', 12, $post_id ) );
		$through = ( new DateTimeImmutable( 'now', $event['timezone'] ) )->add( new DateInterval( 'P' . $months . 'M' ) );
		$rule    = Rule::from_post( $post_id );
		$starts  = Rule::expand( $event['start'], $rule, $through, $minimum );
		$now     = current_time( 'mysql', true );

		update_post_meta( $post_id, Rule::META_PREFIX . 'rrule', Rule::to_rrule( $rule, $event['start'] ) );

		global $wpdb;
		$table = Database::occurrences_table();

		foreach ( $starts as $start ) {
			$end           = $start->add( $event['duration'] );
			$start_gmt     = $start->setTimezone( new DateTimeZone( 'UTC' ) );
			$end_gmt       = $end->setTimezone( new DateTimeZone( 'UTC' ) );
			$recurrence_id = Rule::recurrence_id( $start );

			$sql = $wpdb->prepare(
				"INSERT IGNORE INTO %i
				(series_post_id, recurrence_id, datetime_start, datetime_start_gmt, datetime_end, datetime_end_gmt, timezone, status, created_gmt, updated_gmt)
				VALUES (%d, %s, %s, %s, %s, %s, %s, 'scheduled', %s, %s)",
				$table,
				$post_id,
				$recurrence_id,
				$start->format( 'Y-m-d H:i:s' ),
				$start_gmt->format( 'Y-m-d H:i:s' ),
				$end->format( 'Y-m-d H:i:s' ),
				$end_gmt->format( 'Y-m-d H:i:s' ),
				$event['timezone']->getName(),
				$now,
				$now
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $sql );
		}

		self::remove_stale_scheduled_rows(
			$post_id,
			array_map( static fn( DateTimeImmutable $start ): string => Rule::recurrence_id( $start ), $starts )
		);
		set_transient( 'gpre_projected_' . $post_id, 1, 6 * HOUR_IN_SECONDS );

		do_action( 'gpre_occurrences_projected', $post_id, count( $starts ) );
	}

	/**
	 * Gets an occurrence by its canonical identity.
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Recurrence identifier.
	 * @return object|null Occurrence row.
	 */
	public static function get( int $post_id, string $recurrence_id ): ?object {
		global $wpdb;

		// A lightweight request-time repair covers missed WP-Cron runs.
		self::maybe_project( $post_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE series_post_id = %d AND recurrence_id = %s',
				Database::occurrences_table(),
				$post_id,
				$recurrence_id
			)
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Selects the next available occurrence for a series landing page.
	 *
	 * @param int $post_id Series post ID.
	 * @return object|null Selected occurrence row.
	 */
	public static function select_for_series( int $post_id ): ?object {
		self::maybe_project( $post_id );

		global $wpdb;
		$table = Database::occurrences_table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE series_post_id = %d AND datetime_end_gmt >= %s
				ORDER BY (status = 'cancelled') ASC, datetime_start_gmt ASC LIMIT 1",
				$table,
				$post_id,
				$now
			)
		);

		if ( ! $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE series_post_id = %d ORDER BY datetime_start_gmt DESC LIMIT 1',
					$table,
					$post_id
				)
			);
		}

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Gets a compact list of dates around a selected occurrence.
	 *
	 * @param int    $post_id Series post ID.
	 * @param string $selected Selected recurrence identifier.
	 * @param int    $limit Maximum number of rows.
	 * @return object[] Occurrence rows.
	 */
	public static function around( int $post_id, string $selected = '', int $limit = 6 ): array {
		self::maybe_project( $post_id );

		global $wpdb;
		$table = Database::occurrences_table();
		$now   = current_time( 'mysql', true );

		if ( $selected ) {
			$current = self::get( $post_id, $selected );
			$from    = $current ? $current->datetime_start_gmt : $now;
		} else {
			$from = $now;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE series_post_id = %d AND datetime_start_gmt >= %s ORDER BY datetime_start_gmt ASC LIMIT %d',
				$table,
				$post_id,
				$from,
				$limit
			)
		);
	}

	/**
	 * Gets projected occurrences in one temporal direction.
	 *
	 * @param int    $post_id   Series post ID.
	 * @param string $direction Upcoming or past.
	 * @param int    $limit     Maximum number of rows.
	 * @return object[] Occurrence rows.
	 */
	public static function all( int $post_id, string $direction = 'upcoming', int $limit = 100 ): array {
		self::maybe_project( $post_id );

		global $wpdb;
		if ( 'past' === $direction ) {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE series_post_id = %d AND datetime_end_gmt < %s ORDER BY datetime_start_gmt DESC LIMIT %d',
				Database::occurrences_table(),
				$post_id,
				current_time( 'mysql', true ),
				$limit
			);
		} else {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE series_post_id = %d AND datetime_start_gmt >= %s ORDER BY datetime_start_gmt ASC LIMIT %d',
				Database::occurrences_table(),
				$post_id,
				current_time( 'mysql', true ),
				$limit
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $query );
	}

	/**
	 * Cancels or restores a future occurrence.
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Recurrence identifier.
	 * @param string $status        Scheduled or cancelled.
	 * @return bool Whether the row was updated.
	 */
	public static function set_status( int $post_id, string $recurrence_id, string $status ): bool {
		if ( ! in_array( $status, array( 'scheduled', 'cancelled' ), true ) || ! self::get( $post_id, $recurrence_id ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Database::occurrences_table(),
			array(
				'status'      => $status,
				'updated_gmt' => current_time( 'mysql', true ),
			),
			array(
				'series_post_id' => $post_id,
				'recurrence_id'  => $recurrence_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false !== $updated ) {
			do_action( 'gpre_occurrence_status_changed', $post_id, $recurrence_id, $status );
		}

		return false !== $updated;
	}

	/**
	 * Ends a series after a selected future occurrence.
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Final recurrence identifier.
	 * @return bool Whether the series was ended.
	 */
	public static function end_after( int $post_id, string $recurrence_id ): bool {
		$occurrence = self::get( $post_id, $recurrence_id );
		if ( ! $occurrence || $occurrence->datetime_start_gmt <= current_time( 'mysql', true ) ) {
			return false;
		}

		$until         = substr( $occurrence->datetime_start, 0, 10 );
		$current_type  = get_post_meta( $post_id, Rule::META_PREFIX . 'end_type', true );
		$current_until = get_post_meta( $post_id, Rule::META_PREFIX . 'until', true );

		if ( 'until' === $current_type && $current_until && $current_until <= $until ) {
			return false;
		}

		Plugin::update_end_condition( $post_id, $until );

		global $wpdb;
		// Keep projected later rows as stable, cancelled URLs with their existing discussion and RSVP history.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', updated_gmt = %s
				WHERE series_post_id = %d AND datetime_start_gmt > %s",
				Database::occurrences_table(),
				current_time( 'mysql', true ),
				$post_id,
				$occurrence->datetime_start_gmt
			)
		);

		$event = self::master_datetime( $post_id );
		if ( $event ) {
			update_post_meta( $post_id, Rule::META_PREFIX . 'rrule', Rule::to_rrule( Rule::from_post( $post_id ), $event['start'] ) );
		}

		do_action( 'gpre_series_ended', $post_id, $recurrence_id );
		return true;
	}

	/**
	 * Reads the GatherPress master date and duration.
	 *
	 * @param int $post_id Series post ID.
	 * @return array|null Master date data.
	 */
	private static function master_datetime( int $post_id ): ?array {
		global $wpdb;

		// Read the immutable series seed directly so active occurrence metadata overrides
		// can never shift the projection's DTSTART.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$master = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT datetime_start, datetime_end, timezone FROM %i WHERE post_id = %d',
				$wpdb->prefix . 'gatherpress_events',
				$post_id
			)
		);

		if ( ! $master ) {
			return null;
		}

		$timezone_name = $master->timezone ?: wp_timezone_string();

		try {
			$timezone = new DateTimeZone( $timezone_name );
			$start    = new DateTimeImmutable( $master->datetime_start, $timezone );
			$end      = new DateTimeImmutable( $master->datetime_end, $timezone );
		} catch ( Exception $exception ) {
			return null;
		}

		if ( $end <= $start ) {
			return null;
		}

		return array(
			'start'    => $start,
			'duration' => $start->diff( $end ),
			'timezone' => $timezone,
		);
	}

	/**
	 * Removes unrealized scheduled rows that no longer belong to the rule.
	 *
	 * Cancelled rows are stable resources and realized rows are permanent history.
	 *
	 * @param int      $post_id       Series post ID.
	 * @param string[] $recurrence_ids Valid projected identifiers.
	 */
	private static function remove_stale_scheduled_rows( int $post_id, array $recurrence_ids ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT occurrence_id, recurrence_id FROM %i
				WHERE series_post_id = %d AND status = 'scheduled' AND datetime_start_gmt >= %s",
				Database::occurrences_table(),
				$post_id,
				current_time( 'mysql', true )
			)
		);

		foreach ( $rows as $row ) {
			if ( ! in_array( $row->recurrence_id, $recurrence_ids, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( Database::occurrences_table(), array( 'occurrence_id' => (int) $row->occurrence_id ), array( '%d' ) );
			}
		}
	}

	/**
	 * Repairs a projection only when its short-lived freshness marker expires.
	 *
	 * @param int $post_id Series post ID.
	 */
	private static function maybe_project( int $post_id ): void {
		if ( Rule::is_recurring( $post_id ) && 'publish' === get_post_status( $post_id ) && ! get_transient( 'gpre_projected_' . $post_id ) ) {
			self::project( $post_id );
		}
	}
}
