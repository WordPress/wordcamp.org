<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use WP_REST_Request;
use WordCamp\Groups\Group_Stats;
use WordPressdotorg\GatherPress_Recurring_Events\Database as Recurring_Events_Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;

use function WordCamp\Groups\Frontend\REST\create_event;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Group_Stats extends Groups_TestCase {
	/**
	 * @var int
	 */
	protected $site_id;

	/**
	 * Group organizer, who creates the events.
	 *
	 * @var int
	 */
	protected $organizer_id;

	/**
	 * Create a group site with an organizer and a member.
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_cache_flush();

		$this->site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/joinville/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		$this->organizer_id = self::factory()->user->create( array( 'user_login' => 'joinville-organizer' ) );
		add_user_to_blog( $this->site_id, $this->organizer_id, 'administrator' );
		add_user_to_blog( $this->site_id, self::factory()->user->create(), 'subscriber' );

		$this->on_site(
			function () {
				\GatherPress\Core\Setup::get_instance()->check_plugin_version();
				Recurring_Events_Database::maybe_install();
			}
		);

		update_site_meta( $this->site_id, 'wporg_group_location_type', 'online' );
	}

	/**
	 * Remove the group site.
	 */
	protected function tearDown(): void {
		wp_delete_site( $this->site_id );

		parent::tearDown();
	}

	/**
	 * Run a callback on the group site.
	 *
	 * @param callable $callback What to run there.
	 * @return mixed
	 */
	protected function on_site( callable $callback ) {
		switch_to_blog( $this->site_id );

		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Create a published event as the organizer.
	 *
	 * @param array $params Request params, over a one-off event a week out.
	 * @return int Event post ID.
	 */
	protected function create_event( array $params = array() ): int {
		return $this->on_site(
			function () use ( $params ) {
				$previous_user = get_current_user_id();
				wp_set_current_user( $this->organizer_id );

				$request = new WP_REST_Request( 'POST', '/wporg-groups/v1/event' );
				$params  = array_merge(
					array(
						'title'      => 'Monthly Meetup',
						'date'       => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
						'time_start' => '18:00',
						'time_end'   => '20:00',
					),
					$params
				);

				foreach ( $params as $key => $value ) {
					$request->set_param( $key, $value );
				}

				$response = create_event( $request );
				wp_set_current_user( $previous_user );

				$this->assertNotWPError( $response );

				return (int) $response->get_data()['id'];
			}
		);
	}

	/**
	 * Create a one-off event that started some days ago.
	 *
	 * @param int $days_ago Days before now.
	 * @return int Event post ID.
	 */
	protected function create_past_event( int $days_ago ): int {
		$event_id = $this->create_event();
		$start    = time() - $days_ago * DAY_IN_SECONDS;

		$this->on_site(
			function () use ( $event_id, $start ) {
				global $wpdb;

				$wpdb->update(
					sprintf( Event::TABLE_FORMAT, $wpdb->prefix ),
					array(
						'datetime_start_gmt' => gmdate( 'Y-m-d H:i:s', $start ),
						'datetime_end_gmt'   => gmdate( 'Y-m-d H:i:s', $start + 2 * HOUR_IN_SECONDS ),
					),
					array( 'post_id' => $event_id )
				);
			}
		);

		return $event_id;
	}

	/**
	 * RSVP a fresh user to an event.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $status   RSVP status.
	 */
	protected function rsvp( int $event_id, string $status ): void {
		$user_id = self::factory()->user->create();

		$this->on_site(
			function () use ( $event_id, $status, $user_id ) {
				$comment_id = wp_insert_comment(
					array(
						'comment_post_ID'  => $event_id,
						'comment_type'     => 'gatherpress_rsvp',
						'comment_approved' => 1,
						'user_id'          => $user_id,
					)
				);

				wp_set_object_terms( $comment_id, $status, '_gatherpress_rsvp_status' );
			}
		);
	}

	/**
	 * @covers \WordCamp\Groups\Group_Stats\refresh_site
	 */
	public function test_refresh_stores_the_group_summary() {
		$last = $this->create_past_event( 30 );
		$this->rsvp( $last, 'attending' );
		$this->rsvp( $last, 'attending' );
		$this->rsvp( $last, 'not_attending' );

		// Imported from Meetup.com: the count is meta, with no RSVPs behind it.
		$imported = $this->create_past_event( 60 );
		$this->on_site(
			function () use ( $imported ) {
				update_post_meta( $imported, '_wporg_meetup_rsvp_yes_count', 15 );
			}
		);

		// Held, but outside the last 12 months.
		$this->rsvp( $this->create_past_event( 400 ), 'attending' );

		$series = $this->create_event(
			array(
				'title'      => 'Weekly Meetup',
				'date'       => gmdate( 'Y-m-d', strtotime( '+14 days' ) ),
				'recurrence' => array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( strtoupper( substr( gmdate( 'D', strtotime( '+14 days' ) ), 0, 2 ) ) ),
					'end_type'  => 'count',
					'count'     => 3,
				),
			)
		);

		$occurrences = $this->on_site(
			function () use ( $series ) {
				$occurrences = Occurrences::all( $series, 'upcoming', 10 );
				Occurrences::set_status( $series, $occurrences[0]->recurrence_id, 'cancelled' );

				return $occurrences;
			}
		);

		$stats = Group_Stats\refresh_site( $this->site_id );

		$this->assertNotWPError( $stats );
		$this->assertSame( $stats, get_site_meta( $this->site_id, Group_Stats\META_KEY, true ) );
		$this->assertSame( 'Online', $stats['location'] );
		$this->assertSame( 2, $stats['members_count'] );
		$this->assertSame( array( 'joinville-organizer' ), $stats['organizers'] );
		$this->assertSame( 3, $stats['past_events_count'] );
		$this->assertSame( gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), substr( $stats['last_event_at'], 0, 10 ) );
		$this->assertSame( 2, $stats['last_event_rsvps'] );
		$this->assertSame( $occurrences[1]->datetime_start_gmt, $stats['next_event_at'], 'A cancelled date is not the next event.' );
		$this->assertSame( 2, $stats['events_last_12_months'] );
		$this->assertSame( 8.5, $stats['average_rsvps'], 'The imported count stands in for its missing RSVPs.' );
	}

	/**
	 * @covers \WordCamp\Groups\Group_Stats\refresh_site
	 */
	public function test_failed_refresh_keeps_the_previous_summary() {
		update_site_meta( $this->site_id, Group_Stats\META_KEY, array( 'members_count' => 9 ) );
		update_blog_status( $this->site_id, 'archived', 1 );

		$this->assertWPError( Group_Stats\refresh_site( $this->site_id ) );
		$this->assertSame( array( 'members_count' => 9 ), get_site_meta( $this->site_id, Group_Stats\META_KEY, true ) );
	}
}
