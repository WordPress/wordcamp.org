<?php

namespace WordCamp\Groups\Tests;

use GatherPress\Core\Event\Event;
use WP_REST_Request;
use WordCamp\Groups\Events_Feed;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Database as Recurring_Events_Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;

use function WordCamp\Groups\Frontend\REST\create_event;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';
require_once dirname( __DIR__, 3 ) . '/sunrise-groups.php';

/**
 * @group groups
 */
class Test_Groups_Events_Feed extends Groups_TestCase {
	/**
	 * Group sites created for the current test.
	 *
	 * @var int[]
	 */
	protected $group_sites = array();

	/**
	 * `REQUEST_URI` before the test, which the sunrise tests overwrite.
	 *
	 * @var string|null
	 */
	protected $request_uri;

	/**
	 * Create two group sites with working GatherPress tables.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null;

		// `Database_TestCase` rolls the database back but not the object cache,
		// so a new site can inherit the cached options of an earlier fixture
		// site that had the same ID.
		wp_cache_flush();

		$this->group_sites = array(
			'brisbane' => $this->create_group_site( 'brisbane' ),
			'hobart'   => $this->create_group_site( 'hobart' ),
		);
	}

	/**
	 * Clean up sites, the request globals, and the page cache.
	 */
	protected function tearDown(): void {
		foreach ( $this->group_sites as $site_id ) {
			wp_delete_site( $site_id );
		}

		$this->group_sites = array();
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}

		$_GET = array();
		wp_cache_flush_group( Events_Feed\CACHE_GROUP );

		parent::tearDown();
	}

	/**
	 * Create a group site with GatherPress tables and pretty permalinks.
	 *
	 * Pretty permalinks on the group site, while the test runs on the network
	 * root with whatever it has, is the production shape the feed's permalink
	 * handling exists for.
	 *
	 * @param string $slug Group slug.
	 * @return int Site ID.
	 */
	protected function create_group_site( string $slug ): int {
		$site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => "/group/$slug/",
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		$this->with_site(
			$site_id,
			function () use ( $slug ) {
				\GatherPress\Core\Setup::get_instance()->check_plugin_version();
				Recurring_Events_Database::maybe_install();
				update_option( 'blogname', ucfirst( $slug ) . ' &amp; Friends' );
				update_option( 'permalink_structure', '/%year%/%monthnum%/%day%/%postname%/' );
			}
		);

		return $site_id;
	}

	/**
	 * Run a callback on a group site and restore the previous blog.
	 *
	 * @param int      $site_id  Group site.
	 * @param callable $callback What to run there.
	 * @return mixed The callback's return value.
	 */
	protected function with_site( int $site_id, callable $callback ) {
		switch_to_blog( $site_id );

		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Create a published event on a group site via the production REST path.
	 *
	 * @param int   $site_id Group site.
	 * @param array $params  Request params, over a one-off event a week out.
	 * @return int Event post ID.
	 */
	protected function create_site_event( int $site_id, array $params = array() ): int {
		return $this->with_site(
			$site_id,
			function () use ( $params ) {
				$previous_user = get_current_user_id();
				wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

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
	 * Give an event's venue post the meta GatherPress's geocoder would write.
	 *
	 * @param int   $site_id  Group site.
	 * @param int   $event_id Event post ID.
	 * @param array $meta     Venue meta, unprefixed.
	 * @return int Venue post ID.
	 */
	protected function geocode_event_venue( int $site_id, int $event_id, array $meta ): int {
		return $this->with_site(
			$site_id,
			function () use ( $event_id, $meta ) {
				$venue = \GatherPress\Core\Venue\Setup::get_instance()->get_venue_post_from_event_post_id( $event_id );
				$this->assertNotNull( $venue );

				foreach ( $meta as $key => $value ) {
					update_post_meta( $venue->ID, 'gatherpress_' . $key, $value );
				}

				return $venue->ID;
			}
		);
	}

	/**
	 * RSVP a fresh user to an event, optionally to one occurrence.
	 *
	 * @param int    $site_id       Group site.
	 * @param int    $event_id      Event post ID.
	 * @param string $status        RSVP status term slug.
	 * @param string $recurrence_id Occurrence, or empty.
	 * @param array  $user          User overrides.
	 */
	protected function rsvp( int $site_id, int $event_id, string $status, string $recurrence_id = '', array $user = array() ): void {
		$user_id = self::factory()->user->create( $user );

		$this->with_site(
			$site_id,
			function () use ( $event_id, $status, $recurrence_id, $user_id ) {
				$comment_id = wp_insert_comment(
					array(
						'comment_post_ID'  => $event_id,
						'comment_type'     => 'gatherpress_rsvp',
						'comment_approved' => 1,
						'user_id'          => $user_id,
					)
				);

				wp_set_object_terms( $comment_id, $status, '_gatherpress_rsvp_status' );

				if ( '' !== $recurrence_id ) {
					Recurring_Events_Database::map_comment( $comment_id, $event_id, $recurrence_id );
				}
			}
		);
	}

	/**
	 * Move an event's GatherPress dates.
	 *
	 * @param int    $site_id  Group site.
	 * @param int    $event_id Event post ID.
	 * @param string $start    Start, GMT.
	 * @param string $end      End, GMT.
	 */
	protected function set_event_dates( int $site_id, int $event_id, string $start, string $end ): void {
		$this->with_site(
			$site_id,
			function () use ( $event_id, $start, $end ) {
				global $wpdb;

				$wpdb->update(
					sprintf( Event::TABLE_FORMAT, $wpdb->prefix ),
					array(
						'datetime_start_gmt' => $start,
						'datetime_end_gmt'   => $end,
					),
					array( 'post_id' => $event_id )
				);
			}
		);
	}

	/**
	 * Fetch a feed page through the REST callback.
	 *
	 * @param int $after Cursor.
	 * @return array|\WP_Error Response data, or the error.
	 */
	protected function get_page( int $after = 0 ) {
		$request = new WP_REST_Request( 'GET', '/wporg-groups/v1/events' );
		$request->set_param( 'after', $after );

		$response = Events_Feed\get_feed( $request );

		return is_wp_error( $response ) ? $response : $response->get_data();
	}

	/**
	 * Events in a page, keyed by source ID.
	 *
	 * @param array $page Feed page.
	 * @return array[]
	 */
	protected function by_source_id( array $page ): array {
		return array_column( $page['events'], null, 'source_id' );
	}

	/**
	 * The expected source ID of a one-off event.
	 *
	 * @param int $site_id  Group site.
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	protected function source_id( int $site_id, int $event_id ): string {
		return 'gp-' . base_convert( (string) $site_id, 10, 36 ) . '-' . base_convert( (string) $event_id, 10, 36 );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\format_event
	 */
	public function test_one_off_event_is_listed_with_its_details() {
		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event(
			$brisbane,
			array(
				'title'             => 'Hooks &amp; Filters',
				'new_venue_name'    => 'River Hall',
				'new_venue_address' => '1 River St, Brisbane',
			)
		);

		$this->geocode_event_venue(
			$brisbane,
			$event_id,
			array(
				'latitude'     => '-27.4705',
				'longitude'    => '153.0260',
				'city'         => 'Brisbane',
				'state'        => 'Queensland',
				'country_code' => 'au',
			)
		);

		$this->rsvp( $brisbane, $event_id, 'attending' );
		$this->rsvp( $brisbane, $event_id, 'attending' );
		$this->rsvp( $brisbane, $event_id, 'not_attending' );

		$events = $this->by_source_id( $this->get_page() );
		$event  = $events[ $this->source_id( $brisbane, $event_id ) ] ?? null;

		$this->assertNotNull( $event );
		$this->assertSame( 'scheduled', $event['status'] );
		$this->assertSame( 'Hooks & Filters', $event['title'], 'Titles are plain text, not HTML.' );
		$this->assertSame( 2, $event['attending'], 'Only attending RSVPs are counted.' );
		$this->assertFalse( $event['online'] );
		$this->assertSame(
			array(
				'name'         => 'River Hall',
				'city'         => 'Brisbane',
				'state'        => 'Queensland',
				'country_code' => 'AU',
				'latitude'     => -27.4705,
				'longitude'    => 153.026,
			),
			$event['venue']
		);
		$this->assertSame( 'Brisbane & Friends', $event['group']['name'] );
		$this->assertSame( get_home_url( $brisbane, '/' ), $event['group']['url'] );
		$this->assertStringContainsString( '/group/brisbane/', $event['group']['url'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $event['start_gmt'] );
		$this->assertIsInt( $event['utc_offset'] );
	}

	/**
	 * The network root, where the feed runs, has no event permastruct of its
	 * own; without the switch handling every URL is a `?gatherpress_event=`
	 * link, and the root's own state must be back as it was afterwards.
	 *
	 * The root is put in that production shape first: plain permalinks and no
	 * event permastruct. The test install's root has both, which would hide a
	 * missing permastruct.
	 *
	 * @covers \WordCamp\Groups\Events_Feed\collect_site_events
	 */
	public function test_event_urls_use_the_group_sites_permalinks() {
		global $wp_rewrite;

		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event( $brisbane, array( 'title' => 'Pretty Links' ) );

		$original_structure    = $wp_rewrite->permalink_structure;
		$original_permastructs = $wp_rewrite->extra_permastructs;

		try {
			$wp_rewrite->set_permalink_structure( '' );
			$wp_rewrite->remove_permastruct( Event::POST_TYPE );

			$events = $this->by_source_id( $this->get_page() );
			$url    = $events[ $this->source_id( $brisbane, $event_id ) ]['url'];

			$this->assertSame( get_home_url( $brisbane, '/event/pretty-links/' ), $url );
			$this->assertSame( '', $wp_rewrite->permalink_structure, 'The root keeps its plain permalinks.' );
			$this->assertArrayNotHasKey( Event::POST_TYPE, $wp_rewrite->extra_permastructs, 'The root gains no event permastruct.' );
			$this->assertSame( self::$groups_root_site_id, get_current_blog_id() );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
			$wp_rewrite->extra_permastructs = $original_permastructs;
		}
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_event_rows
	 */
	public function test_only_public_events_are_listed() {
		$brisbane  = $this->group_sites['brisbane'];
		$public    = $this->create_site_event( $brisbane, array( 'title' => 'Public' ) );
		$draft     = $this->create_site_event( $brisbane, array( 'title' => 'Draft' ) );
		$protected = $this->create_site_event( $brisbane, array( 'title' => 'Protected' ) );
		$private   = $this->create_site_event( $brisbane, array( 'title' => 'Private' ) );

		$this->with_site(
			$brisbane,
			function () use ( $draft, $protected, $private ) {
				wp_update_post(
					array(
						'ID'          => $draft,
						'post_status' => 'draft',
					)
				);
				wp_update_post(
					array(
						'ID'            => $protected,
						'post_password' => 'secret',
					)
				);
				wp_update_post(
					array(
						'ID'          => $private,
						'post_status' => 'private',
					)
				);
			}
		);

		$events = $this->by_source_id( $this->get_page() );

		$this->assertArrayHasKey( $this->source_id( $brisbane, $public ), $events );
		$this->assertArrayNotHasKey( $this->source_id( $brisbane, $draft ), $events );
		$this->assertArrayNotHasKey( $this->source_id( $brisbane, $protected ), $events );
		$this->assertArrayNotHasKey( $this->source_id( $brisbane, $private ), $events );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_event_rows
	 */
	public function test_only_events_in_the_window_are_listed() {
		$brisbane = $this->group_sites['brisbane'];
		$ended    = $this->create_site_event( $brisbane, array( 'title' => 'Ended' ) );
		$just     = $this->create_site_event( $brisbane, array( 'title' => 'Just ended' ) );
		$far      = $this->create_site_event( $brisbane, array( 'title' => 'Too far' ) );

		$this->set_event_dates( $brisbane, $ended, gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) ), gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ) );
		$this->set_event_dates( $brisbane, $just, gmdate( 'Y-m-d H:i:s', strtotime( '-3 hours' ) ), gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) );
		$this->set_event_dates( $brisbane, $far, gmdate( 'Y-m-d H:i:s', strtotime( '+13 months' ) ), gmdate( 'Y-m-d H:i:s', strtotime( '+13 months +2 hours' ) ) );

		$events = $this->by_source_id( $this->get_page() );

		$this->assertArrayNotHasKey( $this->source_id( $brisbane, $ended ), $events );
		$this->assertArrayHasKey( $this->source_id( $brisbane, $just ), $events, 'The importer allows a day of leeway for timezones.' );
		$this->assertArrayNotHasKey( $this->source_id( $brisbane, $far ), $events );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_event_rows
	 */
	public function test_recurring_series_is_listed_per_occurrence() {
		$brisbane = $this->group_sites['brisbane'];
		$date     = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$event_id = $this->create_site_event(
			$brisbane,
			array(
				'title'      => 'Weekly Meetup',
				'date'       => $date,
				'recurrence' => array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( strtoupper( substr( gmdate( 'D', strtotime( $date ) ), 0, 2 ) ) ),
					'end_type'  => 'count',
					'count'     => 3,
				),
			)
		);

		$occurrences = $this->with_site(
			$brisbane,
			function () use ( $event_id ) {
				$occurrences = Occurrences::all( $event_id, 'upcoming', 10 );
				Occurrences::set_status( $event_id, $occurrences[2]->recurrence_id, 'cancelled' );

				return $occurrences;
			}
		);

		$this->assertCount( 3, $occurrences );
		$this->rsvp( $brisbane, $event_id, 'attending', $occurrences[0]->recurrence_id );

		$events = $this->by_source_id( $this->get_page() );
		$series = $this->source_id( $brisbane, $event_id );

		$this->assertArrayNotHasKey( $series, $events, 'A series is listed only through its occurrences.' );

		foreach ( $occurrences as $index => $occurrence ) {
			$event = $events[ $series . '-' . $occurrence->recurrence_id ] ?? null;

			$this->assertNotNull( $event );
			$this->assertSame( 2 === $index ? 'cancelled' : 'scheduled', $event['status'] );
			$this->assertSame( 0 === $index ? 1 : 0, $event['attending'] );
			$this->assertSame( $occurrence->datetime_start_gmt, $event['start_gmt'] );
			$this->assertStringEndsWith( '/event/weekly-meetup/' . $occurrence->recurrence_id . '/', $event['url'] );
		}
	}

	/**
	 * Occurrences are read from their own table, so the series' password has
	 * to be checked there too.
	 *
	 * @covers \WordCamp\Groups\Events_Feed\get_event_rows
	 */
	public function test_password_protected_series_lists_no_occurrences() {
		$brisbane = $this->group_sites['brisbane'];
		$date     = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$event_id = $this->create_site_event(
			$brisbane,
			array(
				'title'      => 'Secret Series',
				'date'       => $date,
				'recurrence' => array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( strtoupper( substr( gmdate( 'D', strtotime( $date ) ), 0, 2 ) ) ),
					'end_type'  => 'count',
					'count'     => 3,
				),
			)
		);

		$occurrences = $this->with_site(
			$brisbane,
			function () use ( $event_id ) {
				wp_update_post(
					array(
						'ID'            => $event_id,
						'post_password' => 'secret',
					)
				);

				return Occurrences::all( $event_id, 'upcoming', 10 );
			}
		);

		$this->assertNotEmpty( $occurrences, 'The series still has occurrences to leak.' );

		$series = $this->source_id( $brisbane, $event_id );
		$listed = array_filter(
			array_keys( $this->by_source_id( $this->get_page() ) ),
			static function ( string $source_id ) use ( $series ): bool {
				return $series === $source_id || str_starts_with( $source_id, $series . '-' );
			}
		);

		$this->assertSame( array(), $listed );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_event_venues
	 */
	public function test_online_event_never_includes_its_meeting_link() {
		$brisbane = $this->group_sites['brisbane'];
		$link     = 'https://meet.example.com/secret-room-4815';
		$event_id = $this->create_site_event(
			$brisbane,
			array(
				'title'             => 'Online Meetup',
				'is_online'         => true,
				'online_event_link' => $link,
			)
		);

		$page   = $this->get_page();
		$events = $this->by_source_id( $page );
		$event  = $events[ $this->source_id( $brisbane, $event_id ) ];

		$this->assertTrue( $event['online'] );
		$this->assertNull( $event['venue'] );
		$this->assertStringNotContainsString( 'secret-room', wp_json_encode( $page ) );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_event_venues
	 */
	public function test_hybrid_event_keeps_its_venue() {
		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event(
			$brisbane,
			array(
				'is_online'         => true,
				'online_event_link' => 'https://meet.example.com/hybrid',
				'new_venue_name'    => 'Hybrid Hall',
				'new_venue_address' => '2 River St, Brisbane',
			)
		);

		$this->geocode_event_venue(
			$brisbane,
			$event_id,
			array(
				'latitude'  => '-27.47',
				'longitude' => '153.02',
			)
		);

		$event = $this->by_source_id( $this->get_page() )[ $this->source_id( $brisbane, $event_id ) ];

		$this->assertTrue( $event['online'] );
		$this->assertSame( 'Hybrid Hall', $event['venue']['name'] );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_event_venues
	 */
	public function test_password_protected_venue_is_left_out() {
		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event(
			$brisbane,
			array(
				'new_venue_name'    => 'Secret Garage',
				'new_venue_address' => '13 Hidden Lane, Brisbane',
			)
		);

		$venue_id = $this->geocode_event_venue(
			$brisbane,
			$event_id,
			array(
				'latitude'  => '-27.5',
				'longitude' => '153.1',
				'phone'     => '+61 7 5555 0100',
				'website'   => 'https://secret.example.com',
			)
		);

		$this->with_site(
			$brisbane,
			function () use ( $venue_id ) {
				wp_update_post(
					array(
						'ID'            => $venue_id,
						'post_password' => 'secret',
					)
				);
			}
		);

		$page  = $this->get_page();
		$event = $this->by_source_id( $page )[ $this->source_id( $brisbane, $event_id ) ];

		$this->assertNull( $event['venue'], 'The event is still listed, with no venue.' );
		$this->assertStringNotContainsString( 'Secret Garage', wp_json_encode( $page ) );
		$this->assertStringNotContainsString( 'Hidden Lane', wp_json_encode( $page ) );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\format_venue
	 */
	public function test_ungeocoded_venue_has_no_coordinates() {
		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event(
			$brisbane,
			array(
				'new_venue_name'    => 'Not Yet Geocoded',
				'new_venue_address' => '3 River St, Brisbane',
			)
		);

		$this->geocode_event_venue(
			$brisbane,
			$event_id,
			array(
				'latitude'  => '',
				'longitude' => '',
			)
		);

		$event = $this->by_source_id( $this->get_page() )[ $this->source_id( $brisbane, $event_id ) ];

		$this->assertSame( 'Not Yet Geocoded', $event['venue']['name'] );
		$this->assertNull( $event['venue']['latitude'] );
		$this->assertNull( $event['venue']['longitude'] );
	}

	/**
	 * Nothing about who RSVPed, nor venue contact details, may reach a
	 * public feed.
	 *
	 * @covers \WordCamp\Groups\Events_Feed\format_event
	 */
	public function test_no_personal_or_contact_data_in_the_feed() {
		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event(
			$brisbane,
			array(
				'new_venue_name'    => 'Contact Hall',
				'new_venue_address' => '4 River St, Brisbane',
			)
		);

		$this->geocode_event_venue(
			$brisbane,
			$event_id,
			array(
				'latitude'  => '-27.4',
				'longitude' => '153.0',
				'phone'     => '+61 7 5555 0199',
				'website'   => 'https://contact-hall.example.com',
			)
		);

		$this->rsvp(
			$brisbane,
			$event_id,
			'attending',
			'',
			array(
				'user_login'   => 'feed_attendee_login',
				'user_email'   => 'feed-attendee@example.com',
				'display_name' => 'Feed Attendee Name',
			)
		);

		$json = wp_json_encode( $this->get_page() );

		foreach ( array( 'feed_attendee_login', 'feed-attendee@example.com', 'Feed Attendee Name', '5555 0199', 'contact-hall.example.com' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_description
	 */
	public function test_description_is_short_plain_text() {
		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event( $brisbane );

		$this->with_site(
			$brisbane,
			function () use ( $event_id ) {
				wp_update_post(
					array(
						'ID'           => $event_id,
						'post_content' => "<!-- wp:paragraph --><p>Bring a <strong>laptop</strong> &amp; questions.</p><!-- /wp:paragraph -->\n[gallery ids=\"1\"]" . str_repeat( ' word', 100 ),
					)
				);
			}
		);

		$description = $this->by_source_id( $this->get_page() )[ $this->source_id( $brisbane, $event_id ) ]['description'];

		$this->assertStringStartsWith( 'Bring a laptop & questions.', $description );
		$this->assertStringNotContainsString( '<', $description );
		$this->assertStringNotContainsString( 'wp:paragraph', $description );
		$this->assertStringNotContainsString( '[gallery', $description );
		$this->assertLessThanOrEqual( Events_Feed\DESCRIPTION_WORDS, str_word_count( rtrim( $description, '…' ) ) );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_group_site_ids
	 */
	public function test_inactive_sites_and_the_root_are_left_out() {
		$brisbane = $this->group_sites['brisbane'];
		$hobart   = $this->group_sites['hobart'];
		$listed   = $this->create_site_event( $brisbane );
		$archived = $this->create_site_event( $hobart );

		update_blog_status( $hobart, 'archived', '1' );

		$site_ids = Events_Feed\get_group_site_ids();
		$events   = $this->by_source_id( $this->get_page() );

		$this->assertNotContains( GROUPS_ROOT_BLOG_ID, $site_ids );
		$this->assertNotContains( $hobart, $site_ids );
		$this->assertArrayHasKey( $this->source_id( $brisbane, $listed ), $events );
		$this->assertArrayNotHasKey( $this->source_id( $hobart, $archived ), $events );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\build_page
	 */
	public function test_pages_follow_site_ids() {
		$extra  = array();
		$needed = Events_Feed\SITES_PER_PAGE + 1 - count( Events_Feed\get_group_site_ids() );

		// Enough sites for a second page.
		for ( $i = 0; $i < $needed; $i++ ) {
			$extra[] = $this->create_group_site( 'extra-' . $i );
		}

		$this->group_sites = array_merge( $this->group_sites, $extra );
		$site_ids          = Events_Feed\get_group_site_ids();
		$last              = end( $site_ids );
		$this->create_site_event( $last, array( 'title' => 'On the second page' ) );

		$first = $this->get_page();

		$this->assertTrue( $first['complete'] );
		$this->assertSame( $site_ids[ Events_Feed\SITES_PER_PAGE - 1 ], $first['next'] );

		$second = $this->get_page( $first['next'] );

		$this->assertNull( $second['next'] );
		$this->assertSame( array( 'On the second page' ), array_column( $second['events'], 'title' ) );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_feed
	 */
	public function test_cursor_must_be_a_group_site() {
		foreach ( array( GROUPS_ROOT_BLOG_ID, 1, 999999 ) as $after ) {
			$response = $this->get_page( $after );

			$this->assertWPError( $response, "after=$after" );
			$this->assertSame( 400, $response->get_error_data()['status'] );
		}

		$this->assertIsArray( $this->get_page( $this->group_sites['brisbane'] ) );
	}

	/**
	 * A page that couldn't read a site must say so, so the importer won't
	 * delete that site's events, and mustn't be cached.
	 *
	 * @covers \WordCamp\Groups\Events_Feed\build_page
	 */
	public function test_unreadable_site_marks_the_page_incomplete() {
		$brisbane = $this->group_sites['brisbane'];
		$event_id = $this->create_site_event( $brisbane );

		$page = Events_Feed\build_page( array( $brisbane, 999999 ), 0, time() );

		$this->assertFalse( $page['complete'] );
		$this->assertArrayHasKey( $this->source_id( $brisbane, $event_id ), $this->by_source_id( $page ), 'Readable sites are still listed.' );

		$break_reads = static function ( $query ) {
			return str_contains( $query, 'AS dates' ) ? 'SELECT * FROM table_that_does_not_exist' : $query;
		};

		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $break_reads );

		try {
			$broken = $this->get_page();
		} finally {
			remove_filter( 'query', $break_reads );
			$wpdb->suppress_errors( $suppressed );
		}

		$this->assertFalse( $broken['complete'], 'A failed query must not read as "no events".' );
		$this->assertFalse( wp_cache_get( 'page:0', Events_Feed\CACHE_GROUP ), 'An incomplete page is not cached.' );
	}

	/**
	 * The occurrence and RSVP reads fail the page too. A failed occurrence read
	 * would otherwise drop every date of a series, and the importer would
	 * delete them all.
	 *
	 * @covers \WordCamp\Groups\Events_Feed\get_event_rows
	 * @covers \WordCamp\Groups\Events_Feed\get_attending_counts
	 */
	public function test_failed_occurrence_or_rsvp_read_marks_the_page_incomplete() {
		global $wpdb;

		$date = gmdate( 'Y-m-d', strtotime( '+14 days' ) );

		$this->create_site_event(
			$this->group_sites['brisbane'],
			array(
				'title'      => 'Weekly Meetup',
				'date'       => $date,
				'recurrence' => array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( strtoupper( substr( gmdate( 'D', strtotime( $date ) ), 0, 2 ) ) ),
					'end_type'  => 'count',
					'count'     => 2,
				),
			)
		);

		$this->assertTrue( $this->get_page()['complete'] );
		wp_cache_flush_group( Events_Feed\CACHE_GROUP );

		foreach ( array( 'occurrences.series_post_id AS post_id', 'comments.comment_post_ID AS event_id' ) as $needle ) {
			$break_reads = static function ( $query ) use ( $needle ) {
				return str_contains( $query, $needle ) ? 'SELECT * FROM table_that_does_not_exist' : $query;
			};

			$suppressed = $wpdb->suppress_errors( true );
			add_filter( 'query', $break_reads );

			try {
				$page = $this->get_page();
			} finally {
				remove_filter( 'query', $break_reads );
				$wpdb->suppress_errors( $suppressed );
			}

			$this->assertFalse( $page['complete'], $needle );
		}
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_feed
	 */
	public function test_complete_pages_are_cached() {
		$brisbane = $this->group_sites['brisbane'];
		$this->create_site_event( $brisbane, array( 'title' => 'Before' ) );

		$first = $this->get_page();
		$this->create_site_event( $brisbane, array( 'title' => 'After' ) );
		$second = $this->get_page();

		$this->assertSame( $first, $second );

		$request  = new WP_REST_Request( 'GET', '/wporg-groups/v1/events' );
		$response = Events_Feed\get_feed( $request );

		$this->assertSame( 'public, max-age=' . Events_Feed\CLIENT_MAX_AGE, $response->get_headers()['Cache-Control'] );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\get_source_id
	 */
	public function test_source_ids_fit_the_importer_column() {
		$this->assertSame( 'gp-1f-1qd', Events_Feed\get_source_id( 51, 2245, '' ) );
		$this->assertSame( 'gp-1f-1qd-20261103T180000', Events_Feed\get_source_id( 51, 2245, '20261103T180000' ) );
		$this->assertSame( 'gp-1f-1qd-20261103', Events_Feed\get_source_id( 51, 2245, '20261103' ) );

		// The largest IDs this network will plausibly ever reach still fit.
		$this->assertLessThanOrEqual( Events_Feed\SOURCE_ID_MAX_LENGTH, strlen( Events_Feed\get_source_id( 2147483647, 2147483647, '20261103T180000' ) ) );

		$this->assertNull( Events_Feed\get_source_id( 51, 2245, '../../etc' ), 'A malformed recurrence ID is refused.' );
		$this->assertNull( Events_Feed\get_source_id( PHP_INT_MAX, PHP_INT_MAX, '20261103T180000' ), 'An over-long ID is refused, not truncated.' );
	}

	/**
	 * A non-Latin title makes a percent-encoded 200-character slug, and the
	 * importer tells events apart by the first 255 characters of their URL.
	 * Long URLs get a short form, so neither a series' occurrences nor two
	 * events with the same long title are stored as one.
	 *
	 * @covers \WordCamp\Groups\Events_Feed\get_event_url
	 */
	public function test_long_urls_stay_distinct_for_the_importer() {
		$site  = $this->create_group_site( 'wordpress-hachioji' );
		$title = '八王子ワードプレス勉強会 毎月の定例もくもく会とライトニングトーク';
		$date  = gmdate( 'Y-m-d', strtotime( '+14 days' ) );

		$this->group_sites[] = $site;

		$series = $this->create_site_event(
			$site,
			array(
				'title'      => $title,
				'date'       => $date,
				'recurrence' => array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( strtoupper( substr( gmdate( 'D', strtotime( $date ) ), 0, 2 ) ) ),
					'end_type'  => 'count',
					'count'     => 2,
				),
			)
		);
		// A title of its own, or WordPress shortens the slug to fit a suffix.
		$first  = $this->create_site_event( $site, array( 'title' => 'Ежемесячная встреча сообщества WordPress в Новосибирске' ) );
		$second = $this->create_site_event( $site, array( 'title' => 'Ежемесячная встреча сообщества WordPress в Новосибирске' ) );
		$short  = $this->create_site_event( $site, array( 'title' => 'Short Title' ) );

		list( $permalink, $occurrence ) = $this->with_site(
			$site,
			function () use ( $series ) {
				return array( Context::series_url( $series ), Occurrences::all( $series, 'upcoming', 1 )[0]->recurrence_id );
			}
		);

		$this->assertGreaterThan( Events_Feed\URL_MAX_LENGTH, strlen( $permalink ), 'The fixture makes a long permalink.' );

		$by_id    = $this->by_source_id( $this->get_page() );
		$prefix   = 'gp-' . base_convert( (string) $site, 10, 36 ) . '-';
		$prefixes = array();

		foreach ( $by_id as $source_id => $event ) {
			if ( str_starts_with( $source_id, $prefix ) ) {
				$this->assertLessThanOrEqual( Events_Feed\URL_MAX_LENGTH, strlen( $event['url'] ) );
				$prefixes[] = substr( $event['url'], 0, Events_Feed\URL_MAX_LENGTH );
			}
		}

		$this->assertCount( 5, $prefixes );
		$this->assertSame( $prefixes, array_unique( $prefixes ) );

		$this->assertSame(
			get_home_url( $site, '/?post_type=gatherpress_event&p=' . $series . '&gpre_occurrence=' . $occurrence ),
			$by_id[ $this->source_id( $site, $series ) . '-' . $occurrence ]['url']
		);
		$this->assertSame( get_home_url( $site, '/?post_type=gatherpress_event&p=' . $first ), $by_id[ $this->source_id( $site, $first ) ]['url'] );
		$this->assertNotSame( $by_id[ $this->source_id( $site, $first ) ]['url'], $by_id[ $this->source_id( $site, $second ) ]['url'] );
		$this->assertSame( get_home_url( $site, '/event/short-title/' ), $by_id[ $this->source_id( $site, $short ) ]['url'], 'Short permalinks are kept.' );
	}

	/**
	 * @covers \WordCamp\Groups\Events_Feed\register_route
	 */
	public function test_route_exists_on_the_network_root_only() {
		$this->assertArrayHasKey( '/wporg-groups/v1/events', $this->get_fresh_routes() );

		$routes = $this->with_site( $this->group_sites['brisbane'], array( $this, 'get_fresh_routes' ) );

		$this->assertArrayNotHasKey( '/wporg-groups/v1/events', $routes );
	}

	/**
	 * Build a new REST server on the current blog, the way a request does.
	 *
	 * @return array Registered routes in the `wporg-groups/v1` namespace.
	 */
	public function get_fresh_routes(): array {
		global $wp_rest_server;

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Forces `rest_get_server()` to fire `rest_api_init` again.
		$routes         = rest_get_server()->get_routes( 'wporg-groups/v1' );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Don't leak this blog's routes into the next test.

		return $routes;
	}

	/**
	 * Exactly the feed route is reachable on the redirecting network root.
	 *
	 * @covers \WordCamp\Sunrise\Groups\is_reachable_root_endpoint
	 */
	public function test_sunrise_lets_only_the_feed_route_through() {
		$reachable = array(
			'/group/?rest_route=/wporg-groups/v1/events',
			'/group/?rest_route=/wporg-groups/v1/events/',
			'/group/?rest_route=/wporg-groups/v1/events&after=12',
			'/group/index.php?rest_route=/wporg-groups/v1/events',
			'/group/wp-login.php',
		);

		// The pretty spelling stays redirected: a `rest_route` query var
		// overrides its path, and a plain-permalink root ignores the path and
		// renders its front end.
		$redirected = array(
			'/group/',
			'/group/wp-json/',
			'/group/wp-json/wporg-groups/v1/events',
			'/group/wp-json/wporg-groups/v1/events/',
			'/group/wp-json/wporg-groups/v1/events?after=12',
			'/group/wp-json/wporg-groups/v1/events?rest_route=/wp/v2/users',
			'/group/wp-json/wporg-groups/v1/events?feed=rss2',
			'/group/wp-json/wp/v2/users',
			'/group/wp-json/wporg-groups/v1/events/../../wp/v2/users',
			'/group/wp-json/wporg-groups/v1/event',
			'/group/wp-json/wporg-groups/v1/events-x',
			'/group/?rest_route=/wp/v2/users',
			'/group/?rest_route=/wporg-groups/v1/events/../../wp/v2/users',
			'/group/?rest_route[]=/wporg-groups/v1/events',
			'/group/some-page/?rest_route=/wporg-groups/v1/events',
			'/elsewhere/wp-json/wporg-groups/v1/events',
		);

		foreach ( $reachable as $uri ) {
			$this->assertTrue( $this->is_reachable( $uri ), $uri );
		}

		foreach ( $redirected as $uri ) {
			$this->assertFalse( $this->is_reachable( $uri ), $uri );
		}
	}

	/**
	 * Run the sunrise check against a request URI.
	 *
	 * @param string $uri Request URI.
	 * @return bool
	 */
	protected function is_reachable( string $uri ): bool {
		$_SERVER['REQUEST_URI'] = $uri;
		$_GET                   = array();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Mirrors the code under test.
		parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $_GET );

		return \WordCamp\Sunrise\Groups\is_reachable_root_endpoint();
	}
}
