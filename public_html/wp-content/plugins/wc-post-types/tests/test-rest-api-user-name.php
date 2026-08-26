<?php

namespace WordCamp\WC_Post_Types\Tests;
use WP_UnitTestCase, WP_REST_Request, WP_Error;
use function WordCamp\Post_Types\REST_API\guard_user_name_meta;

defined( 'WPINC' ) || die();

// Helpers and stubs the REST code relies on, not otherwise loaded by this suite.
require_once dirname( __DIR__, 3 ) . '/mu-plugins/3-helpers-misc.php';
require_once __DIR__ . '/stubs.php';

/**
 * Entitlement rule for the `_wcpt_user_name` participant link.
 *
 * `wcorg_get_linkable_user_login()` is the shared rule; `guard_user_name_meta()` is the REST boundary that
 * turns a disallowed link into an error. Both are exercised directly so the checks don't depend on the full
 * REST dispatch (and the cross-plugin capability filters it pulls in).
 *
 * @group wc-post-types
 * @group rest-api
 */
class Test_User_Name_Meta extends WP_UnitTestCase {
	protected static $contributor;
	protected static $editor;
	protected static $victim;

	/**
	 * Create the users the entitlement checks distinguish between.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$contributor = $factory->user->create( array( 'role' => 'contributor' ) );
		self::$editor      = $factory->user->create( array( 'role' => 'editor' ) );
		self::$victim      = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * The login of the account a user tries to link that isn't their own.
	 */
	private function victim_login(): string {
		return get_userdata( self::$victim )->user_login;
	}

	/**
	 * A user may link their own account.
	 */
	public function test_user_can_link_own_account(): void {
		$login = get_userdata( self::$contributor )->user_login;

		$this->assertSame( $login, wcorg_get_linkable_user_login( $login, self::$contributor ) );
	}

	/**
	 * Without `edit_others_posts`, another account resolves to empty.
	 */
	public function test_user_without_edit_others_cannot_link_another_account(): void {
		$this->assertSame( '', wcorg_get_linkable_user_login( $this->victim_login(), self::$contributor ) );
	}

	/**
	 * With `edit_others_posts`, another account is linkable.
	 */
	public function test_user_with_edit_others_can_link_another_account(): void {
		$this->assertSame( $this->victim_login(), wcorg_get_linkable_user_login( $this->victim_login(), self::$editor ) );
	}

	/**
	 * An unknown username is not linkable.
	 */
	public function test_unknown_username_is_not_linkable(): void {
		$this->assertSame( '', wcorg_get_linkable_user_login( 'no-such-user-xyz', self::$editor ) );
	}

	/**
	 * With no current user, nothing is linkable.
	 */
	public function test_no_current_user_cannot_link(): void {
		$this->assertSame( '', wcorg_get_linkable_user_login( $this->victim_login(), 0 ) );
	}

	/**
	 * Build a REST request carrying a `_wcpt_user_name` meta value.
	 */
	private function meta_request( string $username ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wp/v2/speakers/1' );
		$request->set_body_params( array( 'meta' => array( '_wcpt_user_name' => $username ) ) );

		return $request;
	}

	/**
	 * The guard rejects linking an account the user is not entitled to.
	 */
	public function test_guard_rejects_unentitled_link(): void {
		wp_set_current_user( self::$contributor );

		$result = guard_user_name_meta( (object) array(), $this->meta_request( $this->victim_login() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcpt_cannot_link_user', $result->get_error_code() );
	}

	/**
	 * The guard allows linking the user's own account.
	 */
	public function test_guard_allows_own_link(): void {
		wp_set_current_user( self::$contributor );
		$login = get_userdata( self::$contributor )->user_login;

		$this->assertNotInstanceOf( WP_Error::class, guard_user_name_meta( (object) array(), $this->meta_request( $login ) ) );
	}

	/**
	 * The guard allows a user with `edit_others_posts` to link another account.
	 */
	public function test_guard_allows_editor_to_link_another(): void {
		wp_set_current_user( self::$editor );

		$this->assertNotInstanceOf( WP_Error::class, guard_user_name_meta( (object) array(), $this->meta_request( $this->victim_login() ) ) );
	}

	/**
	 * The guard leaves an unknown username for the sanitizer to blank.
	 */
	public function test_guard_ignores_unknown_username(): void {
		wp_set_current_user( self::$contributor );

		$this->assertNotInstanceOf( WP_Error::class, guard_user_name_meta( (object) array(), $this->meta_request( 'no-such-user-xyz' ) ) );
	}

	/**
	 * The guard ignores a request that doesn't touch the meta.
	 */
	public function test_guard_ignores_request_without_meta(): void {
		wp_set_current_user( self::$contributor );
		$request = new WP_REST_Request( 'POST', '/wp/v2/speakers/1' );
		$request->set_body_params( array( 'title' => 'No meta here' ) );

		$this->assertNotInstanceOf( WP_Error::class, guard_user_name_meta( (object) array(), $request ) );
	}
}
