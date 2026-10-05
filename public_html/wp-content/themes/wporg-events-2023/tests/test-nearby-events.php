<?php

namespace WordPressdotorg\Theme\Events_2023\Tests;

use WP_Block_Supports, WP_REST_Request, WP_UnitTestCase;
use function WordPressdotorg\Theme\Events_2023\WordPress_Event_List\get_nearby_events_markup;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/src/event-list/index.php';

/**
 * @group events-2023
 */
class Test_Nearby_Events extends WP_UnitTestCase {
	/**
	 * Requests sent to the Events API during the current test.
	 *
	 * @var array
	 */
	protected $api_requests = array();

	/**
	 * The response the mocked Events API sends back.
	 *
	 * @var array|\WP_Error
	 */
	protected $api_response;

	/**
	 * The original `REMOTE_ADDR`, restored after each test.
	 *
	 * @var string|null
	 */
	protected $original_remote_addr;

	/**
	 * Give the visitor a known IP, and mock the Events API.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']     = '198.51.100.7';

		$this->api_requests = array();
		$this->api_response = $this->make_api_response( array( $this->make_event( 'WordCamp Testing' ) ) );

		add_filter( 'pre_http_request', array( $this, 'mock_events_api' ), 10, 3 );
	}

	/**
	 * Restore the visitor's IP and stop mocking.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_events_api' ) );

		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}

		parent::tear_down();
	}

	/**
	 * Stand in for api.wordpress.org/events.
	 */
	public function mock_events_api( $preempt, $args, $url ) {
		if ( ! str_starts_with( $url, 'https://api.wordpress.org/events/1.0/' ) ) {
			return $preempt;
		}

		$this->api_requests[] = $args['body'];

		return $this->api_response;
	}

	/**
	 * Build an event in the Events API's format.
	 *
	 * @param string $title
	 * @param int    $start_offset Seconds from now until the event starts.
	 *
	 * @return array
	 */
	protected function make_event( $title, $start_offset = DAY_IN_SECONDS ) {
		return array(
			'type'                 => 'wordcamp',
			'title'                => $title,
			'url'                  => 'https://testing.wordcamp.org/2026/',
			'meetup'               => '',
			'meetup_url'           => '',
			'date'                 => '2026-12-01 09:00:00',
			'end_date'             => '2026-12-02 17:00:00',
			'start_unix_timestamp' => time() + $start_offset,
			'end_unix_timestamp'   => time() + $start_offset + HOUR_IN_SECONDS,
			'location'             => array(
				'location'  => 'Skopje, North Macedonia',
				'country'   => 'MK',
				'latitude'  => 41.99,
				'longitude' => 21.43,
			),
		);
	}

	/**
	 * Build an HTTP response from the Events API.
	 *
	 * @param array $events
	 * @param int   $code
	 *
	 * @return array
	 */
	protected function make_api_response( array $events, $code = 200 ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array(
				'sandboxed' => false,
				'error'     => null,
				'location'  => array( 'ip' => '198.51.100.0' ),
				'events'    => $events,
			) ),
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Request the nearby endpoint.
	 *
	 * @param array $params
	 *
	 * @return \WP_REST_Response
	 */
	protected function get_nearby( $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/wporg-events/v1/nearby' );
		$request->set_query_params( $params );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The response only contains the fields the list displays, in the same shape as global events.
	 */
	public function test_returns_pruned_events() {
		$response = $this->get_nearby();

		$this->assertSame( 200, $response->get_status() );

		$events = $response->get_data()['events'];

		$this->assertCount( 1, $events );
		$this->assertSame( array( 'title', 'url', 'location', 'timestamp', 'type' ), array_keys( $events[0] ) );
		$this->assertSame( 'WordCamp Testing', $events[0]['title'] );
		$this->assertSame( 'Skopje, North Macedonia', $events[0]['location'] );
		$this->assertSame( 'wordcamp', $events[0]['type'] );
		$this->assertIsInt( $events[0]['timestamp'] );
	}

	/**
	 * The visitor's IP is anonymized before it's sent, and more events are requested than the widget asks for.
	 */
	public function test_request_uses_anonymized_ip() {
		$this->get_nearby( array( 'timezone' => 'Europe/Skopje' ) );

		$this->assertCount( 1, $this->api_requests );
		$this->assertSame( '198.51.100.0', $this->api_requests[0]['ip'] );
		$this->assertSame( 10, $this->api_requests[0]['number'] );
		$this->assertSame( 'Europe/Skopje', $this->api_requests[0]['timezone'] );
		$this->assertArrayNotHasKey( 'location', $this->api_requests[0] );
	}

	/**
	 * Core's widget cuts the list down to 3, which is too short for the front page.
	 */
	public function test_returns_more_than_three_events() {
		$events = array();

		for ( $i = 1; $i <= 12; $i++ ) {
			$events[] = $this->make_event( "Event $i", $i * DAY_IN_SECONDS );
		}

		$this->api_response = $this->make_api_response( $events );

		$this->assertCount( 10, $this->get_nearby()->get_data()['events'] );
	}

	/**
	 * Events that have already ended are dropped, and titles are decoded the way the widget decodes them.
	 */
	public function test_drops_past_events_and_decodes_titles() {
		$this->api_response = $this->make_api_response( array(
			$this->make_event( 'Already over', - DAY_IN_SECONDS ),
			$this->make_event( 'Coffee &amp; Code' ),
		) );

		$events = $this->get_nearby()->get_data()['events'];

		$this->assertCount( 1, $events );
		$this->assertSame( 'Coffee & Code', $events[0]['title'] );
	}

	/**
	 * Results are cached per network, separately from the widget's cache.
	 */
	public function test_second_request_is_cached() {
		$this->get_nearby();
		$response = $this->get_nearby();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->api_requests );
		$this->assertFalse( get_site_transient( 'community-events-' . md5( '198.51.100.0' ) ) );
		$this->assertNotFalse( get_site_transient( 'nearby-community-events-' . md5( '198.51.100.0' ) ) );
	}

	/**
	 * An error from the Events API isn't passed through.
	 */
	public function test_api_error_returns_502() {
		$this->api_response = $this->make_api_response( array(), 500 );

		$response = $this->get_nearby();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'nearby_events_unavailable', $response->get_data()['code'] );
	}

	/**
	 * Only real timezone identifiers are forwarded.
	 */
	public function test_rejects_invalid_timezone() {
		$response = $this->get_nearby( array( 'timezone' => 'Not/A_Zone' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 0, $this->api_requests );
	}

	/**
	 * The front page is page-cached, so the markup can't contain anything about the visitor.
	 */
	public function test_markup_is_the_same_for_every_visitor() {
		// Stand in for the block being rendered, so the wrapper attributes are output. Registering the real block
		// would need the theme's `build/` directory, which CI doesn't create.
		WP_Block_Supports::$block_to_render = array(
			'blockName' => 'wporg/event-list',
			'attrs'     => array( 'events' => 'nearby' ),
		);

		$first = get_nearby_events_markup();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$second                 = get_nearby_events_markup();

		WP_Block_Supports::$block_to_render = null;

		$this->assertSame( $first, $second );
		$this->assertStringNotContainsString( '198.51.100', $first );
		$this->assertStringContainsString( 'wporg-events/v1/nearby', $first );
		$this->assertCount( 0, $this->api_requests );
	}
}
