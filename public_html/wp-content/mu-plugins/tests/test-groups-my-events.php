<?php

namespace WordCamp\Groups\Frontend\Tests;

use DateTimeImmutable;
use DateTimeZone;
use WordPressdotorg\GatherPress_Recurring_Events\Database;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;
use WP_UnitTestCase;

use function WordCamp\Groups\Frontend\My_Events\get_upcoming_events;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/wporg-groups-frontend/inc/my-events.php';

/**
 * Tests for the my-events block's notion of "my events".
 *
 * The block used to answer purely from RSVP data, so an organizer who had
 * created events but never clicked RSVP saw an empty block (#1810). These
 * cover both halves of the definition and the boundaries between them:
 * authored events count, attending events count, an event that is both is
 * listed once, and events that have finished or belong to someone else stay
 * out.
 *
 * The answer is a list of dates, not events, because a recurring series is
 * one post with many dates and only its first is stored in GatherPress's
 * table — which used to drop every series from the block the moment that
 * first date passed (#2056).
 *
 * @group mu-plugins
 * @group groups-frontend
 */
class Test_Groups_My_Events extends WP_UnitTestCase {

	/**
	 * Whether GatherPress's datetime table exists in this environment.
	 *
	 * @var bool
	 */
	protected static $has_events_table = false;

	/**
	 * Whether the recurring-events extension's tables exist here.
	 *
	 * @var bool
	 */
	protected static $has_occurrence_tables = false;

	/**
	 * Create the GatherPress datetime table the block reads from.
	 *
	 * The plugin owns this table, so the tests create it rather than assuming
	 * a GatherPress activation has run in the test environment.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		global $wpdb;

		$table = $wpdb->prefix . 'gatherpress_events';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				post_id bigint(20) unsigned NOT NULL,
				datetime_start datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				datetime_start_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				datetime_end datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				datetime_end_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				timezone varchar(255) DEFAULT NULL,
				PRIMARY KEY (post_id)
			)"
		);

		self::$has_events_table = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		// phpcs:enable

		if ( ! class_exists( Database::class ) ) {
			return;
		}

		$occurrences = Database::occurrences_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$occurrences}'" ) ) {
			// The installer is a no-op once the schema option is set, which
			// it can be in a suite where the tables were never created.
			delete_option( Database::OPTION_NAME );
			Database::maybe_install();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$has_occurrence_tables = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$occurrences}'" );
	}

	/**
	 * Skip when the datetime table could not be created, rather than reporting
	 * a failure that says nothing about the code under test.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! self::$has_events_table ) {
			$this->markTestSkipped( 'GatherPress datetime table unavailable.' );
		}

		register_post_type( 'gatherpress_event', array( 'public' => true ) );
		register_taxonomy( '_gatherpress_rsvp_status', 'comment', array( 'public' => false ) );
	}

	/**
	 * Create an event and give it a slot in GatherPress's datetime table.
	 *
	 * @param int    $author_id Event author.
	 * @param string $offset    Relative time for the event start, e.g. `+1 day`.
	 *
	 * @return int The event post ID.
	 */
	protected function make_event( int $author_id, string $offset = '+1 day' ): int {
		global $wpdb;

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'gatherpress_event',
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);

		$start = gmdate( 'Y-m-d H:i:s', strtotime( $offset ) );
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( $offset ) + HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'gatherpress_events',
			array(
				'post_id'            => $event_id,
				'datetime_start'     => $start,
				'datetime_start_gmt' => $start,
				'datetime_end'       => $end,
				'datetime_end_gmt'   => $end,
				'timezone'           => 'UTC',
			)
		);

		return $event_id;
	}

	/**
	 * RSVP a user to an event as attending.
	 *
	 * @param int $user_id  Member RSVPing.
	 * @param int $event_id Event they are attending.
	 *
	 * @return int The RSVP comment ID.
	 */
	protected function rsvp( int $user_id, int $event_id ): int {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $event_id,
				'comment_type'     => 'gatherpress_rsvp',
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);

		wp_set_object_terms( $comment_id, 'attending', '_gatherpress_rsvp_status' );

		return $comment_id;
	}

	/**
	 * Create a recurring series whose stored date has already passed.
	 *
	 * That is what the projection leaves behind: GatherPress's table holds
	 * the series' first date and nothing else, so a series that started
	 * weeks ago looks finished to anything reading only that table.
	 *
	 * @param int    $author_id    Series author.
	 * @param string $first_offset Relative time for the series' first date.
	 *
	 * @return int The series post ID.
	 */
	protected function make_series( int $author_id, string $first_offset = '-4 weeks' ): int {
		$this->skip_without_occurrence_tables();

		return $this->make_event( $author_id, $first_offset );
	}

	/**
	 * Project one date of a series into the occurrence table.
	 *
	 * @param int    $event_id Series post ID.
	 * @param string $offset   Relative time for the date, e.g. `+1 week`.
	 * @param string $status   Occurrence status, `scheduled` or `cancelled`.
	 *
	 * @return array{recurrence_id: string, start: string} The projected date.
	 */
	protected function add_occurrence( int $event_id, string $offset, string $status = 'scheduled' ): array {
		global $wpdb;

		$start         = gmdate( 'Y-m-d H:i:s', strtotime( $offset ) );
		$end           = gmdate( 'Y-m-d H:i:s', strtotime( $offset ) + HOUR_IN_SECONDS );
		$recurrence_id = Rule::recurrence_id( new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) ) );
		$now           = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Database::occurrences_table(),
			array(
				'series_post_id'     => $event_id,
				'recurrence_id'      => $recurrence_id,
				'datetime_start'     => $start,
				'datetime_start_gmt' => $start,
				'datetime_end'       => $end,
				'datetime_end_gmt'   => $end,
				'timezone'           => 'UTC',
				'status'             => $status,
				'created_gmt'        => $now,
				'updated_gmt'        => $now,
			)
		);

		return array(
			'recurrence_id' => $recurrence_id,
			'start'         => $start,
		);
	}

	/**
	 * RSVP a user to one date of a series.
	 *
	 * @param int    $user_id       Member RSVPing.
	 * @param int    $event_id      Series post ID.
	 * @param string $recurrence_id Date they are attending.
	 *
	 * @return void
	 */
	protected function rsvp_to_occurrence( int $user_id, int $event_id, string $recurrence_id ): void {
		Database::map_comment( $this->rsvp( $user_id, $event_id ), $event_id, $recurrence_id );
	}

	/**
	 * Skip a recurring case when the extension's tables are unavailable.
	 */
	protected function skip_without_occurrence_tables(): void {
		if ( ! self::$has_occurrence_tables ) {
			$this->markTestSkipped( 'Recurring-events occurrence tables unavailable.' );
		}
	}

	/**
	 * The event IDs behind a list of dates, for the cases that only care
	 * about which events came back.
	 *
	 * @param array $entries Entries from `get_upcoming_events()`.
	 *
	 * @return int[]
	 */
	protected function event_ids( array $entries ): array {
		return array_column( $entries, 'event_id' );
	}

	/**
	 * Build the expected entry for a plain, non-recurring event.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $offset   The offset the event was created with.
	 *
	 * @return array{event_id: int, recurrence_id: string, start: string}
	 */
	protected function plain_entry( int $event_id, string $offset ): array {
		return array(
			'event_id'      => $event_id,
			'recurrence_id' => '',
			'start'         => gmdate( 'Y-m-d H:i:s', strtotime( $offset ) ),
		);
	}

	/**
	 * The reported case: an organizer who created events and never RSVP'd.
	 */
	public function test_authored_events_are_included_without_an_rsvp() {
		$organiser = self::factory()->user->create();
		$event_id  = $this->make_event( $organiser );

		$this->assertSame(
			array( $event_id ),
			$this->event_ids( get_upcoming_events( $organiser ) ),
			'An event the member organizes should be listed even with no RSVP.'
		);
	}

	/**
	 * The original behaviour still holds for a member who only RSVPs.
	 */
	public function test_attending_events_are_included_without_authorship() {
		$organiser = self::factory()->user->create();
		$member    = self::factory()->user->create();
		$event_id  = $this->make_event( $organiser );

		$this->rsvp( $member, $event_id );

		$this->assertSame(
			array( $event_id ),
			$this->event_ids( get_upcoming_events( $member ) ),
			"An event the member RSVP'd to should be listed even though someone else authored it."
		);
	}

	/**
	 * An organizer who also RSVPs to their own event sees it once.
	 */
	public function test_authored_and_attending_event_is_listed_once() {
		$organiser = self::factory()->user->create();
		$event_id  = $this->make_event( $organiser );

		$this->rsvp( $organiser, $event_id );

		$this->assertSame(
			array( $event_id ),
			$this->event_ids( get_upcoming_events( $organiser ) ),
			'An event that is both organized and RSVPed should not be duplicated.'
		);
	}

	/**
	 * Events that have already finished stay out, however they qualify.
	 */
	public function test_past_events_are_excluded() {
		$organiser = self::factory()->user->create();
		$member    = self::factory()->user->create();

		$this->make_event( $organiser, '-2 days' );

		$past_rsvp = $this->make_event( $organiser, '-3 days' );
		$this->rsvp( $member, $past_rsvp );

		$this->assertSame(
			array(),
			$this->event_ids( get_upcoming_events( $organiser ) ),
			'A finished event the member organized should not be listed.'
		);
		$this->assertSame(
			array(),
			$this->event_ids( get_upcoming_events( $member ) ),
			"A finished event the member RSVP'd to should not be listed."
		);
	}

	/**
	 * Someone else's events are not "mine".
	 */
	public function test_other_members_events_are_excluded() {
		$organiser = self::factory()->user->create();
		$stranger  = self::factory()->user->create();

		$this->make_event( $organiser );

		$this->assertSame(
			array(),
			$this->event_ids( get_upcoming_events( $stranger ) ),
			'A member should not see events they neither organize nor attend.'
		);
	}

	/**
	 * The list is ordered by start time, since it is billed as upcoming.
	 */
	public function test_events_are_ordered_soonest_first() {
		$organiser = self::factory()->user->create();

		$later   = $this->make_event( $organiser, '+10 days' );
		$sooner  = $this->make_event( $organiser, '+2 days' );
		$between = $this->make_event( $organiser, '+5 days' );

		$this->assertSame(
			array( $sooner, $between, $later ),
			$this->event_ids( get_upcoming_events( $organiser ) ),
			'Upcoming events should be ordered soonest first.'
		);
	}

	/**
	 * A member with no events at all resolves to an empty list, which is what
	 * makes the block render its empty state rather than disappearing.
	 */
	public function test_member_with_no_events_resolves_to_empty() {
		$this->assertSame(
			array(),
			get_upcoming_events( self::factory()->user->create() ),
			'A member with nothing on their calendar should resolve to an empty list.'
		);
		$this->assertSame(
			array(),
			get_upcoming_events( 0 ),
			'A logged-out request should resolve to an empty list.'
		);
	}

	/**
	 * A plain event's entry carries the date the block renders, and no
	 * recurrence id to link an occurrence by.
	 */
	public function test_plain_event_entry_carries_its_own_date() {
		$organiser = self::factory()->user->create();
		$event_id  = $this->make_event( $organiser, '+3 days' );

		$this->assertSame(
			array( $this->plain_entry( $event_id, '+3 days' ) ),
			get_upcoming_events( $organiser )
		);
	}

	/**
	 * The reported case (#2056): a member RSVP'd to a date of a weekly series
	 * that started weeks ago, and the series never appeared, because the only
	 * date GatherPress stores for it is that first one.
	 */
	public function test_series_is_listed_from_the_date_the_member_rsvped_to() {
		$this->skip_without_occurrence_tables();

		$organiser = self::factory()->user->create();
		$member    = self::factory()->user->create();
		$series_id = $this->make_series( $organiser );

		$this->add_occurrence( $series_id, '+1 week' );
		$attending = $this->add_occurrence( $series_id, '+2 weeks' );

		$this->rsvp_to_occurrence( $member, $series_id, $attending['recurrence_id'] );

		$this->assertSame(
			array(
				array(
					'event_id'      => $series_id,
					'recurrence_id' => $attending['recurrence_id'],
					'start'         => $attending['start'],
				),
			),
			get_upcoming_events( $member ),
			"A series should be listed on the date the member RSVP'd to, not dropped for its first date having passed."
		);
	}

	/**
	 * Two RSVPs to the same series are two dates in the list, in order.
	 */
	public function test_each_rsvped_date_of_a_series_is_its_own_entry() {
		$this->skip_without_occurrence_tables();

		$organiser = self::factory()->user->create();
		$member    = self::factory()->user->create();
		$series_id = $this->make_series( $organiser );

		$later  = $this->add_occurrence( $series_id, '+3 weeks' );
		$sooner = $this->add_occurrence( $series_id, '+1 week' );

		$this->rsvp_to_occurrence( $member, $series_id, $later['recurrence_id'] );
		$this->rsvp_to_occurrence( $member, $series_id, $sooner['recurrence_id'] );

		$this->assertSame(
			array( $sooner['recurrence_id'], $later['recurrence_id'] ),
			array_column( get_upcoming_events( $member ), 'recurrence_id' ),
			'Both dates the member is attending should be listed, soonest first.'
		);
	}

	/**
	 * An organizer who has RSVP'd to nothing gets the series' next date,
	 * since no RSVP pins them to a particular one.
	 */
	public function test_authored_series_falls_back_to_its_next_date() {
		$this->skip_without_occurrence_tables();

		$organiser = self::factory()->user->create();
		$series_id = $this->make_series( $organiser );

		$this->add_occurrence( $series_id, '+3 weeks' );
		$next = $this->add_occurrence( $series_id, '+1 week' );

		$this->assertSame(
			array(
				array(
					'event_id'      => $series_id,
					'recurrence_id' => $next['recurrence_id'],
					'start'         => $next['start'],
				),
			),
			get_upcoming_events( $organiser ),
			'A series the member organizes should be listed on its next date.'
		);
	}

	/**
	 * An organizer who RSVP'd to one date of their own series sees that date,
	 * and only that one: the authored fallback would otherwise add the
	 * series' next date as a second card for the same event.
	 */
	public function test_organiser_rsvped_to_their_own_series_sees_only_that_date() {
		$this->skip_without_occurrence_tables();

		$organiser = self::factory()->user->create();
		$series_id = $this->make_series( $organiser );

		$this->add_occurrence( $series_id, '+1 week' );
		$attending = $this->add_occurrence( $series_id, '+2 weeks' );

		$this->rsvp_to_occurrence( $organiser, $series_id, $attending['recurrence_id'] );

		$this->assertSame(
			array(
				array(
					'event_id'      => $series_id,
					'recurrence_id' => $attending['recurrence_id'],
					'start'         => $attending['start'],
				),
			),
			get_upcoming_events( $organiser ),
			'An organizer attending one date of their own series should see that date once.'
		);
	}

	/**
	 * A date the member RSVP'd to and has since passed stays out, even while
	 * the series itself runs for weeks yet.
	 */
	public function test_past_date_of_a_running_series_is_excluded() {
		$this->skip_without_occurrence_tables();

		$organiser = self::factory()->user->create();
		$member    = self::factory()->user->create();
		$series_id = $this->make_series( $organiser );

		$attended = $this->add_occurrence( $series_id, '-1 week' );
		$this->add_occurrence( $series_id, '+1 week' );

		$this->rsvp_to_occurrence( $member, $series_id, $attended['recurrence_id'] );

		$this->assertSame(
			array(),
			get_upcoming_events( $member ),
			'A date the member attended should not stay in their upcoming list because later dates exist.'
		);
	}

	/**
	 * A cancelled date is not something the member is going to.
	 */
	public function test_cancelled_date_is_excluded() {
		$this->skip_without_occurrence_tables();

		$organiser = self::factory()->user->create();
		$member    = self::factory()->user->create();
		$series_id = $this->make_series( $organiser );

		$cancelled = $this->add_occurrence( $series_id, '+1 week', 'cancelled' );

		$this->rsvp_to_occurrence( $member, $series_id, $cancelled['recurrence_id'] );

		$this->assertSame(
			array(),
			get_upcoming_events( $member ),
			'A cancelled date should not be listed as one the member is attending.'
		);
		$this->assertSame(
			array(),
			get_upcoming_events( $organiser ),
			'A series whose only remaining date is cancelled should not stand in for it either.'
		);
	}

	/**
	 * Dates of a series and plain events share one ordering.
	 */
	public function test_series_dates_and_plain_events_are_ordered_together() {
		$this->skip_without_occurrence_tables();

		$organiser = self::factory()->user->create();
		$member    = self::factory()->user->create();

		$series_id  = $this->make_series( $organiser );
		$occurrence = $this->add_occurrence( $series_id, '+5 days' );
		$this->rsvp_to_occurrence( $member, $series_id, $occurrence['recurrence_id'] );

		$sooner = $this->make_event( $organiser, '+2 days' );
		$later  = $this->make_event( $organiser, '+10 days' );
		$this->rsvp( $member, $sooner );
		$this->rsvp( $member, $later );

		$this->assertSame(
			array( $sooner, $series_id, $later ),
			$this->event_ids( get_upcoming_events( $member ) ),
			'A series date should be ordered among plain events by when it starts.'
		);
	}
}
