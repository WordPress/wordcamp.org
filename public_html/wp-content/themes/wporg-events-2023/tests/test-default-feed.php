<?php

namespace WordPressdotorg\Theme\Events_2023\Tests;

use Exception;
use WordCamp\Tests\Database_TestCase;
use function WordPressdotorg\Events_2023\{ get_default_feed_redirect, redirect_default_feed };

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/inc/feeds.php';

/**
 * Tests for sending the site's default feed to the Upcoming Events feed.
 *
 * `Database_TestCase` rather than `WP_UnitTestCase`: `go_to()` fires `wp`, where the Latest Site Hints
 * mu-plugin looks the site up on central, and only the former provisions that site.
 *
 * @group events-2023
 */
class Test_Default_Feed extends Database_TestCase {
	/**
	 * The redirect the request tried to send, as `status location`, or '' when there was none.
	 *
	 * @var string
	 */
	protected $redirect = '';

	/**
	 * Stop at the redirect instead of sending it, and report where it was going.
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'wp_redirect', array( $this, 'throw_redirect' ), 10, 2 );
	}

	/**
	 * Note the redirect's status and target, then throw, so a test can stop it before `exit`.
	 *
	 * @param string $location
	 * @param int    $status
	 *
	 * @throws Exception Always.
	 */
	public function throw_redirect( $location, $status ) {
		$this->redirect = $status . ' ' . $location;

		throw new Exception( 'Redirected.' );
	}

	/**
	 * Load the URL, then run the redirect the way `template_redirect` would.
	 *
	 * @param string $url
	 *
	 * @return string The status and target of the redirect, or '' when there was none.
	 */
	protected function redirect_for( $url ) {
		$this->go_to( $url );
		$this->redirect = '';

		try {
			redirect_default_feed();
		} catch ( Exception $redirected ) {
			unset( $redirected );
		}

		return $this->redirect;
	}

	/**
	 * Hooked where a request's redirects are decided, before the feed is generated, and ahead of
	 * `redirect_canonical()`, so `/feed/rss2/` goes straight to the events feed instead of via `/feed/`.
	 *
	 * @covers \WordPressdotorg\Events_2023\redirect_default_feed
	 */
	public function test_redirect_runs_before_the_canonical_redirect() {
		$this->assertSame( 9, has_action( 'template_redirect', 'WordPressdotorg\Events_2023\redirect_default_feed' ) );
		$this->assertSame( 10, has_action( 'template_redirect', 'redirect_canonical' ) );
	}

	/**
	 * Every format of the default feed goes to the Upcoming Events feed, which has the real items.
	 *
	 * @dataProvider data_default_feeds
	 *
	 * @covers \WordPressdotorg\Events_2023\redirect_default_feed
	 *
	 * @param string $url
	 */
	public function test_default_feed_redirects_to_the_upcoming_events_feed( $url ) {
		$this->assertSame( '301 ' . home_url( '/upcoming-events/feed/' ), $this->redirect_for( $url ) );
	}

	/**
	 * The default feed in each format core serves.
	 *
	 * @return array
	 */
	public function data_default_feeds() {
		return array(
			'feed'  => array( '/?feed=feed' ),
			'rss2'  => array( '/?feed=rss2' ),
			'atom'  => array( '/?feed=atom' ),
			'rss'   => array( '/?feed=rss' ),
			'rdf'   => array( '/?feed=rdf' ),
		);
	}

	/**
	 * Feeds that aren't the default one stay where they are.
	 *
	 * @dataProvider data_other_requests
	 *
	 * @covers \WordPressdotorg\Events_2023\redirect_default_feed
	 *
	 * @param string $url
	 */
	public function test_other_requests_are_left_alone( $url ) {
		$this->assertSame( '', $this->redirect_for( $url ) );
	}

	/**
	 * Requests that must not be redirected.
	 *
	 * @return array
	 */
	public function data_other_requests() {
		return array(
			'comments feed'         => array( '/?feed=rss2&withcomments=1' ),
			'comments feed by name' => array( '/?feed=comments-rss2' ),
			'the home page'         => array( '/' ),
		);
	}

	/**
	 * Only a request for nothing but a feed is the default feed. A feed that names a post type, or a page
	 * (the Upcoming Events feed itself, so there's no loop), keeps its own.
	 *
	 * These go to the decision directly: loading them would query the events, which this suite can't reach.
	 *
	 * @dataProvider data_feeds_of_something
	 *
	 * @covers \WordPressdotorg\Events_2023\get_default_feed_redirect
	 *
	 * @param array $query_vars
	 */
	public function test_a_feed_of_something_is_left_alone( $query_vars ) {
		$this->assertSame( '', get_default_feed_redirect( $query_vars ) );
	}

	/**
	 * Query vars of feeds that belong to something.
	 *
	 * @return array
	 */
	public function data_feeds_of_something() {
		$post_type_feed = array(
			'feed'      => 'rss2',
			'post_type' => 'wporg_events',
		);

		$upcoming_events_feed = array(
			'pagename' => 'upcoming-events',
			'feed'     => 'feed',
		);

		$filtered_events_feed = array(
			'pagename'     => 'upcoming-events',
			'event_facets' => 'format/online/',
			'feed'         => 'feed',
		);

		$later_page = array(
			'feed'  => 'feed',
			'paged' => '2',
		);

		return array(
			'a post type feed'         => array( $post_type_feed ),
			'the upcoming events feed' => array( $upcoming_events_feed ),
			'a filtered events feed'   => array( $filtered_events_feed ),
			'a later page of the feed' => array( $later_page ),
		);
	}
}
