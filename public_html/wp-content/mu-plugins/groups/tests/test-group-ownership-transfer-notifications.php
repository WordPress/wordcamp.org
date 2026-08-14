<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Ownership_Transfer\accept_transfer;
use function WordCamp\Groups\Ownership_Transfer\cancel_transfer;
use function WordCamp\Groups\Ownership_Transfer\decline_transfer;
use function WordCamp\Groups\Ownership_Transfer\execute_transfer;
use function WordCamp\Groups\Ownership_Transfer\initiate_transfer;
use function WordCamp\Groups\Ownership_Transfer\reject_transfer;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 *
 * Covers `group-ownership-transfer-notifications.php`: that each transition
 * hook emails exactly the intended parties, no one else. The hooks
 * themselves are registered at file scope (loaded by `tests/bootstrap.php`),
 * so driving the real `Transfer\*` functions exercises the real wiring
 * rather than calling the `notify_*` callbacks directly.
 */
class Test_Group_Ownership_Transfer_Notifications extends Groups_TestCase {

	/**
	 * @var int
	 */
	private $group_site_id;

	/**
	 * Emails captured during the current test, via `pre_wp_mail`.
	 *
	 * @var array[]
	 */
	protected $sent_mail = array();

	/**
	 * Create a real group subsite and start intercepting outgoing mail.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->group_site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/ownership-transfer-notifications-test/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		switch_to_blog( $this->group_site_id );
		\GatherPress\Core\Setup::get_instance()->check_plugin_version();

		$this->sent_mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Remove the temporary group site, the mail interceptor, and any
	 * super-admin override set by an individual test.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		unset( $GLOBALS['super_admins'] );

		restore_current_blog();
		wp_delete_site( $this->group_site_id );

		parent::tearDown();
	}

	/**
	 * Record mail instead of sending it.
	 *
	 * @param null|bool $short_circuit Whether to short-circuit `wp_mail()`.
	 * @param array     $atts          `wp_mail()` arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ) {
		$this->sent_mail[] = $atts;

		return true;
	}

	/**
	 * Grant `manage_sites` to a user via the `$GLOBALS['super_admins']`
	 * override -- see `test-group-ownership-transfer.php::create_network_admin()`
	 * for why this, rather than `update_site_option()`, is the reliable way
	 * to do this in this test harness.
	 *
	 * @return int User ID.
	 */
	private function create_network_admin(): int {
		$user_id = self::factory()->user->create();

		$GLOBALS['super_admins'][] = get_userdata( $user_id )->user_login; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return $user_id;
	}

	/**
	 * The addresses actually mailed, lowercased for order-independent comparison.
	 *
	 * @return string[]
	 */
	private function mailed_addresses(): array {
		return array_map(
			static function ( array $atts ): string {
				// `send_notification()` passes a single-element array,
				// either "Name <email>" or a bare email.
				$to = is_array( $atts['to'] ) ? $atts['to'][0] : $atts['to'];
				preg_match( '/<([^>]+)>/', $to, $m );
				return strtolower( $m[1] ?? $to );
			},
			$this->sent_mail
		);
	}

	/**
	 * Initiating only emails the nominated candidate.
	 */
	public function test_initiate_notifies_candidate_only() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );

		$this->assertCount( 1, $this->sent_mail );
		$this->assertSame(
			array( strtolower( get_userdata( $candidate_id )->user_email ) ),
			$this->mailed_addresses()
		);
	}

	/**
	 * Accepting only emails network admins, not the owner or the candidate.
	 */
	public function test_accept_notifies_network_admins_only() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$GLOBALS['super_admins'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$admin_one_id            = $this->create_network_admin();
		$admin_two_id            = $this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		$this->sent_mail = array();

		accept_transfer( $this->group_site_id, $candidate_id );

		$this->assertCount( 2, $this->sent_mail );
		$this->assertEqualsCanonicalizing(
			array(
				strtolower( get_userdata( $admin_one_id )->user_email ),
				strtolower( get_userdata( $admin_two_id )->user_email ),
			),
			$this->mailed_addresses()
		);

		$mailed = $this->mailed_addresses();
		$this->assertNotContains( strtolower( get_userdata( $owner_id )->user_email ), $mailed );
		$this->assertNotContains( strtolower( get_userdata( $candidate_id )->user_email ), $mailed );
	}

	/**
	 * Declining only emails whoever initiated the transfer.
	 */
	public function test_decline_notifies_initiator_only() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		$this->sent_mail = array();

		decline_transfer( $this->group_site_id, $candidate_id );

		$this->assertCount( 1, $this->sent_mail );
		$this->assertSame(
			array( strtolower( get_userdata( $owner_id )->user_email ) ),
			$this->mailed_addresses()
		);
	}

	/**
	 * Cancelling only emails the nominated candidate.
	 */
	public function test_cancel_notifies_candidate_only() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		$this->sent_mail = array();

		// `cancel_transfer()` authorizes off the *current* user, not the
		// `$user_id` argument -- see `current_user_can_initiate()`.
		wp_set_current_user( $owner_id );
		cancel_transfer( $this->group_site_id, $owner_id );

		$this->assertCount( 1, $this->sent_mail );
		$this->assertSame(
			array( strtolower( get_userdata( $candidate_id )->user_email ) ),
			$this->mailed_addresses()
		);
	}

	/**
	 * Executing (approval) emails both the old and new owner, and no one else.
	 */
	public function test_execute_notifies_both_parties() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );
		$this->sent_mail = array();

		execute_transfer( $this->group_site_id, $admin_id );

		$this->assertCount( 2, $this->sent_mail );
		$this->assertEqualsCanonicalizing(
			array(
				strtolower( get_userdata( $owner_id )->user_email ),
				strtolower( get_userdata( $candidate_id )->user_email ),
			),
			$this->mailed_addresses()
		);
	}

	/**
	 * Rejecting emails both parties, and the reason is included in the body.
	 */
	public function test_reject_notifies_both_parties_with_reason() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );
		$this->sent_mail = array();

		reject_transfer( $this->group_site_id, $admin_id, 'Suspicious request' );

		$this->assertCount( 2, $this->sent_mail );
		$this->assertEqualsCanonicalizing(
			array(
				strtolower( get_userdata( $owner_id )->user_email ),
				strtolower( get_userdata( $candidate_id )->user_email ),
			),
			$this->mailed_addresses()
		);

		foreach ( $this->sent_mail as $atts ) {
			$this->assertStringContainsString( 'Suspicious request', $atts['message'] );
		}
	}

	/**
	 * A `wp_mail()` failure surfaces as a warning rather than failing silently
	 * -- matches `Notifications\schedule_new_event_notification()`'s own
	 * precedent (`test-notifications.php`).
	 */
	public function test_mail_failure_triggers_warning() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		add_filter( 'pre_wp_mail', '__return_false' );

		$captured = null;
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intentional, test-only: capturing the warning under test, not debug code.
			static function ( $errno, $errstr ) use ( &$captured ) {
				$captured = array( $errno, $errstr );
				return true;
			}
		);

		try {
			initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		} finally {
			restore_error_handler();
			remove_filter( 'pre_wp_mail', '__return_false' );
		}

		$this->assertNotNull( $captured, 'A failed notification send should have triggered a warning.' );
		$this->assertSame( E_USER_WARNING, $captured[0] );
	}
}
