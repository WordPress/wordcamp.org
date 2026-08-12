<?php
/**
 * Tests for GatherPress recurring rule expansion.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events\Tests
 */

namespace WordPressdotorg\GatherPress_Recurring_Events\Tests;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Cache;
use WordPressdotorg\GatherPress_Recurring_Events\Comments;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WordPressdotorg\GatherPress_Recurring_Events\Plugin;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;
use WP_Comment_Query;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
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

	/** Published end conditions can only change through the dedicated mutation. */
	public function test_published_end_condition_is_locked(): void {
		global $wpdb;

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', 'weekly' );
		update_post_meta( $post_id, Rule::META_PREFIX . 'end_type', 'never' );
		update_post_meta( $post_id, Rule::META_PREFIX . 'until', '' );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_status' => 'publish',
			),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );

		$plugin = Plugin::get_instance();
		$this->assertFalse( $plugin->lock_published_schedule( null, $post_id, Rule::META_PREFIX . 'end_type', 'until' ) );
		$this->assertFalse( $plugin->lock_published_schedule( null, $post_id, Rule::META_PREFIX . 'until', '2026-12-31' ) );

		$until = current_datetime()->modify( '+1 month' )->format( 'Y-m-d' );
		Plugin::update_end_condition( $post_id, $until );
		$this->assertSame( 'until', get_post_meta( $post_id, Rule::META_PREFIX . 'end_type', true ) );
		$this->assertSame( $until, get_post_meta( $post_id, Rule::META_PREFIX . 'until', true ) );
	}

	/**
	 * A caller that earned the right to write the schedule before publication
	 * can still persist it afterwards.
	 *
	 * The Groups frontend publishes the post and only then saves the rule, so
	 * without the lift every field the organiser just changed is dropped.
	 */
	public function test_schedule_unlock_persists_writes_on_a_published_series(): void {
		$post_id = $this->create_published_recurring_event();

		$this->assertFalse( update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', 'monthly' ) );
		$this->assertSame( 'weekly', get_post_meta( $post_id, Rule::META_PREFIX . 'frequency', true ) );

		$returned = Plugin::with_schedule_unlocked(
			$post_id,
			static function () use ( $post_id ) {
				update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', 'monthly' );
				delete_post_meta( $post_id, Rule::META_PREFIX . 'interval' );

				return 'callback-return';
			}
		);

		$this->assertSame( 'callback-return', $returned );
		$this->assertSame( 'monthly', get_post_meta( $post_id, Rule::META_PREFIX . 'frequency', true ) );
		$this->assertSame( '', get_post_meta( $post_id, Rule::META_PREFIX . 'interval', true ) );
	}

	/** The lift is released afterwards, and confined to its own post. */
	public function test_schedule_unlock_is_scoped_and_released(): void {
		$post_id       = $this->create_published_recurring_event();
		$other_post_id = $this->create_published_recurring_event();

		Plugin::with_schedule_unlocked(
			$post_id,
			static function () use ( $other_post_id ) {
				update_post_meta( $other_post_id, Rule::META_PREFIX . 'frequency', 'monthly' );
			}
		);

		$this->assertSame( 'weekly', get_post_meta( $other_post_id, Rule::META_PREFIX . 'frequency', true ) );

		$this->assertFalse( update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', 'yearly' ) );
		$this->assertSame( 'weekly', get_post_meta( $post_id, Rule::META_PREFIX . 'frequency', true ) );
	}

	/** Published recurrence metadata cannot be deleted to bypass the lock. */
	public function test_published_recurrence_metadata_cannot_be_deleted(): void {
		$post_id = $this->create_published_recurring_event();

		$this->assertFalse( delete_post_meta( $post_id, Rule::META_PREFIX . 'frequency' ) );
		$this->assertSame( 'weekly', get_post_meta( $post_id, Rule::META_PREFIX . 'frequency', true ) );
	}

	/** The controlled end-series bypass applies only to its post and metadata keys. */
	public function test_end_condition_bypass_is_scoped(): void {
		$post_id       = $this->create_published_recurring_event();
		$other_post_id = $this->create_published_recurring_event();
		$attempted     = false;
		$callback      = static function ( int $meta_id, int $object_id, string $meta_key ) use ( $post_id, $other_post_id, &$attempted ): void {
			if ( $attempted || $post_id !== $object_id || Rule::META_PREFIX . 'end_type' !== $meta_key ) {
				return;
			}

			$attempted = true;
			update_post_meta( $post_id, Rule::META_PREFIX . 'interval', 2 );
			update_post_meta( $other_post_id, Rule::META_PREFIX . 'frequency', 'monthly' );
		};
		add_action( 'updated_post_meta', $callback, 10, 3 );

		try {
			Plugin::update_end_condition( $post_id, '2026-12-31' );
		} finally {
			remove_action( 'updated_post_meta', $callback, 10 );
		}

		$this->assertTrue( $attempted );
		$this->assertSame( '1', get_post_meta( $post_id, Rule::META_PREFIX . 'interval', true ) );
		$this->assertSame( 'weekly', get_post_meta( $other_post_id, Rule::META_PREFIX . 'frequency', true ) );
	}

	/** Unpublishing a recurring series removes its projected occurrence data. */
	public function test_unpublishing_recurring_event_removes_series_data(): void {
		global $wpdb;

		$post_id    = $this->create_published_recurring_event();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
			)
		);
		$now        = current_time( 'mysql', true );
		$wpdb->insert(
			Database::occurrences_table(),
			array(
				'series_post_id'    => $post_id,
				'recurrence_id'     => '20260810T100000',
				'datetime_start'    => '2026-08-10 10:00:00',
				'datetime_start_gmt' => '2026-08-10 10:00:00',
				'datetime_end'      => '2026-08-10 11:00:00',
				'datetime_end_gmt'  => '2026-08-10 11:00:00',
				'timezone'          => 'UTC',
				'status'            => 'scheduled',
				'created_gmt'       => $now,
				'updated_gmt'       => $now,
			)
		);
		Database::map_comment( $comment_id, $post_id, '20260810T100000' );

		$post              = get_post( $post_id );
		$post->post_status = 'draft';
		Plugin::get_instance()->save_event( $post_id, $post );

		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE series_post_id = %d', Database::occurrences_table(), $post_id ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE series_post_id = %d', Database::comments_table(), $post_id ) ) );
	}

	/** Only comment queries for the active series are occurrence-scoped. */
	public function test_comment_query_scoping_targets_series_only(): void {
		$post_id    = $this->create_published_recurring_event();
		$occurrence = (object) array(
			'series_post_id' => $post_id,
			'recurrence_id'  => '20260810T100000',
		);
		Context::set( $occurrence );

		try {
			$other_query             = new WP_Comment_Query();
			$other_query->query_vars = array( 'post_id' => $post_id + 1 );
			Comments::prepare_query( $other_query );
			$this->assertArrayNotHasKey( 'gpre_occurrence', $other_query->query_vars );

			$series_query             = new WP_Comment_Query();
			$series_query->query_vars = array( 'post_id' => $post_id );
			Comments::prepare_query( $series_query );
			$this->assertSame( '20260810T100000', $series_query->query_vars['gpre_occurrence'] );
		} finally {
			Context::set( null );
		}
	}

	/**
	 * Activating an occurrence's request context invalidates the series'
	 * GatherPress RSVP cache, so a subsequent RSVP-count read reflects the
	 * occurrence that's actually active rather than a stale cached value
	 * from a previously viewed occurrence. This is a regression test for
	 * the GATHERPRESS_CACHE_GROUP bug: that bug used an undefined constant
	 * and fataled immediately, but a class_exists()-only guard would have
	 * let a *renamed* Cache API fail just as silently as a no-op.
	 */
	public function test_context_set_invalidates_rsvp_cache(): void {
		$post_id    = $this->create_published_recurring_event();
		$occurrence = (object) array(
			'series_post_id' => $post_id,
			'recurrence_id'  => '20260810T100000',
		);

		Cache::set( $post_id, array( 'attending' => array( 1 ) ) );
		$this->assertNotNull( Cache::get( $post_id ), 'Precondition: the cache entry exists before activating the occurrence context.' );

		try {
			Context::set( $occurrence );
			$this->assertNull( Cache::get( $post_id ) );
		} finally {
			Context::set( null );
		}
	}

	/** Mapping a comment to its occurrence also invalidates the series' RSVP cache. */
	public function test_comment_mapping_invalidates_rsvp_cache(): void {
		$post_id    = $this->create_published_recurring_event();
		$occurrence = (object) array(
			'series_post_id' => $post_id,
			'recurrence_id'  => '20260810T100000',
		);
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		Context::set( $occurrence );
		Cache::set( $post_id, array( 'attending' => array( 1 ) ) );

		try {
			Comments::map_inserted( $comment_id, get_comment( $comment_id ) );
			$this->assertNull( Cache::get( $post_id ) );
		} finally {
			Context::set( null );
		}
	}

	/**
	 * Publishing a real weekly series through the actual save_post_gatherpress_event
	 * hook projects correct occurrence rows into the database — not just correct
	 * dates from the pure Rule::expand() function the other tests here exercise.
	 * This is the only test that seeds a real GatherPress event date via
	 * Event::save_datetimes(), the same way the block editor does, so it also
	 * covers Occurrences::master_datetime()'s direct read of GatherPress's own
	 * {$wpdb->prefix}gatherpress_events table.
	 */
	public function test_publishing_recurring_event_projects_occurrence_rows(): void {
		global $wpdb;

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);

		( new Event( $post_id ) )->save_datetimes(
			array(
				'post_id'        => $post_id,
				'datetime_start' => '2026-08-10 10:00:00', // A Monday.
				'datetime_end'   => '2026-08-10 11:00:00',
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

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE series_post_id = %d ORDER BY datetime_start_gmt ASC',
				Database::occurrences_table(),
				$post_id
			)
		);

		$this->assertSame(
			array( '20260810T100000', '20260817T100000', '20260824T100000', '20260831T100000' ),
			array_map( static fn( object $row ): string => $row->recurrence_id, $rows )
		);

		foreach ( $rows as $row ) {
			$this->assertSame( 'scheduled', $row->status );
			$this->assertSame( 'UTC', $row->timezone );
		}

		$this->assertSame( '2026-08-10 11:00:00', $rows[0]->datetime_end );
		$this->assertSame( 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO;COUNT=4', get_post_meta( $post_id, Rule::META_PREFIX . 'rrule', true ) );
	}

	/** Deactivation removes the site's projection cron event. */
	public function test_deactivation_clears_projection_cron(): void {
		Occurrences::clear_cron();
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Occurrences::CRON_HOOK );
		$this->assertNotFalse( wp_next_scheduled( Occurrences::CRON_HOOK ) );

		Plugin::deactivate( false );
		$this->assertFalse( wp_next_scheduled( Occurrences::CRON_HOOK ) );
	}

	/**
	 * A brand-new multisite site gets its occurrence tables installed via the
	 * `wp_initialize_site` hook, not just on the site that happens to serve
	 * `init`. Regression test for the production/cron failure where a new
	 * site's tables didn't exist yet the first time a cross-site cron run
	 * tried to use them.
	 */
	public function test_new_site_gets_occurrence_tables_installed(): void {
		global $wpdb;

		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );

		try {
			$this->assertSame( Database::SCHEMA_VERSION, get_option( Database::OPTION_NAME ) );
			$this->assertSame( Database::occurrences_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Database::occurrences_table() ) ) );
			$this->assertSame( Database::comments_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Database::comments_table() ) ) );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Creates a published weekly event with locked recurrence metadata.
	 *
	 * @return int Event post ID.
	 */
	private function create_published_recurring_event(): int {
		global $wpdb;

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', 'weekly' );
		update_post_meta( $post_id, Rule::META_PREFIX . 'interval', 1 );
		update_post_meta( $post_id, Rule::META_PREFIX . 'end_type', 'never' );
		update_post_meta( $post_id, Rule::META_PREFIX . 'until', '' );
		$wpdb->update(
			$wpdb->posts,
			array( 'post_status' => 'publish' ),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );

		return $post_id;
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
