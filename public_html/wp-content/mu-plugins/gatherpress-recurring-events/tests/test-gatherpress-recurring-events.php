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
use WP_Query;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group gatherpress-recurring-events
 */
final class Test_GatherPress_Recurring_Events extends WP_UnitTestCase {

	/** Ensure GatherPress tables exist for this suite. */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( class_exists( 'GatherPress\Core\Setup' ) ) {
			\GatherPress\Core\Setup::get_instance()->check_plugin_version();
		}
	}

	/** Leave the screen global as we found it for whatever runs next. */
	public function tear_down() {
		unset( $GLOBALS['current_screen'] );

		parent::tear_down();
	}

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

	/** A post with no recurrence meta reads back a rule that passes the REST enum. */
	public function test_rule_from_bare_post_uses_valid_weekday(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'gatherpress_event' ) );

		$this->assertContains( Rule::from_post( $post_id )['monthly_weekday'], Rule::weekdays() );
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
	 * without the lift every field the organizer just changed is dropped.
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

	/**
	 * A nested end-condition write does not cancel the surrounding lift.
	 *
	 * Both entry points share one allowlist slot, so an inner lift that
	 * replaced and then cleared that slot would re-lock the schedule for the
	 * rest of the outer callback, silently dropping the writes that follow it
	 * -- the same silent loss the lift exists to prevent, one level down.
	 */
	public function test_schedule_unlock_survives_a_nested_end_condition_write(): void {
		$post_id = $this->create_published_recurring_event();

		Plugin::with_schedule_unlocked(
			$post_id,
			static function () use ( $post_id ): void {
				Plugin::update_end_condition( $post_id, '2026-12-31' );

				update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', 'monthly' );
			}
		);

		$this->assertSame( 'until', get_post_meta( $post_id, Rule::META_PREFIX . 'end_type', true ) );
		$this->assertSame( '2026-12-31', get_post_meta( $post_id, Rule::META_PREFIX . 'until', true ) );
		$this->assertSame( 'monthly', get_post_meta( $post_id, Rule::META_PREFIX . 'frequency', true ) );

		$this->assertFalse( update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', 'yearly' ) );
		$this->assertSame( 'monthly', get_post_meta( $post_id, Rule::META_PREFIX . 'frequency', true ) );
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
	 * A stored lowercase monthly weekday is normalized to the REST enum.
	 */
	public function test_rule_from_post_normalizes_stored_monthly_weekday(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type' => 'gatherpress_event',
			)
		);

		update_post_meta(
			$post_id,
			Rule::META_PREFIX . 'monthly_weekday',
			'we'
		);

		$this->assertSame(
			'WE',
			Rule::from_post( $post_id )['monthly_weekday']
		);
	}

	/**
	 * Archive queries bucket a series by each occurrence's date, not the master date.
	 *
	 * GatherPress builds the WHERE comparison with `$wpdb->prepare( '%i.%i' )`,
	 * which backtick-quotes the identifiers. The extension's clause rewrite
	 * used to match only the unquoted form, so once a series' first occurrence
	 * had ended the whole series fell out of Upcoming and every future
	 * occurrence was listed under Past.
	 */
	public function test_archive_queries_use_occurrence_dates(): void {
		/*
		 * `wordcamp-remote-css/tests/bootstrap.php` defines `WP_ADMIN` for the
		 * whole run, so `is_admin()` is true unless a screen says otherwise, and
		 * `Query::clauses()` exempts wp-admin. This is a front-end archive query.
		 */
		set_current_screen( 'front' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);

		$start = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '-8 days' )->setTime( 10, 0 );

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
		update_post_meta( $post_id, Rule::META_PREFIX . 'weekdays', array( strtoupper( substr( $start->format( 'D' ), 0, 2 ) ) ) );
		update_post_meta( $post_id, Rule::META_PREFIX . 'end_type', 'count' );
		update_post_meta( $post_id, Rule::META_PREFIX . 'count', 4 );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// Occurrences at -8d, -1d, +6d and +13d relative to now.
		$upcoming = new WP_Query(
			array(
				'post_type'               => 'gatherpress_event',
				'post__in'                => array( $post_id ),
				'orderby'                 => 'datetime',
				'order'                   => 'ASC',
				'gatherpress_event_query' => 'upcoming',
				'include_unfinished'      => 1,
			)
		);
		$past     = new WP_Query(
			array(
				'post_type'               => 'gatherpress_event',
				'post__in'                => array( $post_id ),
				'orderby'                 => 'datetime',
				'order'                   => 'DESC',
				'gatherpress_event_query' => 'past',
				'include_unfinished'      => 0,
			)
		);

		$this->assertStringContainsString( 'COALESCE(gpre_occ_query.datetime_end_gmt', $upcoming->request );
		$this->assertStringContainsString( 'COALESCE(gpre_occ_query.datetime_start_gmt', $upcoming->request );
		$this->assertCount( 2, $upcoming->posts, 'Upcoming should list the two future occurrences.' );
		$this->assertCount( 2, $past->posts, 'Past should list only the two finished occurrences.' );
	}

	/** Reproduce archive loop occurrence date behavior. */
	public function test_archive_loop_renders_occurrence_dates_in_order(): void {
		set_current_screen( 'front' );

		// Event A: started 28 days ago, 20 occurrences (4 in past, 16 in future).
		$post_id_a             = self::factory()->post->create(
			array(
				'post_title'  => 'Recurring Weekly A',
				'post_type'   => 'gatherpress_event',
				'post_status' => 'draft',
			)
		);
		$start_a               = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '-28 days' )->setTime( 10, 0 );
		$master_date_formatted = $start_a->format( 'M j, Y' );
		( new Event( $post_id_a ) )->save_datetimes(
			array(
				'post_id'        => $post_id_a,
				'datetime_start' => $start_a->format( 'Y-m-d H:i:s' ),
				'datetime_end'   => $start_a->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
				'timezone'       => 'UTC',
			)
		);
		update_post_meta( $post_id_a, Rule::META_PREFIX . 'frequency', 'weekly' );
		update_post_meta( $post_id_a, Rule::META_PREFIX . 'interval', 1 );
		update_post_meta( $post_id_a, Rule::META_PREFIX . 'weekdays', array( strtoupper( substr( $start_a->format( 'D' ), 0, 2 ) ) ) );
		update_post_meta( $post_id_a, Rule::META_PREFIX . 'end_type', 'count' );
		update_post_meta( $post_id_a, Rule::META_PREFIX . 'count', 20 );
		wp_update_post( array(
			'ID' => $post_id_a, 'post_status' => 'publish',
		) );

		// Several single events interleaved.
		for ( $i = 1; $i <= 5; $i++ ) {
			$post_id_single = self::factory()->post->create(
				array(
					'post_title'  => "Single Event {$i}",
					'post_type'   => 'gatherpress_event',
					'post_status' => 'draft',
				)
			);
			$start_single   = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+' . ( $i * 5 ) . ' days' )->setTime( 14, 0 );
			( new Event( $post_id_single ) )->save_datetimes(
				array(
					'post_id'        => $post_id_single,
					'datetime_start' => $start_single->format( 'Y-m-d H:i:s' ),
					'datetime_end'   => $start_single->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
					'timezone'       => 'UTC',
				)
			);
			wp_update_post( array(
				'ID' => $post_id_single, 'post_status' => 'publish',
			) );
		}

		$page1 = new WP_Query(
			array(
				'post_type'               => 'gatherpress_event',
				'orderby'                 => 'datetime',
				'order'                   => 'ASC',
				'gatherpress_event_query' => 'upcoming',
				'include_unfinished'      => 1,
				'posts_per_page'          => 12,
				'paged'                   => 1,
			)
		);

		$this->assertCount( 12, $page1->posts, 'Page 1 should contain exactly 12 events/occurrences.' );
		$this->assertSame( 20, $page1->found_posts, 'Total found posts should be 15 upcoming occurrences + 5 single events = 20.' );
		$this->assertSame( 2, $page1->max_num_pages, 'Total pages should be 2.' );

		$page2 = new WP_Query(
			array(
				'post_type'               => 'gatherpress_event',
				'orderby'                 => 'datetime',
				'order'                   => 'ASC',
				'gatherpress_event_query' => 'upcoming',
				'include_unfinished'      => 1,
				'posts_per_page'          => 12,
				'paged'                   => 2,
			)
		);

		$this->assertCount( 8, $page2->posts, 'Page 2 should contain the remaining 8 events/occurrences.' );

		// Register pattern and render the actual query block.
		$theme_dir = dirname( dirname( dirname( __DIR__ ) ) ) . '/themes/groups-site/';
		if ( ! \WP_Block_Patterns_Registry::get_instance()->is_registered( 'groups-site/event-card' ) && file_exists( $theme_dir . 'patterns/event-card.php' ) ) {
			ob_start();
			include $theme_dir . 'patterns/event-card.php';
			$card_content = ob_get_clean();
			register_block_pattern(
				'groups-site/event-card',
				array(
					'title'   => 'Event card',
					'content' => $card_content,
				)
			);
		}

		$query_block_markup = '<!-- wp:query {"query":{"postType":"gatherpress_event","perPage":12,"offset":0,"order":"asc","orderBy":"datetime","gatherpress_event_query":"upcoming","include_unfinished":1,"inherit":false},"namespace":"gatherpress-event-query","className":"gatherpress-event-query","align":"wide"} -->
<div class="wp-block-query alignwide gatherpress-event-query">
	<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->
		<!-- wp:pattern {"slug":"groups-site/event-card"} /-->
	<!-- /wp:post-template -->
	<!-- wp:query-pagination -->
		<!-- wp:query-pagination-numbers /-->
	<!-- /wp:query-pagination -->
</div>
<!-- /wp:query -->';

		// Render Page 1.
		$_GET           = array();
		$rendered_page1 = do_blocks( $query_block_markup );
		preg_match_all( '/<div[^>]*class="[^"]*wp-block-gatherpress-event-date[^"]*"[^>]*>(.*?)<\/div>/s', $rendered_page1, $dates_p1 );
		preg_match_all( '/<h3[^>]*class="[^"]*wp-block-post-title[^"]*"[^>]*><a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a><\/h3>/s', $rendered_page1, $titles_p1 );

		$this->assertCount( 12, $titles_p1[2], 'Rendered page 1 should have 12 cards.' );

		$all_rendered_urls = array();

		foreach ( $titles_p1[2] as $idx => $title ) {
			$date_str = trim( wp_strip_all_tags( $dates_p1[1][ $idx ] ?? '' ) );
			$url      = $titles_p1[1][ $idx ];

			// Crucial CFT assertion: Master creation date from 28 days ago must NEVER render on upcoming cards.
			$this->assertNotSame(
				$master_date_formatted,
				$date_str,
				sprintf( 'Card %d (%s) must not render past master creation date (%s).', $idx + 1, $title, $master_date_formatted )
			);

			// Occurrence links must include recurrence timestamp.
			if ( str_contains( $title, 'Recurring' ) ) {
				$this->assertMatchesRegularExpression(
					'#/\d{8}T\d{6}/#',
					$url,
					sprintf( 'Card %d (%s) must link to its specific occurrence URL.', $idx + 1, $title )
				);
			}

			$all_rendered_urls[] = $url;
		}

		// Render Page 2.
		$_GET           = array( 'query-page' => 2 );
		$rendered_page2 = do_blocks( $query_block_markup );
		preg_match_all( '/<div[^>]*class="[^"]*wp-block-gatherpress-event-date[^"]*"[^>]*>(.*?)<\/div>/s', $rendered_page2, $dates_p2 );
		preg_match_all( '/<h3[^>]*class="[^"]*wp-block-post-title[^"]*"[^>]*><a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a><\/h3>/s', $rendered_page2, $titles_p2 );

		$this->assertCount( 8, $titles_p2[2], 'Rendered page 2 should have 8 cards.' );

		$page2_urls = array();
		foreach ( $titles_p2[2] as $idx => $title ) {
			$date_str = trim( wp_strip_all_tags( $dates_p2[1][ $idx ] ?? '' ) );
			$url      = $titles_p2[1][ $idx ];

			$this->assertNotSame(
				$master_date_formatted,
				$date_str,
				sprintf( 'Page 2 card %d (%s) must not render past master creation date (%s).', $idx + 1, $title, $master_date_formatted )
			);

			$page2_urls[] = $url;
		}

		// Pagination stability: Zero duplicate URLs between Page 1 and Page 2.
		$intersection = array_intersect( $all_rendered_urls, $page2_urls );
		$this->assertEmpty(
			$intersection,
			'Pagination instability detected: events repeated across page 1 and page 2: ' . implode( ', ', $intersection )
		);

		// Total distinct items rendered across page 1 and page 2 must equal 20.
		$total_unique_rendered = count( array_unique( array_merge( $all_rendered_urls, $page2_urls ) ) );
		$this->assertSame( 20, $total_unique_rendered, 'All 20 events should be rendered exactly once across pages 1 and 2.' );

		$_GET = array();
	}

	/**
	 * Verify pagination stability when multiple events or occurrences tie on start datetime.
	 */
	public function test_pagination_stability_with_tied_timestamps(): void {
		$base_time     = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+10 days' )->setTime( 18, 0 );
		$created_posts = array();

		// Create 15 events where items 10, 11, 12, 13 share the exact same start datetime.
		for ( $i = 1; $i <= 15; $i++ ) {
			$post_id = self::factory()->post->create(
				array(
					'post_title'  => "Tied Test Event {$i}",
					'post_type'   => 'gatherpress_event',
					'post_status' => 'draft',
				)
			);

			// Items 10, 11, 12, 13 all have the same start datetime.
			$offset_days = ( $i >= 10 && $i <= 13 ) ? 10 : $i;
			$event_start = $base_time->modify( '+' . $offset_days . ' days' );

			( new Event( $post_id ) )->save_datetimes(
				array(
					'post_id'        => $post_id,
					'datetime_start' => $event_start->format( 'Y-m-d H:i:s' ),
					'datetime_end'   => $event_start->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
					'timezone'       => 'UTC',
				)
			);
			wp_update_post( array(
				'ID' => $post_id, 'post_status' => 'publish',
			) );
			$created_posts[ $post_id ] = $event_start->format( 'Y-m-d H:i:s' );
		}

		$page1 = new WP_Query(
			array(
				'post_type'               => 'gatherpress_event',
				'orderby'                 => 'datetime',
				'order'                   => 'ASC',
				'gatherpress_event_query' => 'upcoming',
				'include_unfinished'      => 1,
				'posts_per_page'          => 12,
				'paged'                   => 1,
			)
		);

		$page2 = new WP_Query(
			array(
				'post_type'               => 'gatherpress_event',
				'orderby'                 => 'datetime',
				'order'                   => 'ASC',
				'gatherpress_event_query' => 'upcoming',
				'include_unfinished'      => 1,
				'posts_per_page'          => 12,
				'paged'                   => 2,
			)
		);

		$this->assertCount( 12, $page1->posts, 'Page 1 should have 12 items.' );
		$this->assertCount( 3, $page2->posts, 'Page 2 should have 3 items.' );
		$this->assertSame( 15, $page1->found_posts, 'Total found posts should be 15.' );

		$page1_ids = wp_list_pluck( $page1->posts, 'ID' );
		$page2_ids = wp_list_pluck( $page2->posts, 'ID' );

		// Enforce pagination stability: No row should appear on both Page 1 and Page 2.
		$intersection = array_intersect( $page1_ids, $page2_ids );
		$this->assertEmpty(
			$intersection,
			'Tied timestamps caused pagination instability: post IDs repeated across pages 1 and 2: ' . implode( ', ', $intersection )
		);

		// Combined items must contain all 15 unique post IDs.
		$all_ids = array_merge( $page1_ids, $page2_ids );
		$this->assertSame( 15, count( array_unique( $all_ids ) ), 'All 15 events must be accounted for across pages 1 and 2.' );
	}

	/**
	 * Verify past events archive ordering and pagination stability with DESC order.
	 */
	public function test_past_events_archive_ordering_and_pagination(): void {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		// Create 15 past events.
		for ( $i = 1; $i <= 15; $i++ ) {
			$post_id    = self::factory()->post->create(
				array(
					'post_title'  => "Past Event {$i}",
					'post_type'   => 'gatherpress_event',
					'post_status' => 'draft',
				)
			);
			$past_start = $now->modify( '-' . ( 20 - $i ) . ' days' )->setTime( 10, 0 );

			( new Event( $post_id ) )->save_datetimes(
				array(
					'post_id'        => $post_id,
					'datetime_start' => $past_start->format( 'Y-m-d H:i:s' ),
					'datetime_end'   => $past_start->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
					'timezone'       => 'UTC',
				)
			);
			wp_update_post( array(
				'ID' => $post_id, 'post_status' => 'publish',
			) );
		}

		$past_page1 = new WP_Query(
			array(
				'post_type'               => 'gatherpress_event',
				'orderby'                 => 'datetime',
				'order'                   => 'DESC',
				'gatherpress_event_query' => 'past',
				'include_unfinished'      => 0,
				'posts_per_page'          => 10,
				'paged'                   => 1,
			)
		);

		$past_page2 = new WP_Query(
			array(
				'post_type'               => 'gatherpress_event',
				'orderby'                 => 'datetime',
				'order'                   => 'DESC',
				'gatherpress_event_query' => 'past',
				'include_unfinished'      => 0,
				'posts_per_page'          => 10,
				'paged'                   => 2,
			)
		);

		$this->assertCount( 10, $past_page1->posts, 'Past Page 1 should contain 10 items.' );
		$this->assertCount( 5, $past_page2->posts, 'Past Page 2 should contain 5 items.' );

		$p1_ids = wp_list_pluck( $past_page1->posts, 'ID' );
		$p2_ids = wp_list_pluck( $past_page2->posts, 'ID' );

		$intersection = array_intersect( $p1_ids, $p2_ids );
		$this->assertEmpty(
			$intersection,
			'Past events pagination instability: post IDs repeated across pages 1 and 2: ' . implode( ', ', $intersection )
		);
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
