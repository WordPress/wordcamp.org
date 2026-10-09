<?php

namespace WordPressdotorg\Events_2023\Tests;

use WordCamp\Tests\Database_TestCase;
use WP_Error;
use function WordPressdotorg\Events_2023\{ compute_stats, format_stat, get_stats, refresh_stats, schedule_stats_cron };
use function WordPressdotorg\Theme\Events_2023\Events_Stat\render as render_stat;
use const WordPressdotorg\Events_2023\{ STATS_CRON_HOOK, STATS_OPTION };

defined( 'WPINC' ) || die();

/**
 * Tests for the homepage stats: how they're computed, cached and rendered.
 *
 * @group events-theme
 */
class Test_Stats extends Database_TestCase {
	/**
	 * Start without stored stats or injected data.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( STATS_OPTION );
		remove_all_filters( 'wporg_events_stats_groups' );
		remove_all_filters( 'wporg_events_stats_wordcamp_count' );
	}

	/**
	 * Three chapter groups the way `Meetup_Client::get_groups()` returns them.
	 *
	 * @return array[]
	 */
	protected function groups(): array {
		return array(
			array(
				'member_count' => 100, 'country' => 'DE', 'past_event_count' => 10,
			),
			array(
				'member_count' => 50, 'country' => 'de', 'past_event_count' => 5,
			),
			array(
				'member_count' => 1, 'country' => 'US', 'past_event_count' => 0,
			),
		);
	}

	/**
	 * Inject the data the refresh would fetch.
	 *
	 * @param array|WP_Error $groups
	 * @param int            $wordcamps
	 */
	protected function inject( $groups, int $wordcamps ): void {
		add_filter( 'wporg_events_stats_groups', fn() => $groups );
		add_filter( 'wporg_events_stats_wordcamp_count', fn() => $wordcamps );
	}

	/**
	 * Events are the groups' past events plus the WordCamps; countries are counted once each, whatever the case.
	 *
	 * @covers \WordPressdotorg\Events_2023\compute_stats
	 */
	public function test_compute_stats_sums_groups_and_wordcamps() {
		$stats = compute_stats( $this->groups(), 3 );

		$this->assertSame( 18, $stats['events'] );
		$this->assertSame( 3, $stats['groups'] );
		$this->assertSame( 2, $stats['countries'] );
		$this->assertSame( 151, $stats['members'] );
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
		$this->assertSame( 151, $stats['members'] );
		$this->assertGreaterThan( 0, $stats['updated'] );
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
