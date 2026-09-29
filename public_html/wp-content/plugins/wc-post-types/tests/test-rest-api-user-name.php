<?php

namespace WordCamp\WC_Post_Types\Tests;
use WP_UnitTestCase, WP_REST_Request, WP_Error;
use function WordCamp\Post_Types\REST_API\guard_user_name_meta;
use function WordCamp\Post_Types\REST_API\guard_participant_link_meta;

defined( 'WPINC' ) || die();

// Helper the REST code relies on, not otherwise loaded by this suite.
require_once dirname( __DIR__, 3 ) . '/mu-plugins/3-helpers-misc.php';

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

	/**
	 * Create a speaker already linked to the given user, as an organizer would.
	 */
	private function linked_speaker( int $linked_user ): int {
		$post_id = self::factory()->post->create( array( 'post_type' => 'wcb_speaker' ) );

		wp_set_current_user( self::$editor );
		update_post_meta( $post_id, '_wcpt_user_name', get_userdata( $linked_user )->user_login );

		return $post_id;
	}

	/**
	 * Re-saving the account already linked is allowed, even for a user who couldn't set it.
	 */
	public function test_guard_allows_resaving_the_current_link(): void {
		$post_id = $this->linked_speaker( self::$victim );

		wp_set_current_user( self::$contributor );
		$request = $this->meta_request( $this->victim_login() );
		$request->set_param( 'id', $post_id );

		$this->assertNotInstanceOf( WP_Error::class, guard_user_name_meta( (object) array(), $request ) );
	}

	/**
	 * Changing an existing link to another account is still rejected.
	 */
	public function test_guard_rejects_changing_the_link(): void {
		$post_id = $this->linked_speaker( self::$editor );

		wp_set_current_user( self::$contributor );
		$request = $this->meta_request( $this->victim_login() );
		$request->set_param( 'id', $post_id );

		$this->assertInstanceOf( WP_Error::class, guard_user_name_meta( (object) array(), $request ) );
	}

	/**
	 * The guard is wired to each participant post type, so a renamed filter would be caught.
	 */
	public function test_guard_is_hooked_for_each_post_type(): void {
		foreach ( array( 'speaker', 'organizer', 'volunteer' ) as $type ) {
			$this->assertNotFalse(
				has_filter( "rest_pre_insert_wcb_{$type}", 'WordCamp\Post_Types\REST_API\guard_user_name_meta' ),
				"Guard not hooked for wcb_{$type}."
			);
		}
	}

	/**
	 * The metadata guard blocks linking another account through a raw meta write (e.g. XML-RPC).
	 */
	public function test_meta_guard_blocks_linking_another_account(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'wcb_speaker' ) );
		wp_set_current_user( self::$contributor );

		$this->assertFalse( guard_participant_link_meta( null, $post_id, '_wcpt_user_name', $this->victim_login() ) );
	}

	/**
	 * The metadata guard blocks setting the derived user ID directly to another account.
	 */
	public function test_meta_guard_blocks_setting_derived_id_directly(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'wcb_speaker' ) );
		wp_set_current_user( self::$contributor );

		$this->assertFalse( guard_participant_link_meta( null, $post_id, '_wcpt_user_id', self::$victim ) );
	}

	/**
	 * The metadata guard allows linking the user's own account.
	 */
	public function test_meta_guard_allows_own_account(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'wcb_speaker' ) );
		wp_set_current_user( self::$contributor );
		$login = get_userdata( self::$contributor )->user_login;

		$this->assertNull( guard_participant_link_meta( null, $post_id, '_wcpt_user_name', $login ) );
	}

	/**
	 * The metadata guard allows a user with `edit_others_posts` to link another account.
	 */
	public function test_meta_guard_allows_editor_to_link_another(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'wcb_speaker' ) );
		wp_set_current_user( self::$editor );

		$this->assertNull( guard_participant_link_meta( null, $post_id, '_wcpt_user_name', $this->victim_login() ) );
	}

	/**
	 * The metadata guard allows re-writing the account already linked.
	 */
	public function test_meta_guard_allows_rewriting_current_link(): void {
		$post_id = $this->linked_speaker( self::$victim );
		wp_set_current_user( self::$contributor );

		$this->assertNull( guard_participant_link_meta( null, $post_id, '_wcpt_user_name', $this->victim_login() ) );
	}

	/**
	 * The metadata guard allows writes with no current user (WP-CLI, cron, imports).
	 */
	public function test_meta_guard_allows_writes_with_no_current_user(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'wcb_speaker' ) );
		wp_set_current_user( 0 );

		$this->assertNull( guard_participant_link_meta( null, $post_id, '_wcpt_user_name', $this->victim_login() ) );
	}

	/**
	 * The metadata guard ignores meta on unrelated post types.
	 */
	public function test_meta_guard_ignores_other_post_types(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		wp_set_current_user( self::$contributor );

		$this->assertNull( guard_participant_link_meta( null, $post_id, '_wcpt_user_name', $this->victim_login() ) );
	}
}
