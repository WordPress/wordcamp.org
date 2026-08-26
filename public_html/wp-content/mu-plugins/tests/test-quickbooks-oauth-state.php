<?php

namespace WordCamp\Tests;

use ReflectionMethod;
use WP_UnitTestCase;
use WordCamp\QuickBooks\Client;

defined( 'WPINC' ) || die();

if ( ! defined( 'WordCamp\QuickBooks\PLUGIN_PREFIX' ) ) {
	define( 'WordCamp\QuickBooks\PLUGIN_PREFIX', 'wordcamp-qbo' );
}

require_once dirname( __DIR__ ) . '/quickbooks/includes/client.php';

/**
 * Tests for the `state` value that ties a QuickBooks OAuth callback to the authorization request that
 * started it.
 *
 * @group mu-plugins
 * @group quickbooks
 *
 * @package WordCamp\Tests
 */
class Test_QuickBooks_OAuth_State extends WP_UnitTestCase {
	/**
	 * A network admin, who is the only kind of user that can run the OAuth process.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Create the shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 *
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Start each test as the network admin who initiates the process.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Call one of the client's OAuth `state` helpers.
	 *
	 * They're protected because nothing outside the OAuth process should be creating or consuming a `state`.
	 *
	 * @param string $name
	 * @param array  $args
	 *
	 * @return mixed
	 */
	protected function call_client_method( $name, array $args = array() ) {
		// No `setAccessible()` call -- it's been a no-op since PHP 8.1, and deprecated since 8.5.
		$method = new ReflectionMethod( Client::class, $name );

		return $method->invokeArgs( null, $args );
	}

	/**
	 * @return string
	 */
	protected function create_state() {
		return $this->call_client_method( 'create_oauth_state' );
	}

	/**
	 * @param mixed $state
	 *
	 * @return bool
	 */
	protected function verify_state( $state ) {
		return $this->call_client_method( 'verify_oauth_state', array( $state ) );
	}

	/**
	 * @return string
	 */
	protected function state_key() {
		return $this->call_client_method( 'generate_oauth_state_key' );
	}

	/**
	 * The value sent to QBO is remembered for the user who started the process.
	 *
	 * @covers \WordCamp\QuickBooks\Client::create_oauth_state
	 */
	public function test_created_state_is_stored_for_the_current_user() {
		$state = $this->create_state();
		$meta  = get_user_meta( self::$admin_id, $this->state_key(), true );

		$this->assertSame( 32, strlen( $state ) );
		$this->assertSame( $state, $meta['state'] );
		$this->assertGreaterThan( time(), $meta['expires'] );
	}

	/**
	 * A callback carrying the value we sent is accepted.
	 *
	 * @covers \WordCamp\QuickBooks\Client::verify_oauth_state
	 */
	public function test_matching_state_is_verified() {
		$state = $this->create_state();

		$this->assertTrue( $this->verify_state( $state ) );
	}

	/**
	 * The value is good for a single callback, so a replay of the same one is turned away.
	 *
	 * @covers \WordCamp\QuickBooks\Client::verify_oauth_state
	 */
	public function test_state_can_only_be_used_once() {
		$state = $this->create_state();

		$this->verify_state( $state );

		$this->assertFalse( $this->verify_state( $state ) );
		$this->assertSame( '', get_user_meta( self::$admin_id, $this->state_key(), true ) );
	}

	/**
	 * A callback that doesn't carry the value we sent is turned away.
	 *
	 * @dataProvider data_unverified_states
	 * @covers \WordCamp\QuickBooks\Client::verify_oauth_state
	 *
	 * @param mixed $state
	 */
	public function test_other_states_are_not_verified( $state ) {
		$this->create_state();

		$this->assertFalse( $this->verify_state( $state ) );
	}

	/**
	 * Data provider for `test_other_states_are_not_verified`.
	 *
	 * @return array
	 */
	public function data_unverified_states() {
		return array(
			'empty'      => array( '' ),
			'mismatched' => array( 'AJd83ndKSl92mfkeAJd83ndKSl92mfke' ),
			'not a string' => array( array( 'nope' ) ),
		);
	}

	/**
	 * A callback that arrives long after the process started is turned away.
	 *
	 * @covers \WordCamp\QuickBooks\Client::verify_oauth_state
	 */
	public function test_expired_state_is_not_verified() {
		$state = $this->create_state();
		$key   = $this->state_key();

		update_user_meta(
			self::$admin_id,
			$key,
			array(
				'state'   => $state,
				'expires' => time() - 1,
			)
		);

		$this->assertFalse( $this->verify_state( $state ) );
	}

	/**
	 * The value only counts for the user it was created for.
	 *
	 * @covers \WordCamp\QuickBooks\Client::verify_oauth_state
	 */
	public function test_state_is_not_verified_for_another_user() {
		$state = $this->create_state();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $this->verify_state( $state ) );

		// The original user's value is untouched, so their own callback can still complete.
		wp_set_current_user( self::$admin_id );

		$this->assertTrue( $this->verify_state( $state ) );
	}

	/**
	 * A callback from a logged-out request is turned away.
	 *
	 * @covers \WordCamp\QuickBooks\Client::verify_oauth_state
	 */
	public function test_state_is_not_verified_without_a_user() {
		$state = $this->create_state();

		wp_set_current_user( 0 );

		$this->assertFalse( $this->verify_state( $state ) );
	}
}
