<?php

namespace WordPressdotorg\Theme\Events_2023\Tests;

use WP_Post, WP_UnitTestCase;
use const WordPressdotorg\Events_2023\FILTERED_URL_PATTERN_FEED;
use function WordPressdotorg\Events_2023\{ feed_link_to_page, feed_title, get_feed_item_details };

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/inc/events-query.php';

/**
 * @group events-2023
 */
class Test_Events_Feed extends WP_UnitTestCase {
	/**
	 * The original `$wp->request`, restored after each test.
	 *
	 * @var string
	 */
	protected $original_request;

	/**
	 * Remember the request, so tests can change it.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_request = $GLOBALS['wp']->request;
	}

	/**
	 * Restore the request.
	 */
	public function tear_down() {
		$GLOBALS['wp']->request = $this->original_request;

		parent::tear_down();
	}

	/**
	 * Build an event the way `inject_events_into_query()` does.
	 */
	protected function make_event( string $location, int $timestamp, int $tz_offset ): WP_Post {
		$post = new WP_Post(
			(object) array(
				'ID'        => 987654321,
				'post_type' => 'wporg_event',
				'filter'    => 'raw',
			)
		);

		wp_cache_set(
			$post->ID,
			array(
				'location'  => array( $location ),
				'timestamp' => array( $timestamp ),
				'tz_offset' => array( $tz_offset ),
			),
			'post_meta'
		);

		return $post;
	}

	/**
	 * The location comes from the event source, so it must not reach feed readers as markup.
	 */
	public function test_item_details_escape_the_location() {
		$details = get_feed_item_details( $this->make_event( 'Brisbane <img src=x onerror=alert(1)> & Co', 0, 0 ) );

		$this->assertStringNotContainsString( '<img', $details );
		$this->assertStringContainsString( 'Brisbane &lt;img src=x onerror=alert(1)&gt; &amp; Co', $details );
	}

	/**
	 * The time is shown in the event's own timezone, and says which one that is.
	 *
	 * @dataProvider data_item_details_time
	 */
	public function test_item_details_time( int $tz_offset, string $expected ) {
		// 2026-10-11 08:00 UTC.
		$details = get_feed_item_details( $this->make_event( 'online', 1791705600, $tz_offset ) );

		$this->assertStringContainsString( $expected, $details );
	}

	/**
	 * Data provider for test_item_details_time().
	 */
	public function data_item_details_time() {
		return array(
			'ahead of UTC'       => array( 36000, 'October 11, 2026 6:00pm (UTC+10:00)' ),
			'behind UTC'         => array( -25200, 'October 11, 2026 1:00am (UTC-7:00)' ),
			'a half-hour offset' => array( 19800, 'October 11, 2026 1:30pm (UTC+5:30)' ),
			'UTC'                => array( 0, 'October 11, 2026 8:00am (UTC+0:00)' ),
		);
	}

	/**
	 * The channel links back to the filtered page the feed came from.
	 *
	 * @dataProvider data_feed_link_to_page
	 */
	public function test_feed_link_to_page( string $request, string $expected ) {
		$GLOBALS['wp']->request = $request;

		$this->assertSame( home_url( $expected ), feed_link_to_page( 'ignored', 'url' ) );
		$this->assertSame( 'Site name', feed_link_to_page( 'Site name', 'name' ) );
	}

	/**
	 * Data provider for test_feed_link_to_page().
	 */
	public function data_feed_link_to_page() {
		return array(
			'unfiltered' => array( 'upcoming-events/feed', '/upcoming-events/' ),
			'filtered'   => array( 'upcoming-events/filtered/country/AU/feed', '/upcoming-events/filtered/country/AU/' ),
		);
	}

	/**
	 * The feed is named after the page, not the post type.
	 */
	public function test_feed_title() {
		$parts = feed_title( array( 'title' => 'Posts' ) );

		$this->assertSame( 'Upcoming Events', $parts['title'] );
	}

	/**
	 * Only a feed suffix on a filtered URL is routed to the feed.
	 *
	 * @dataProvider data_feed_url_pattern
	 */
	public function test_feed_url_pattern( string $path, bool $expected ) {
		$this->assertSame( $expected, (bool) preg_match( '#^' . FILTERED_URL_PATTERN_FEED . '$#', $path ) );
	}

	/**
	 * Data provider for test_feed_url_pattern().
	 */
	public function data_feed_url_pattern() {
		return array(
			'filtered feed'           => array( 'upcoming-events/filtered/country/AU/feed/', true ),
			'without a slash'         => array( 'upcoming-events/filtered/country/AU/feed', true ),
			'multiple facets'         => array( 'upcoming-events/filtered/type/meetup/country/AU/feed/', true ),
			'the filtered page'       => array( 'upcoming-events/filtered/country/AU/', false ),
		);
	}
}
