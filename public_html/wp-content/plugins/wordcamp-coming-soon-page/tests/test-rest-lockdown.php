<?php

namespace WordCamp\Coming_Soon_Page\Tests;

use WordCamp_Coming_Soon_Page;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

/**
 * @group coming-soon-page
 *
 * @covers WordCamp_Coming_Soon_Page::disable_rest_endpoints
 */
class Test_Rest_Lockdown extends WP_UnitTestCase {
	/**
	 * Boot a REST server so `rest_do_request()` runs the real dispatch.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		wp_set_current_user( 0 );
	}

	/**
	 * Reset the REST server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Build the lockdown callback with Coming Soon in the given state.
	 *
	 * @param string $enabled `on` or `off`.
	 * @return callable
	 */
	protected function lockdown_callback( $enabled ) {
		update_option( 'wccsp_settings', array( 'enabled' => $enabled ) );

		$plugin = new WordCamp_Coming_Soon_Page();
		$plugin->init();

		return array( $plugin, 'disable_rest_endpoints' );
	}

	/**
	 * Run one anonymous request with the lockdown in force.
	 *
	 * @param callable|null $interference Filter to register on `rest_request_after_callbacks`.
	 * @return \WP_REST_Response
	 */
	protected function locked_down_request( $interference = null ) {
		$lockdown = $this->lockdown_callback( 'on' );

		add_filter( 'rest_request_before_callbacks', $lockdown, 99, 3 );
		add_filter( 'rest_request_after_callbacks', $lockdown, 999, 3 );

		if ( $interference ) {
			add_filter( 'rest_request_after_callbacks', $interference, 10, 3 );
		}

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );

		if ( $interference ) {
			remove_filter( 'rest_request_after_callbacks', $interference, 10 );
		}

		remove_filter( 'rest_request_after_callbacks', $lockdown, 999 );
		remove_filter( 'rest_request_before_callbacks', $lockdown, 99 );

		return $response;
	}

	/**
	 * The lockdown is applied at both points, not just before the callbacks.
	 */
	public function test_lockdown_runs_before_and_after_callbacks() {
		$plugin   = new WordCamp_Coming_Soon_Page();
		$callback = array( $plugin, 'disable_rest_endpoints' );

		$before = has_filter( 'rest_request_before_callbacks', $callback );
		$after  = has_filter( 'rest_request_after_callbacks', $callback );

		if ( false !== $before ) {
			remove_filter( 'rest_request_before_callbacks', $callback, $before );
		}

		if ( false !== $after ) {
			remove_filter( 'rest_request_after_callbacks', $callback, $after );
		}

		$this->assertSame( 99, $before );
		$this->assertSame( 999, $after );
	}

	/**
	 * The lockdown refuses an ordinary route.
	 */
	public function test_locked_down_request_is_refused() {
		$response = $this->locked_down_request();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cannot_access', $response->get_data()['code'] );
	}

	/**
	 * `rest_request_before_callbacks` only sets a response, and a later filter on the
	 * same request can replace it. The lockdown has to survive that.
	 */
	public function test_lockdown_survives_a_filter_that_replaces_the_response() {
		$discard = function () {
			return rest_ensure_response( array( 'leaked' => true ) );
		};

		$response = $this->locked_down_request( $discard );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cannot_access', $response->get_data()['code'] );
	}

	/**
	 * With the site launched, the same request is answered normally.
	 */
	public function test_launched_site_is_not_refused() {
		$lockdown = $this->lockdown_callback( 'off' );

		add_filter( 'rest_request_before_callbacks', $lockdown, 99, 3 );
		add_filter( 'rest_request_after_callbacks', $lockdown, 999, 3 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		remove_filter( 'rest_request_after_callbacks', $lockdown, 999 );
		remove_filter( 'rest_request_before_callbacks', $lockdown, 99 );

		$this->assertSame( 200, $response->get_status() );
	}
}
