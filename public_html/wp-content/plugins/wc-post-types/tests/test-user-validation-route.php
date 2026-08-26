<?php

namespace WordCamp\WC_Post_Types\Tests;
use WP_UnitTestCase;

defined( 'WPINC' ) || die();

// Helpers and stubs the REST code relies on, not otherwise loaded by this suite.
require_once dirname( __DIR__, 3 ) . '/mu-plugins/3-helpers-misc.php';
require_once __DIR__ . '/stubs.php';

/**
 * The username validation route applies the same entitlement rule as the save path, so a name the caller
 * couldn't link isn't reported as valid.
 *
 * @group wc-post-types
 * @group rest-api
 */
class Test_User_Validation_Route extends WP_UnitTestCase {
	protected static $contributor;
	protected static $editor;
	protected static $victim;

	/**
	 * Create the users the entitlement checks distinguish between, and register the route.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$contributor = $factory->user->create( array( 'role' => 'contributor' ) );
		self::$editor      = $factory->user->create( array( 'role' => 'editor' ) );
		self::$victim      = $factory->user->create( array( 'role' => 'subscriber' ) );

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
	 * A user's own account validates.
	 */
	public function test_own_account_is_valid(): void {
		wp_set_current_user( self::$contributor );

		$this->assertTrue( $this->validate( get_userdata( self::$contributor )->user_login ) );
	}

	/**
	 * Another account is invalid without `edit_others_posts`.
	 */
	public function test_another_account_invalid_for_contributor(): void {
		wp_set_current_user( self::$contributor );

		$this->assertFalse( $this->validate( get_userdata( self::$victim )->user_login ) );
	}

	/**
	 * Another account is valid for a user who can edit other authors' posts.
	 */
	public function test_another_account_valid_for_editor(): void {
		wp_set_current_user( self::$editor );

		$this->assertTrue( $this->validate( get_userdata( self::$victim )->user_login ) );
	}

	/**
	 * An unknown username is invalid.
	 */
	public function test_unknown_username_invalid(): void {
		wp_set_current_user( self::$editor );

		$this->assertFalse( $this->validate( 'no-such-user-xyz' ) );
	}
}
