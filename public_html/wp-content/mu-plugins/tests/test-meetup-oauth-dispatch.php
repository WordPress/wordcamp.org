<?php
/**
 * Tests for the Meetup OAuth callback dispatcher.
 *
 * @package WordCamp
 */

declare( strict_types = 1 );

/**
 * Verify that nonce states reach the admin callback.
 */
class Test_Meetup_OAuth_Dispatch extends WP_UnitTestCase {
	/**
	 * Register the callback for a nonce and reject an unauthorized exchange.
	 *
	 * @return void
	 */
	public function test_nonce_callback_is_dispatched(): void {
		global $wp_filter;

		$original_get = $_GET;
		$before       = $wp_filter['admin_init']->callbacks[10] ?? array();
		$added        = array();
		wp_set_current_user( self::factory()->user->create() );
		$_GET = array(
			'code'  => 'returned-code',
			'state' => wp_create_nonce( 'meetup-oauth' ),
		);

		try {
			require dirname( __DIR__ ) . '/wcorg-meetup-oauth.php';
			$after = $wp_filter['admin_init']->callbacks[10] ?? array();
			$added = array_diff_key( $after, $before );
			$this->assertCount( 1, $added );
			$callback = reset( $added )['function'];
			$this->assertNull( $callback() );
		} finally {
			$_GET = $original_get;
			foreach ( $added as $hook ) {
				remove_action( 'admin_init', $hook['function'] );
			}
		}
	}
	/**
	 * The recovery page mints its state only after an administrator opens it.
	 *
	 * @return void
	 */
	public function test_recovery_page_creates_browser_state(): void {
		global $wp_filter;

		$original_get  = $_GET;
		$before_init   = $wp_filter['admin_init']->callbacks[10] ?? array();
		$before_notice = $wp_filter['admin_notices']->callbacks[10] ?? array();
		$added_init    = array();
		$added_notice  = array();
		foreach ( array(
			'MEETUP_OAUTH_CONSUMER_KEY' => 'test-client',
			'MEETUP_OAUTH_CONSUMER_REDIRECT_URI' => admin_url( '/' ),
		) as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		wp_set_current_user( 0 );
		$cron_state = wp_create_nonce( 'meetup-oauth' );
		$_GET       = array( 'action' => 'wcorg-meetup-authorize' );
		try {
			require dirname( __DIR__ ) . '/wcorg-meetup-oauth.php';
			$added_init = array_diff_key( $wp_filter['admin_init']->callbacks[10] ?? array(), $before_init );
			$this->assertCount( 1, $added_init );
			$callback = reset( $added_init )['function'];
			$callback();
			$this->assertSame( $before_notice, $wp_filter['admin_notices']->callbacks[10] ?? array() );

			$user = wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$user->add_cap( 'manage_network_options' );
			if ( is_multisite() ) {
				grant_super_admin( $user->ID );
			}
			$callback();
			$added_notice = array_diff_key( $wp_filter['admin_notices']->callbacks[10] ?? array(), $before_notice );
			$this->assertCount( 1, $added_notice );
			ob_start();
			try {
				reset( $added_notice )['function']();
				$html = ob_get_contents();
			} finally {
				ob_end_clean();
			}
			preg_match( '/href="([^"]+)"/', $html, $matches );
			$url = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
			parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$this->assertSame( 'secure.meetup.com', wp_parse_url( $url, PHP_URL_HOST ) );
			$this->assertSame( MEETUP_OAUTH_CONSUMER_REDIRECT_URI, $query['redirect_uri'] );
			$this->assertSame( 'code', $query['response_type'] );
			$this->assertFalse( wp_verify_nonce( $cron_state, 'meetup-oauth' ) );
			$this->assertNotFalse( wp_verify_nonce( $query['state'], 'meetup-oauth' ) );
		} finally {
			$_GET = $original_get;
			foreach ( $added_init as $hook ) {
				remove_action( 'admin_init', $hook['function'] );
			}
			foreach ( $added_notice as $hook ) {
				remove_action( 'admin_notices', $hook['function'] );
			}
			if ( isset( $user ) && is_multisite() ) {
				revoke_super_admin( $user->ID );
			}
		}
	}
}
