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
}
