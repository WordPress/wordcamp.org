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
	 * Clear out the callback parameters that the exchange tests set.
	 */
	public function tear_down() {
		$_GET = array();

		parent::tear_down();
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
	 * @return string
	 */
	protected function token_key() {
		return $this->call_client_method( 'generate_oauth_option_key' );
	}

	/**
	 * Whatever token data is stored for the network, or `not set` if there isn't any.
	 *
	 * @return array|string
	 */
	protected function stored_token_data() {
		return get_network_option( WORDCAMP_NETWORK_ID, $this->token_key(), 'not set' );
	}

	/**
	 * Give the client enough configuration to build an authorization URL.
	 *
	 * The values are fake, but nothing in these tests sends a request to Intuit, so they only need to be
	 * non-empty. `Production` avoids the SDK's log directory, which the `Development` value sets up.
	 *
	 * @return void
	 */
	protected function add_fake_credentials() {
		add_filter(
			'wordcamp_qbo_client_config',
			static function ( array $config ) {
				$config['ClientID']     = 'fake-client-id';
				$config['ClientSecret'] = 'fake-client-secret';
				$config['baseUrl']      = 'Production';

				return $config;
			}
		);
	}

	/**
	 * Play back a callback from Intuit.
	 *
	 * @param array  $query  The query parameters Intuit redirected with.
	 * @param Client $client Optional. The client to run it through. Default a new one.
	 *
	 * @return Client
	 */
	protected function handle_callback( array $query, $client = null ) {
		$_GET = $query;

		$client = $client ?? new Client();

		$client->maybe_exchange_code_for_token();

		return $client;
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
	 * A callback that can't be matched leaves a pending authorization alone, so the genuine one that follows
	 * can still complete.
	 *
	 * @covers \WordCamp\QuickBooks\Client::verify_oauth_state
	 */
	public function test_unmatched_callback_leaves_a_pending_state_alone() {
		$state = $this->create_state();

		$this->assertFalse( $this->verify_state( 'AJd83ndKSl92mfkeAJd83ndKSl92mfke' ) );
		$this->assertTrue( $this->verify_state( $state ) );
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
	 * A stored value that isn't a string is turned away rather than handed to `hash_equals()`, which would
	 * throw a TypeError.
	 *
	 * @covers \WordCamp\QuickBooks\Client::verify_oauth_state
	 */
	public function test_non_string_stored_state_is_not_verified() {
		update_user_meta(
			self::$admin_id,
			$this->state_key(),
			array(
				'state'   => array( 'nope' ),
				'expires' => time() + MINUTE_IN_SECONDS,
			)
		);

		$this->assertFalse( $this->verify_state( 'AJd83ndKSl92mfkeAJd83ndKSl92mfke' ) );
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

	/**
	 * The URL the user is sent to carries the value we stored for them, so the callback has something to match.
	 *
	 * @covers \WordCamp\QuickBooks\Client::get_authorize_url
	 */
	public function test_authorize_url_carries_the_stored_state() {
		$this->add_fake_credentials();

		$url = ( new Client() )->get_authorize_url();

		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$stored = get_user_meta( self::$admin_id, $this->state_key(), true );

		$this->assertNotEmpty( $query['state'] );
		$this->assertSame( $stored['state'], $query['state'] );
	}

	/**
	 * A callback that doesn't match the authorization this user started never reaches Intuit, and never
	 * results in a stored token.
	 *
	 * @covers \WordCamp\QuickBooks\Client::maybe_exchange_code_for_token
	 */
	public function test_exchange_is_refused_without_a_matching_state() {
		$state  = $this->create_state();
		$client = $this->handle_callback(
			array(
				'code'    => 'ANYTHING',
				'realmId' => '9999999999',
				'state'   => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
			)
		);

		$this->assertNotEmpty( $client->error->get_error_messages( 'invalid_state' ) );
		$this->assertSame( 'not set', $this->stored_token_data() );

		// The authorization that's actually in flight is untouched, so its callback can still complete.
		$this->assertTrue( $this->verify_state( $state ) );
	}

	/**
	 * A callback carrying an array instead of a code is turned away before anything is handed to the SDK.
	 *
	 * @covers \WordCamp\QuickBooks\Client::maybe_exchange_code_for_token
	 */
	public function test_exchange_is_refused_without_a_usable_code() {
		$state  = $this->create_state();
		$client = $this->handle_callback(
			array(
				'code'    => array( 'ANYTHING' ),
				'realmId' => '9999999999',
				'state'   => $state,
			)
		);

		$this->assertEmpty( $client->error->get_error_messages( 'invalid_state' ) );
		$this->assertSame( 'not set', $this->stored_token_data() );
		$this->assertTrue( $this->verify_state( $state ) );
	}

	/**
	 * A callback that gets past the check but fails further along can be retried, because the value that
	 * vouched for it is only discarded once a token comes back.
	 *
	 * The client here has no credentials, so the exchange fails without sending anything to Intuit.
	 *
	 * @covers \WordCamp\QuickBooks\Client::maybe_exchange_code_for_token
	 */
	public function test_exchange_keeps_the_state_when_the_token_request_fails() {
		$state  = $this->create_state();
		$client = $this->handle_callback(
			array(
				'code'    => 'ANYTHING',
				'realmId' => '9999999999',
				'state'   => $state,
			)
		);

		$this->assertEmpty( $client->error->get_error_messages( 'invalid_state' ) );
		$this->assertSame( 'not set', $this->stored_token_data() );
		$this->assertTrue( $this->verify_state( $state ) );
	}

	/**
	 * A callback that arrives once the connection is already made discards whatever this user had pending,
	 * rather than leaving it behind.
	 *
	 * @covers \WordCamp\QuickBooks\Client::maybe_exchange_code_for_token
	 */
	public function test_exchange_discards_the_state_when_a_token_already_exists() {
		$connected = new class() extends Client {
			/**
			 * Stand in for a connection that's already working.
			 *
			 * @return bool
			 */
			public function has_valid_token() {
				return true;
			}
		};

		$state = $this->create_state();

		$this->handle_callback(
			array(
				'code'    => 'ANYTHING',
				'realmId' => '9999999999',
				'state'   => $state,
			),
			$connected
		);

		$this->assertSame( '', get_user_meta( self::$admin_id, $this->state_key(), true ) );
	}
}
