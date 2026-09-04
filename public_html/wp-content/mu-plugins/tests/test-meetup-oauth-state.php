<?php

namespace WordCamp\Tests;

use WP_UnitTestCase;

use function WordCamp\Meetup_OAuth\{ create_oauth_state, delete_oauth_state, filter_authorize_url, get_page_url,
	maybe_exchange_code_for_token, verify_oauth_state };
use const WordCamp\Meetup_OAuth\STATE_META_KEY;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/wcorg-meetup-oauth.php';

/**
 * Tests for the `state` value that ties a Meetup OAuth callback to the authorization request that started it.
 *
 * @group mu-plugins
 * @group meetup
 *
 * @package WordCamp\Tests
 */
class Test_Meetup_OAuth_State extends WP_UnitTestCase {
	/**
	 * A network admin, who is the only kind of user that can run the OAuth process.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * A user with no network privileges.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Create the shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 *
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Start each test as the network admin who initiates the process.
	 */
	public function set_up() {
		parent::set_up();

		// Set directly rather than through `site_admins`, which the object cache holds onto between tests.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in `tear_down()`.
		$GLOBALS['super_admins'] = array( get_userdata( self::$admin_id )->user_login );

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Clear out the callback parameters and the super admin override that the tests set.
	 */
	public function tear_down() {
		$_GET = array();

		unset( $GLOBALS['super_admins'] );

		parent::tear_down();
	}

	/**
	 * Whatever `state` is stored for a user, or an empty string if there isn't any.
	 *
	 * @param int $user_id
	 *
	 * @return array|string
	 */
	protected function stored_state( $user_id ) {
		return get_user_meta( $user_id, STATE_META_KEY, true );
	}

	/**
	 * Play back a callback from Meetup.
	 *
	 * @param array $query The query parameters Meetup redirected with.
	 *
	 * @return void
	 */
	protected function handle_callback( array $query ) {
		$_GET = $query;

		maybe_exchange_code_for_token();
	}

	/**
	 * The value sent to Meetup is remembered for the user who started the process.
	 *
	 * @covers \WordCamp\Meetup_OAuth\create_oauth_state
	 */
	public function test_created_state_is_stored_for_the_current_user() {
		$state = create_oauth_state();
		$meta  = $this->stored_state( self::$admin_id );

		$this->assertSame( 32, strlen( $state ) );
		$this->assertSame( $state, $meta['state'] );
		$this->assertGreaterThan( time(), $meta['expires'] );
	}

	/**
	 * A callback carrying the value we sent is accepted.
	 *
	 * @covers \WordCamp\Meetup_OAuth\verify_oauth_state
	 */
	public function test_matching_state_is_verified() {
		$state = create_oauth_state();

		$this->assertTrue( verify_oauth_state( $state ) );
	}

	/**
	 * The literal the callback used to accept is no longer good for anything.
	 *
	 * @covers \WordCamp\Meetup_OAuth\verify_oauth_state
	 */
	public function test_former_hardcoded_state_is_not_verified() {
		create_oauth_state();

		$this->assertFalse( verify_oauth_state( 'meetup-oauth' ) );
	}

	/**
	 * A callback that can't be matched leaves a pending authorization alone, so the genuine one that follows
	 * can still complete.
	 *
	 * @covers \WordCamp\Meetup_OAuth\verify_oauth_state
	 */
	public function test_unmatched_callback_leaves_a_pending_state_alone() {
		$state = create_oauth_state();

		$this->assertFalse( verify_oauth_state( 'AJd83ndKSl92mfkeAJd83ndKSl92mfke' ) );
		$this->assertTrue( verify_oauth_state( $state ) );
	}

	/**
	 * A callback that doesn't carry the value we sent is turned away.
	 *
	 * @dataProvider data_unverified_states
	 * @covers \WordCamp\Meetup_OAuth\verify_oauth_state
	 *
	 * @param mixed $state
	 */
	public function test_other_states_are_not_verified( $state ) {
		create_oauth_state();

		$this->assertFalse( verify_oauth_state( $state ) );
	}

	/**
	 * Data provider for `test_other_states_are_not_verified`.
	 *
	 * @return array
	 */
	public function data_unverified_states() {
		return array(
			'empty'        => array( '' ),
			'mismatched'   => array( 'AJd83ndKSl92mfkeAJd83ndKSl92mfke' ),
			'not a string' => array( array( 'nope' ) ),
		);
	}

	/**
	 * A callback that arrives long after the process started is turned away.
	 *
	 * @covers \WordCamp\Meetup_OAuth\verify_oauth_state
	 */
	public function test_expired_state_is_not_verified() {
		$state = create_oauth_state();

		update_user_meta(
			self::$admin_id,
			STATE_META_KEY,
			array(
				'state'   => $state,
				'expires' => time() - 1,
			)
		);

		$this->assertFalse( verify_oauth_state( $state ) );
	}

	/**
	 * A stored value that isn't a string is turned away rather than handed to `hash_equals()`, which would
	 * throw a TypeError.
	 *
	 * @covers \WordCamp\Meetup_OAuth\verify_oauth_state
	 */
	public function test_non_string_stored_state_is_not_verified() {
		update_user_meta(
			self::$admin_id,
			STATE_META_KEY,
			array(
				'state'   => array( 'nope' ),
				'expires' => time() + MINUTE_IN_SECONDS,
			)
		);

		$this->assertFalse( verify_oauth_state( 'AJd83ndKSl92mfkeAJd83ndKSl92mfke' ) );
	}

	/**
	 * The value only counts for the user it was created for.
	 *
	 * @covers \WordCamp\Meetup_OAuth\verify_oauth_state
	 */
	public function test_state_is_not_verified_for_another_user() {
		$state = create_oauth_state();

		wp_set_current_user( self::$editor_id );

		$this->assertFalse( verify_oauth_state( $state ) );

		// The original user's value is untouched, so their own callback can still complete.
		wp_set_current_user( self::$admin_id );

		$this->assertTrue( verify_oauth_state( $state ) );
	}

	/**
	 * A callback from a logged-out request is turned away.
	 *
	 * @covers \WordCamp\Meetup_OAuth\verify_oauth_state
	 */
	public function test_state_is_not_verified_without_a_user() {
		$state = create_oauth_state();

		wp_set_current_user( 0 );

		$this->assertFalse( verify_oauth_state( $state ) );
	}

	/**
	 * Once spent, the value can't be replayed.
	 *
	 * @covers \WordCamp\Meetup_OAuth\delete_oauth_state
	 */
	public function test_deleted_state_is_not_verified() {
		$state = create_oauth_state();

		delete_oauth_state();

		$this->assertSame( '', $this->stored_state( self::$admin_id ) );
		$this->assertFalse( verify_oauth_state( $state ) );
	}

	/**
	 * A request that isn't a callback at all leaves any pending authorization alone.
	 *
	 * @dataProvider data_non_callback_requests
	 * @covers \WordCamp\Meetup_OAuth\maybe_exchange_code_for_token
	 *
	 * @param array $query
	 */
	public function test_non_callback_requests_are_ignored( array $query ) {
		$state = create_oauth_state();

		$this->handle_callback( $query );

		$this->assertTrue( verify_oauth_state( $state ) );
	}

	/**
	 * Data provider for `test_non_callback_requests_are_ignored`.
	 *
	 * @return array
	 */
	public function data_non_callback_requests() {
		return array(
			'nothing'      => array( array() ),
			'code only'    => array( array( 'code' => 'ANYTHING' ) ),
			'state only'   => array( array( 'state' => 'meetup-oauth' ) ),
			'array code'   => array(
				array(
					'code' => array( 'ANYTHING' ), 'state' => 'meetup-oauth',
				),
			),
		);
	}

	/**
	 * A callback carrying the value that used to be accepted no longer is.
	 *
	 * @covers \WordCamp\Meetup_OAuth\maybe_exchange_code_for_token
	 */
	public function test_exchange_is_refused_with_the_former_hardcoded_state() {
		$state = create_oauth_state();

		$this->handle_callback(
			array(
				'code'  => 'ANYTHING',
				'state' => 'meetup-oauth',
			)
		);

		// The authorization that's actually in flight is untouched, so its callback can still complete.
		$this->assertTrue( verify_oauth_state( $state ) );
	}

	/**
	 * A user without network privileges can't drive the process, even carrying their own value.
	 *
	 * @covers \WordCamp\Meetup_OAuth\maybe_exchange_code_for_token
	 */
	public function test_exchange_is_refused_without_the_capability() {
		wp_set_current_user( self::$editor_id );

		$state = create_oauth_state();

		$this->assertFalse( current_user_can( 'manage_network' ) );

		$this->handle_callback(
			array(
				'code'  => 'ANYTHING',
				'state' => $state,
			)
		);

		// Nothing was spent, because nothing was attempted.
		$this->assertTrue( verify_oauth_state( $state ) );
	}

	/**
	 * A callback that gets past the checks but can't reach Meetup can be retried, because the value that
	 * vouched for it is only discarded once a token comes back.
	 *
	 * There are no credentials in the test environment, so the exchange stops before the client loads.
	 *
	 * @covers \WordCamp\Meetup_OAuth\maybe_exchange_code_for_token
	 */
	public function test_exchange_keeps_the_state_when_the_token_request_fails() {
		$state = create_oauth_state();

		$this->assertFalse( defined( 'MEETUP_OAUTH_CONSUMER_KEY' ) );

		$this->handle_callback(
			array(
				'code'  => 'ANYTHING',
				'state' => $state,
			)
		);

		$this->assertTrue( verify_oauth_state( $state ) );
	}

	/**
	 * The client's reconnection notice points at the screen that mints a value, rather than at Meetup directly.
	 *
	 * @covers \WordCamp\Meetup_OAuth\filter_authorize_url
	 */
	public function test_authorize_url_points_at_the_settings_page() {
		$this->assertSame( get_page_url(), filter_authorize_url() );
		$this->assertStringContainsString( 'page=wcorg-meetup', filter_authorize_url() );
		$this->assertStringNotContainsString( 'secure.meetup.com', filter_authorize_url() );
	}
}
