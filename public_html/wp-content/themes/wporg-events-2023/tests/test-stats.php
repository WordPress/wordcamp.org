<?php

namespace WordPressdotorg\Theme\Events_2023\Tests;

use WordCamp\Tests\Database_TestCase;
use WP_Error;
use function WordPressdotorg\Events_2023\{ compute_stats, fetch_meetup_groups, fetch_member_count, format_stat, get_stats, query_member_count, refresh_stats, schedule_stats_cron, usable_meetup_client };
use function WordPressdotorg\Theme\Events_2023\Events_Stat\render as render_stat;
use const WordPressdotorg\Events_2023\{ STATS_CRON_HOOK, STATS_OPTION };

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/inc/stats.php';
require_once dirname( __DIR__ ) . '/src/events-stat/index.php';

/**
 * Tests for the homepage stats: how they're computed, cached and rendered.
 *
 * @group events-2023
 */
class Test_Stats extends Database_TestCase {
	/**
	 * Start without stored stats or injected data.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( STATS_OPTION );
		remove_all_filters( 'wporg_events_stats_groups' );
		remove_all_filters( 'wporg_events_stats_member_count' );
		remove_all_filters( 'wporg_events_stats_wordcamp_count' );
	}

	/**
	 * The unique member count the network reports for `groups()`.
	 *
	 * Less than the 151 the groups add up to, because some people are in more than one group.
	 */
	const UNIQUE_MEMBERS = 140;

	/**
	 * Three chapter groups the way `Meetup_Client::get_groups()` returns them.
	 *
	 * @return array[]
	 */
	protected function groups(): array {
		return array(
			array(
				'member_count' => 100, 'country' => 'DE', 'past_event_count' => 10,
				'groupAnalytics' => array(
					'totalMembers'    => 100,
					'totalPastEvents' => 10,
				),
			),
			array(
				'member_count' => 50, 'country' => 'de', 'past_event_count' => 5,
				'groupAnalytics' => array(
					'totalMembers'    => 50,
					'totalPastEvents' => 5,
				),
			),
			array(
				'member_count' => 1, 'country' => 'US', 'past_event_count' => 0,
				'groupAnalytics' => array(
					'totalMembers'    => 1,
					'totalPastEvents' => 0,
				),
			),
		);
	}

	/**
	 * The same groups as Meetup returns them to a request without a token: listed, but with no analytics.
	 *
	 * The client then fills in `member_count` as 0 and leaves `past_event_count` out.
	 *
	 * @return array[]
	 */
	protected function groups_without_analytics(): array {
		return array_map(
			fn( $group ) => array(
				'member_count'   => 0,
				'country'        => $group['country'],
				'groupAnalytics' => null,
			),
			$this->groups()
		);
	}

	/**
	 * Inject the data the refresh would fetch.
	 *
	 * @param array|WP_Error $groups
	 * @param int            $wordcamps
	 * @param int|WP_Error   $members   The network's unique member count.
	 */
	protected function inject( $groups, int $wordcamps, $members = self::UNIQUE_MEMBERS ): void {
		add_filter( 'wporg_events_stats_groups', fn() => $groups );
		add_filter( 'wporg_events_stats_wordcamp_count', fn() => $wordcamps );
		add_filter( 'wporg_events_stats_member_count', fn() => $members );
	}

	/**
	 * A stand-in for the Meetup client that answers every query with `$result` and keeps the queries it got.
	 *
	 * @param array|WP_Error $result
	 *
	 * @return object
	 */
	protected function client_returning( $result ): object {
		return new class( $result ) {
			public array $queries = array();

			/**
			 * @param array|WP_Error $result What every query returns.
			 */
			public function __construct( private $result ) {}

			/**
			 * Keep the query and return the canned result.
			 *
			 * @param string     $query
			 * @param array|null $variables
			 *
			 * @return array|WP_Error
			 */
			public function send_paginated_request( $query, $variables = null ) {
				$this->queries[] = $query;

				return $this->result;
			}
		};
	}

	/**
	 * Events are the groups' past events plus the WordCamps; countries are counted once each, whatever the case.
	 *
	 * Members are the network's unique count as given, not the groups' counts added up, which would count
	 * someone in two groups twice.
	 *
	 * @covers \WordPressdotorg\Events_2023\compute_stats
	 */
	public function test_compute_stats_sums_groups_and_wordcamps() {
		$stats = compute_stats( $this->groups(), self::UNIQUE_MEMBERS, 3 );

		$this->assertSame( 18, $stats['events'] );
		$this->assertSame( 3, $stats['groups'] );
		$this->assertSame( 2, $stats['countries'] );
		$this->assertSame( self::UNIQUE_MEMBERS, $stats['members'] );
	}

	/**
	 * The unique member count comes from the network's analytics, not from the groups.
	 *
	 * @covers \WordPressdotorg\Events_2023\query_member_count
	 */
	public function test_query_member_count_reads_the_network_analytics() {
		$client = $this->client_returning( array(
			'proNetwork' => array(
				'networkAnalytics' => array( 'totalMembers' => 515286 ),
			),
		) );

		$this->assertSame( 515286, query_member_count( $client ) );
		$this->assertCount( 1, $client->queries );
		$this->assertMatchesRegularExpression( '/proNetwork\s*\(\s*urlname:\s*"wordpress"\s*\)/', $client->queries[0] );
		$this->assertMatchesRegularExpression( '/networkAnalytics\s*\{\s*totalMembers\s*\}/', $client->queries[0] );
	}

	/**
	 * An error, a missing field or a zero is an error, so a refresh never stores "0 meetup members".
	 *
	 * @covers \WordPressdotorg\Events_2023\query_member_count
	 */
	public function test_query_member_count_fails_without_a_usable_count() {
		$responses = array(
			new WP_Error( 'meetup', 'down' ),
			array(),
			array( 'proNetwork' => array( 'networkAnalytics' => null ) ),
			array( 'proNetwork' => array( 'networkAnalytics' => array( 'totalMembers' => 0 ) ) ),
		);

		foreach ( $responses as $response ) {
			$this->assertWPError( query_member_count( $this->client_returning( $response ) ) );
		}
	}

	/**
	 * A client that already failed, when Meetup refused to refresh an expired token in its constructor, is
	 * never used. Using it would send a request without a token, and the client's reset would then delete
	 * Central's token.
	 *
	 * The real client can't be created here (no Meetup credentials in the test environment), so this uses
	 * a stand-in with the same `error` property.
	 *
	 * @covers \WordPressdotorg\Events_2023\usable_meetup_client
	 */
	public function test_a_client_that_failed_to_refresh_its_token_is_not_used() {
		$client        = new \stdClass();
		$client->error = new WP_Error( 'invalid_grant', 'The refresh token was refused.' );

		$result = usable_meetup_client( $client );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_grant', $result->get_error_code() );
	}

	/**
	 * A client without errors is used as it is.
	 *
	 * @covers \WordPressdotorg\Events_2023\usable_meetup_client
	 */
	public function test_a_client_without_errors_is_used() {
		$client        = new \stdClass();
		$client->error = new WP_Error();

		$this->assertSame( $client, usable_meetup_client( $client ) );
	}

	/**
	 * Without a Meetup connection no client is created, so a stack without a token doesn't log warnings
	 * from a failed request every hour.
	 *
	 * The test environment has no Meetup credentials, which is the unconnected case.
	 *
	 * @covers \WordPressdotorg\Events_2023\fetch_meetup_groups
	 * @covers \WordPressdotorg\Events_2023\fetch_member_count
	 * @covers \WordPressdotorg\Events_2023\is_meetup_connected
	 */
	public function test_nothing_is_requested_without_a_meetup_connection() {
		$requests = 0;
		$count    = function ( $pre ) use ( &$requests ) {
			++$requests;

			return $pre;
		};

		add_filter( 'pre_http_request', $count );

		$groups  = fetch_meetup_groups();
		$members = fetch_member_count();
		refresh_stats();

		remove_filter( 'pre_http_request', $count );

		$this->assertSame( 'meetup_not_connected', $groups->get_error_code() );
		$this->assertSame( 'meetup_not_connected', $members->get_error_code() );
		$this->assertSame( 0, $requests );
		$this->assertFalse( get_option( STATS_OPTION ) );
	}

	/**
	 * Big numbers round down to a clean figure with a plus; the small ones are exact.
	 *
	 * @covers \WordPressdotorg\Events_2023\format_stat
	 */
	public function test_format_stat_rounds_large_numbers_down_with_a_plus() {
		$stats = array(
			'events' => 56789, 'groups' => 711, 'countries' => 100, 'members' => 512345,
		);

		$this->assertSame( '56,000+ WordPress events since 2006', format_stat( 'events', $stats ) );
		$this->assertSame( '711 local groups in 100 countries', format_stat( 'groups', $stats ) );
		$this->assertSame( '510,000+ meetup members', format_stat( 'members', $stats ) );
	}

	/**
	 * A figure below its rounding step is shown as it is.
	 *
	 * @covers \WordPressdotorg\Events_2023\format_stat
	 */
	public function test_format_stat_shows_a_small_figure_exactly() {
		$stats = array(
			'events' => 850, 'groups' => 2, 'countries' => 1, 'members' => 40,
		);

		$this->assertSame( '850 WordPress events since 2006', format_stat( 'events', $stats ) );
		$this->assertSame( '40 meetup members', format_stat( 'members', $stats ) );
		$this->assertSame( '', format_stat( 'nonsense', $stats ) );
	}

	/**
	 * Before the first refresh the page shows the copy it launched with, not blanks or zeros.
	 *
	 * @covers \WordPressdotorg\Events_2023\get_stats
	 */
	public function test_get_stats_falls_back_to_the_launch_copy_without_data() {
		$stats = get_stats();

		$this->assertTrue( $stats['fallback'] );
		$this->assertSame( '56,000+ WordPress events since 2006', format_stat( 'events', $stats ) );
		$this->assertSame( '711 local groups in 100 countries', format_stat( 'groups', $stats ) );
		$this->assertSame( '500,000+ meetup members', format_stat( 'members', $stats ) );
	}

	/**
	 * A refresh stores what it computed, and the page reads that from then on.
	 *
	 * @covers \WordPressdotorg\Events_2023\refresh_stats
	 */
	public function test_refresh_stores_the_computed_stats() {
		$this->inject( $this->groups(), 3 );

		refresh_stats();

		$stats = get_stats();

		$this->assertFalse( $stats['fallback'] );
		$this->assertSame( 18, $stats['events'] );
		$this->assertSame( self::UNIQUE_MEMBERS, $stats['members'] );
		$this->assertGreaterThan( 0, $stats['updated'] );
	}

	/**
	 * The cron run counts the real WordCamps on central: only scheduled and closed camps, and only `wordcamp` posts.
	 *
	 * Only the groups are injected here, so the count runs as it does in production.
	 *
	 * @covers \WordPressdotorg\Events_2023\count_wordcamps
	 * @covers \WordPressdotorg\Events_2023\refresh_stats
	 */
	public function test_refresh_counts_the_wordcamps_on_central() {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		foreach ( array( 'wcpt-scheduled', 'wcpt-scheduled', 'wcpt-closed', 'wcpt-needs-vetting', 'wcpt-cancelled' ) as $status ) {
			self::factory()->post->create( array(
				'post_type'   => 'wordcamp',
				'post_status' => $status,
			) );
		}

		self::factory()->post->create( array(
			'post_type'   => 'post',
			'post_status' => 'wcpt-scheduled',
		) );

		restore_current_blog();

		add_filter( 'wporg_events_stats_groups', fn() => $this->groups() );
		add_filter( 'wporg_events_stats_member_count', fn() => self::UNIQUE_MEMBERS );

		do_action( STATS_CRON_HOOK );

		$this->assertSame( 15 + 3, get_stats()['events'] );
	}

	/**
	 * When Meetup can't be reached, the last good figures stay.
	 *
	 * @covers \WordPressdotorg\Events_2023\refresh_stats
	 */
	public function test_refresh_keeps_the_last_stats_when_the_api_fails() {
		$this->inject( $this->groups(), 3 );
		refresh_stats();

		remove_all_filters( 'wporg_events_stats_groups' );
		add_filter( 'wporg_events_stats_groups', fn() => new WP_Error( 'meetup', 'down' ) );
		refresh_stats();

		$this->assertSame( 18, get_stats()['events'] );

		remove_all_filters( 'wporg_events_stats_groups' );
		add_filter( 'wporg_events_stats_groups', fn() => array() );
		refresh_stats();

		$this->assertSame( 18, get_stats()['events'] );
	}

	/**
	 * Groups without analytics mean the request had no token, so their zeros aren't stored over real figures.
	 *
	 * @covers \WordPressdotorg\Events_2023\refresh_stats
	 */
	public function test_refresh_keeps_the_last_stats_when_no_group_has_analytics() {
		$this->inject( $this->groups(), 3 );
		refresh_stats();

		remove_all_filters( 'wporg_events_stats_groups' );
		add_filter( 'wporg_events_stats_groups', fn() => $this->groups_without_analytics() );
		refresh_stats();

		$this->assertSame( 18, get_stats()['events'] );
		$this->assertSame( self::UNIQUE_MEMBERS, get_stats()['members'] );
	}

	/**
	 * When the unique member count can't be fetched, the last good figures stay, rather than a sum that
	 * counts people twice or a zero.
	 *
	 * @covers \WordPressdotorg\Events_2023\refresh_stats
	 */
	public function test_refresh_keeps_the_last_stats_when_the_member_count_fails() {
		$this->inject( $this->groups(), 3 );
		refresh_stats();

		remove_all_filters( 'wporg_events_stats_groups' );
		remove_all_filters( 'wporg_events_stats_member_count' );
		add_filter( 'wporg_events_stats_groups', fn() => array_slice( $this->groups(), 0, 1 ) );
		add_filter( 'wporg_events_stats_member_count', fn() => new WP_Error( 'meetup', 'down' ) );
		refresh_stats();

		$this->assertSame( 18, get_stats()['events'] );
		$this->assertSame( 3, get_stats()['groups'] );
		$this->assertSame( self::UNIQUE_MEMBERS, get_stats()['members'] );
	}

	/**
	 * Before any good refresh, groups without analytics leave the launch copy up instead of "0 meetup members".
	 *
	 * @covers \WordPressdotorg\Events_2023\refresh_stats
	 */
	public function test_refresh_stores_nothing_when_no_group_has_analytics() {
		$this->inject( $this->groups_without_analytics(), 3 );

		refresh_stats();

		$this->assertFalse( get_option( STATS_OPTION ) );
		$this->assertTrue( get_stats()['fallback'] );
	}

	/**
	 * The WordCamp statuses are only registered on the WordCamp network, so the count can't rely on them.
	 *
	 * On the Events network, where the cron runs, `WP_Query` drops the unregistered statuses and counts every
	 * `wordcamp` post instead.
	 *
	 * @covers \WordPressdotorg\Events_2023\count_wordcamps
	 */
	public function test_refresh_counts_the_wordcamps_when_their_statuses_are_not_registered() {
		global $wp_post_statuses;

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		foreach ( array( 'wcpt-scheduled', 'wcpt-closed', 'wcpt-needs-vetting', 'wcpt-cancelled', 'draft' ) as $status ) {
			self::factory()->post->create( array(
				'post_type'   => 'wordcamp',
				'post_status' => $status,
			) );
		}

		restore_current_blog();

		$registered = $wp_post_statuses;

		foreach ( array_keys( $wp_post_statuses ) as $status ) {
			if ( str_starts_with( $status, 'wcpt-' ) ) {
				unset( $wp_post_statuses[ $status ] );
			}
		}

		add_filter( 'wporg_events_stats_groups', fn() => $this->groups() );
		add_filter( 'wporg_events_stats_member_count', fn() => self::UNIQUE_MEMBERS );

		try {
			do_action( STATS_CRON_HOOK );
		} finally {
			$wp_post_statuses = $registered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		$this->assertSame( 15 + 2, get_stats()['events'] );
	}

	/**
	 * The refresh runs hourly from cron, so visitors never wait on the Meetup API.
	 *
	 * @covers \WordPressdotorg\Events_2023\schedule_stats_cron
	 */
	public function test_refresh_is_scheduled_hourly() {
		wp_clear_scheduled_hook( STATS_CRON_HOOK );

		$this->assertNotFalse( has_action( 'init', 'WordPressdotorg\Events_2023\schedule_stats_cron' ) );
		$this->assertNotFalse( has_action( STATS_CRON_HOOK, 'WordPressdotorg\Events_2023\refresh_stats' ) );

		schedule_stats_cron();

		$this->assertNotFalse( wp_next_scheduled( STATS_CRON_HOOK ) );
		$this->assertSame( 'hourly', wp_get_schedule( STATS_CRON_HOOK ) );

		// Running it again doesn't stack a second event.
		$first = wp_next_scheduled( STATS_CRON_HOOK );
		schedule_stats_cron();
		$this->assertSame( $first, wp_next_scheduled( STATS_CRON_HOOK ) );
	}

	/**
	 * The block renders one stat as a paragraph, and nothing for a stat it doesn't know.
	 *
	 * @covers \WordPressdotorg\Theme\Events_2023\Events_Stat\render
	 */
	public function test_block_renders_the_stat() {
		$this->inject( $this->groups(), 3 );
		refresh_stats();

		$html = render_stat( array( 'stat' => 'groups' ) );

		$this->assertStringContainsString( '<p ', $html );
		$this->assertStringContainsString( 'wp-block-wporg-events-stat', $html );
		$this->assertStringContainsString( '3 local groups in 2 countries', $html );
		$this->assertSame( '', render_stat( array( 'stat' => 'nonsense' ) ) );
		$this->assertSame( '', render_stat( array() ) );
	}
}
