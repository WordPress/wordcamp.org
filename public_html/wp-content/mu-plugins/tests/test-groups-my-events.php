<?php

namespace WordCamp\Groups\Frontend\Tests;

use WP_UnitTestCase;

use function WordCamp\Groups\Frontend\My_Events\get_upcoming_event_ids;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/wporg-groups-frontend/inc/my-events.php';

/**
 * Tests for the my-events block's notion of "my events".
 *
 * The block used to answer purely from RSVP data, so an organiser who had
 * created events but never clicked RSVP saw an empty block (#1810). These
 * cover both halves of the definition and the boundaries between them:
 * authored events count, attending events count, an event that is both is
 * listed once, and events that have finished or belong to someone else stay
 * out.
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
	 * @return void
	 */
	protected function rsvp( int $user_id, int $event_id ): void {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $event_id,
				'comment_type'     => 'gatherpress_rsvp',
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);

		wp_set_object_terms( $comment_id, 'attending', '_gatherpress_rsvp_status' );
	}

	/**
	 * The reported case: an organiser who created events and never RSVP'd.
	 */
	public function test_authored_events_are_included_without_an_rsvp() {
		$organiser = self::factory()->user->create();
		$event_id  = $this->make_event( $organiser );

		$this->assertSame(
			array( $event_id ),
			get_upcoming_event_ids( $organiser ),
			'An event the member organises should be listed even with no RSVP.'
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
			get_upcoming_event_ids( $member ),
			"An event the member RSVP'd to should be listed even though someone else authored it."
		);
	}

	/**
	 * An organiser who also RSVPs to their own event sees it once.
	 */
	public function test_authored_and_attending_event_is_listed_once() {
		$organiser = self::factory()->user->create();
		$event_id  = $this->make_event( $organiser );

		$this->rsvp( $organiser, $event_id );

		$this->assertSame(
			array( $event_id ),
			get_upcoming_event_ids( $organiser ),
			'An event that is both organised and RSVPed should not be duplicated.'
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
			get_upcoming_event_ids( $organiser ),
			'A finished event the member organised should not be listed.'
		);
		$this->assertSame(
			array(),
			get_upcoming_event_ids( $member ),
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
			get_upcoming_event_ids( $stranger ),
			'A member should not see events they neither organise nor attend.'
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
			get_upcoming_event_ids( $organiser ),
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
			get_upcoming_event_ids( self::factory()->user->create() ),
			'A member with nothing on their calendar should resolve to an empty list.'
		);
		$this->assertSame(
			array(),
			get_upcoming_event_ids( 0 ),
			'A logged-out request should resolve to an empty list.'
		);
	}
}
