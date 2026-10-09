<?php

namespace WordPressdotorg\Events_2023\Tests;

use WordCamp\Tests\Database_TestCase;

defined( 'WPINC' ) || die();

/**
 * Tests for the site's default feed serving events.
 *
 * `Database_TestCase` rather than `WP_UnitTestCase`: `go_to()` fires `wp`, where the Latest Site Hints
 * mu-plugin looks the site up on central, and only the former provisions that site.
 *
 * @group events-theme
 */
class Test_Feeds extends Database_TestCase {
	/**
	 * `/feed/`, the URL every page advertises, serves the events rather than the empty `post` feed.
	 *
	 * @covers \WordPressdotorg\Events_2023\use_events_for_default_feed
	 */
	public function test_default_feed_serves_events() {
		$this->go_to( '/?feed=rss2' );

		$this->assertTrue( is_feed() );
		$this->assertSame( 'wporg_events', get_query_var( 'post_type' ) );
	}

	/**
	 * A feed that already names a post type keeps it.
	 *
	 * @covers \WordPressdotorg\Events_2023\use_events_for_default_feed
	 */
	public function test_a_feed_for_another_post_type_is_left_alone() {
		$this->go_to( '/?feed=rss2&post_type=page' );

		$this->assertTrue( is_feed() );
		$this->assertSame( 'page', get_query_var( 'post_type' ) );
	}

	/**
	 * The comments feed is still the comments feed.
	 *
	 * @covers \WordPressdotorg\Events_2023\use_events_for_default_feed
	 */
	public function test_the_comments_feed_is_left_alone() {
		$this->go_to( '/?feed=comments-rss2&withcomments=1' );

		$this->assertTrue( is_comment_feed() );
		$this->assertSame( '', get_query_var( 'post_type' ) );
	}

	/**
	 * A request that isn't a feed is untouched.
	 *
	 * @covers \WordPressdotorg\Events_2023\use_events_for_default_feed
	 */
	public function test_a_page_request_is_left_alone() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( '/?page_id=' . $page_id );

		$this->assertFalse( is_feed() );
		$this->assertSame( '', get_query_var( 'post_type' ) );
		$this->assertSame( $page_id, get_queried_object_id() );
	}
}
