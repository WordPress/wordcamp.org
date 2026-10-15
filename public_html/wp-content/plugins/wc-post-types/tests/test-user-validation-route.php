<?php

namespace WordCamp\WC_Post_Types\Tests;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

// Helper the route relies on, not otherwise loaded by this suite.
require_once dirname( __DIR__, 3 ) . '/mu-plugins/3-helpers-misc.php';

/**
 * The username validation route reports whether a WordPress.org account exists.
 *
 * @group wc-post-types
 * @group rest-api
 */
class Test_User_Validation_Route extends WP_UnitTestCase {
	protected static $user;

	/**
	 * Create a user to validate against, and register the route.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user = $factory->user->create( array( 'role' => 'subscriber' ) );

		do_action( 'rest_api_init' );
	}

	/**
	 * Run the route's username validate_callback.
	 */
	private function validate( string $username ): bool {
		$routes   = rest_get_server()->get_routes();
		$args     = $routes['/wc-post-types/v1/validation'][0]['args'] ?? array();
		$callback = $args['username']['validate_callback'] ?? null;

		$this->assertNotNull( $callback, 'Could not find validate_callback for username parameter.' );

		return (bool) call_user_func( $callback, $username, null, 'username' );
	}

	/**
	 * An existing username validates.
	 */
	public function test_existing_username_is_valid(): void {
		$this->assertTrue( $this->validate( get_userdata( self::$user )->user_login ) );
	}

	/**
	 * An unknown username does not validate.
	 */
	public function test_unknown_username_is_invalid(): void {
		$this->assertFalse( $this->validate( 'no-such-user-xyz' ) );
	}
}
