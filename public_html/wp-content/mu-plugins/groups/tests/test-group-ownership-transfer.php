<?php

namespace WordCamp\Groups\Tests;

use WPDieException;
use function WordCamp\Groups\Ownership_Transfer\accept_transfer;
use function WordCamp\Groups\Ownership_Transfer\cancel_transfer;
use function WordCamp\Groups\Ownership_Transfer\current_user_can_approve;
use function WordCamp\Groups\Ownership_Transfer\current_user_can_initiate;
use function WordCamp\Groups\Ownership_Transfer\decline_transfer;
use function WordCamp\Groups\Ownership_Transfer\execute_transfer;
use function WordCamp\Groups\Ownership_Transfer\get_final_status_label;
use function WordCamp\Groups\Ownership_Transfer\get_group_sites_with_meta_key;
use function WordCamp\Groups\Ownership_Transfer\get_pending_transfer;
use function WordCamp\Groups\Ownership_Transfer\get_recent_decided_transfers;
use function WordCamp\Groups\Ownership_Transfer\get_sites_with_pending_transfers;
use function WordCamp\Groups\Ownership_Transfer\get_transfer_history;
use function WordCamp\Groups\Ownership_Transfer\handle_decision;
use function WordCamp\Groups\Ownership_Transfer\initiate_transfer;
use function WordCamp\Groups\Ownership_Transfer\reject_transfer;
use function WordCamp\Groups\Ownership_Transfer\render_page;
use function WordCamp\Groups\Ownership_Transfer\sanitize_reason;
use function WordCamp\Groups\Ownership_Transfer\validate_candidate;
use const WordCamp\Groups\Ownership_Transfer\DECIDE_ACTION;
use const WordCamp\Groups\Ownership_Transfer\HISTORY_LIMIT;
use const WordCamp\Groups\Ownership_Transfer\LOCK_GROUP;
use const WordCamp\Groups\Ownership_Transfer\LOCK_TIMEOUT;
use const WordCamp\Groups\Ownership_Transfer\META_KEY_PENDING;
use const WordCamp\Groups\Ownership_Transfer\REASON_MAX_LENGTH;
use const WordCamp\Groups\Ownership_Transfer\STATUS_PENDING_ACCEPTANCE;
use const WordCamp\Groups\Ownership_Transfer\STATUS_PENDING_APPROVAL;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Group_Ownership_Transfer extends Groups_TestCase {

	/**
	 * @var int
	 */
	private $group_site_id;

	/**
	 * `[errno, errstr]` pairs collected by `capture_warnings()`.
	 *
	 * @var array[]
	 */
	private $captured_warnings = array();

	/**
	 * Create a real group subsite for each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->group_site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/ownership-transfer-test/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		switch_to_blog( $this->group_site_id );
		\GatherPress\Core\Setup::get_instance()->check_plugin_version();
	}

	/**
	 * Remove the temporary group site and restore the parent fixture.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['super_admins'] );

		// `handle_decision()` reads these directly; leaving them set would
		// leak a nonce and a decision into whatever test runs next.
		$_POST    = array();
		$_REQUEST = array();

		restore_current_blog();
		wp_delete_site( $this->group_site_id );

		parent::tearDown();
	}

	/**
	 * Grant `manage_sites` to a user.
	 *
	 * `grant_super_admin()` reads `site_admins` out of `sitemeta`, which
	 * `Database_TestCase` truncates; setting the global that
	 * `get_super_admins()` checks first is the fixture-safe equivalent — see
	 * `test-sponsors.php::act_as_network_admin()`'s identical use of this.
	 * `tearDown()` clears it.
	 *
	 * @return int User ID.
	 */
	private function create_network_admin(): int {
		$user_id = self::factory()->user->create();

		$GLOBALS['super_admins'] = array( get_userdata( $user_id )->user_login ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return $user_id;
	}

	/**
	 * Full happy-path: initiate -> accept -> approve/execute.
	 */
	public function test_full_happy_path_swaps_roles() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		wp_set_current_user( $owner_id );
		$this->assertTrue( initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id ) );

		$pending = get_pending_transfer( $this->group_site_id );
		$this->assertSame( STATUS_PENDING_ACCEPTANCE, $pending['status'] );

		$this->assertTrue( accept_transfer( $this->group_site_id, $candidate_id ) );
		$pending = get_pending_transfer( $this->group_site_id );
		$this->assertSame( STATUS_PENDING_APPROVAL, $pending['status'] );

		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can_approve() );
		$this->assertTrue( execute_transfer( $this->group_site_id, $admin_id ) );

		$this->assertNull( get_pending_transfer( $this->group_site_id ) );

		$this->assertContains( 'editor', get_userdata( $owner_id )->roles );
		$this->assertNotContains( 'administrator', get_userdata( $owner_id )->roles );
		$this->assertContains( 'administrator', get_userdata( $candidate_id )->roles );

		$history = get_transfer_history( $this->group_site_id );
		$this->assertCount( 1, $history );
		$this->assertSame( 'completed', $history[0]['final_status'] );
	}

	/**
	 * Only the site's own administrator, or a super admin, may initiate.
	 */
	public function test_only_owner_or_super_admin_can_initiate() {
		$owner_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id  = $this->create_network_admin();

		wp_set_current_user( $owner_id );
		$this->assertTrue( current_user_can_initiate( $this->group_site_id ) );

		wp_set_current_user( $editor_id );
		$this->assertFalse( current_user_can_initiate( $this->group_site_id ) );

		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can_initiate( $this->group_site_id ) );
	}

	/**
	 * A network admin can initiate on behalf of an unresponsive owner —
	 * the resolution to the issue's "abandoned group" open question. The
	 * candidate must still accept and a network admin must still approve.
	 */
	public function test_network_admin_can_initiate_on_owners_behalf() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		wp_set_current_user( $admin_id );
		$result = initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $admin_id );

		$this->assertTrue( $result );

		$pending = get_pending_transfer( $this->group_site_id );
		$this->assertSame( $admin_id, $pending['initiated_by'] );
		$this->assertSame( $owner_id, $pending['from_user_id'] );
	}

	/**
	 * Only `manage_sites` holders (network admins by default) can approve.
	 */
	public function test_only_manage_sites_can_approve() {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $owner_id );
		$this->assertFalse( current_user_can_approve() );

		$admin_id = $this->create_network_admin();
		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can_approve() );
	}

	/**
	 * Candidate validation rejects author/subscriber tiers, an already-admin
	 * user, and the current owner transferring to themselves.
	 */
	public function test_validate_candidate_rejects_ineligible_targets() {
		$owner_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$self_result = validate_candidate( $owner_id, $owner_id, $this->group_site_id );
		$this->assertWPError( $self_result );
		$this->assertSame( 'cannot_transfer_to_self', $self_result->get_error_code() );

		$admin_result = validate_candidate( $other_admin, $owner_id, $this->group_site_id );
		$this->assertWPError( $admin_result );
		$this->assertSame( 'candidate_already_administrator', $admin_result->get_error_code() );

		$author_result = validate_candidate( $author_id, $owner_id, $this->group_site_id );
		$this->assertWPError( $author_result );
		$this->assertSame( 'candidate_not_eligible', $author_result->get_error_code() );

		$subscriber_result = validate_candidate( $subscriber_id, $owner_id, $this->group_site_id );
		$this->assertWPError( $subscriber_result );
		$this->assertSame( 'candidate_not_eligible', $subscriber_result->get_error_code() );

		// The factory defaults new users to `subscriber` on the current
		// blog, so a genuinely non-member candidate has to be removed
		// explicitly rather than just created without a `role` argument.
		$non_member_id = self::factory()->user->create();
		remove_user_from_blog( $non_member_id, $this->group_site_id );
		$non_member_result = validate_candidate( $non_member_id, $owner_id, $this->group_site_id );
		$this->assertWPError( $non_member_result );
		$this->assertSame( 'candidate_not_found', $non_member_result->get_error_code() );
	}

	/**
	 * A second initiate request is refused while one is already pending.
	 */
	public function test_cannot_initiate_while_one_already_pending() {
		$owner_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$second_editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id ) );

		$result = initiate_transfer( $this->group_site_id, $owner_id, $second_editor, $owner_id );
		$this->assertWPError( $result );
		$this->assertSame( 'transfer_already_pending', $result->get_error_code() );
	}

	/**
	 * Every transition is serialized per site: while another request holds
	 * this group's lock, a concurrent transition is refused outright rather
	 * than racing the read-then-write against site meta.
	 */
	public function test_concurrent_transition_is_refused_while_locked() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$lock_key = 'transfer_' . $this->group_site_id;
		$this->assertTrue( wp_cache_add( $lock_key, 1, LOCK_GROUP, LOCK_TIMEOUT ) );

		try {
			$result = initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		} finally {
			wp_cache_delete( $lock_key, LOCK_GROUP );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'transfer_busy', $result->get_error_code() );
		$this->assertNull( get_pending_transfer( $this->group_site_id ), 'A refused initiate must not leave a partial record.' );
	}

	/**
	 * Only the nominated candidate can accept or decline.
	 */
	public function test_only_the_candidate_can_accept_or_decline() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->create_network_admin();
		$bystander_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );

		$accept_result = accept_transfer( $this->group_site_id, $bystander_id );
		$this->assertWPError( $accept_result );
		$this->assertSame( 'not_the_candidate', $accept_result->get_error_code() );

		$decline_result = decline_transfer( $this->group_site_id, $bystander_id );
		$this->assertWPError( $decline_result );
		$this->assertSame( 'not_the_candidate', $decline_result->get_error_code() );

		$this->assertTrue( accept_transfer( $this->group_site_id, $candidate_id ) );
	}

	/**
	 * A decline clears the pending record and records it in history.
	 */
	public function test_decline_finalizes_as_declined() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		$this->assertTrue( decline_transfer( $this->group_site_id, $candidate_id ) );

		$this->assertNull( get_pending_transfer( $this->group_site_id ) );
		$history = get_transfer_history( $this->group_site_id );
		$this->assertSame( 'declined', $history[0]['final_status'] );
	}

	/**
	 * Only the initiating audience (owner or super admin) can cancel; anyone
	 * else, including the nominated candidate, cannot.
	 */
	public function test_only_initiating_audience_can_cancel() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );

		wp_set_current_user( $candidate_id );
		$result = cancel_transfer( $this->group_site_id, $candidate_id );
		$this->assertWPError( $result );
		$this->assertSame( 'cannot_cancel_transfer', $result->get_error_code() );

		wp_set_current_user( $owner_id );
		$this->assertTrue( cancel_transfer( $this->group_site_id, $owner_id ) );
		$this->assertNull( get_pending_transfer( $this->group_site_id ) );
		$this->assertSame( 'cancelled', get_transfer_history( $this->group_site_id )[0]['final_status'] );
	}

	/**
	 * A network admin can reject at either pending stage, with a reason recorded.
	 */
	public function test_reject_finalizes_with_reason() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		wp_set_current_user( $admin_id );
		$this->assertTrue( reject_transfer( $this->group_site_id, $admin_id, 'Suspicious request' ) );

		$this->assertNull( get_pending_transfer( $this->group_site_id ) );
		$history = get_transfer_history( $this->group_site_id );
		$this->assertSame( 'rejected', $history[0]['final_status'] );
		$this->assertSame( 'Suspicious request', $history[0]['reason'] );

		// Roles are untouched by a rejection.
		$this->assertContains( 'administrator', get_userdata( $owner_id )->roles );
		$this->assertContains( 'editor', get_userdata( $candidate_id )->roles );
	}

	/**
	 * `execute_transfer()` refuses to run before the candidate has accepted.
	 */
	public function test_cannot_execute_before_acceptance() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );

		$result = execute_transfer( $this->group_site_id, self::factory()->user->create() );
		$this->assertWPError( $result );
		$this->assertSame( 'transfer_not_awaiting_approval', $result->get_error_code() );
	}

	/**
	 * `execute_transfer()` re-validates the candidate immediately before
	 * mutating roles -- if they were removed from the group between
	 * acceptance and approval, execution must refuse rather than silently
	 * re-adding them to the site as a full administrator.
	 */
	public function test_cannot_execute_if_candidate_no_longer_a_member() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		remove_user_from_blog( $candidate_id, $this->group_site_id );

		$result = execute_transfer( $this->group_site_id, self::factory()->user->create() );
		$this->assertWPError( $result );
		$this->assertSame( 'candidate_not_found', $result->get_error_code() );

		$this->assertFalse( is_user_member_of_blog( $candidate_id, $this->group_site_id ) );
	}

	/**
	 * Same, but for the old owner having already been demoted by someone
	 * else in the meantime.
	 */
	public function test_cannot_execute_if_owner_no_longer_administrator() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		$owner = get_userdata( $owner_id );
		$owner->set_role( 'editor' );

		$result = execute_transfer( $this->group_site_id, self::factory()->user->create() );
		$this->assertWPError( $result );
		$this->assertSame( 'transfer_owner_no_longer_administrator', $result->get_error_code() );

		// The candidate must not have been promoted despite the rejection.
		$this->assertNotContains( 'administrator', get_userdata( $candidate_id )->roles );
	}

	/**
	 * `execute_transfer()` promotes the new owner before demoting the old
	 * one, so the site is never briefly without an administrator.
	 */
	public function test_promotes_new_owner_before_demoting_old_owner() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		// Created before the recorder is attached — the factory's own
		// default-role assignment for this user would otherwise show up as
		// a spurious third `set_user_role` firing.
		$decided_by = self::factory()->user->create();

		$order    = array();
		$recorder = static function ( $user_id, $role ) use ( &$order ) {
			$order[] = array( $user_id, $role );
		};
		add_action( 'set_user_role', $recorder, 10, 2 );

		try {
			$result = execute_transfer( $this->group_site_id, $decided_by );
		} finally {
			remove_action( 'set_user_role', $recorder, 10 );
		}

		$this->assertTrue( $result );
		$this->assertCount( 2, $order );
		$this->assertSame( array( $candidate_id, 'administrator' ), $order[0] );
		$this->assertSame( array( $owner_id, 'editor' ), $order[1] );
	}

	/**
	 * If the post-swap state isn't exactly one administrator, `execute_transfer()`
	 * reports a `WP_Error` and a Slack-relayed warning instead of false success.
	 *
	 * Simulated by corrupting the old owner's stored roles, from a `set_user_role`
	 * hook, right after their own demote call finishes — standing in for a
	 * concurrent process racing the same swap.
	 */
	public function test_execute_transfer_reports_inconsistent_state() {
		global $wpdb;

		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		$meta_key = $wpdb->get_blog_prefix( $this->group_site_id ) . 'capabilities';

		$corrupt = static function ( $user_id, $role ) use ( $owner_id, $meta_key ) {
			if ( $owner_id === $user_id && 'editor' === $role ) {
				update_user_meta(
					$owner_id,
					$meta_key,
					array(
						'editor'        => true,
						'administrator' => true,
					)
				);
			}
		};
		add_action( 'set_user_role', $corrupt, 10, 2 );

		$captured = null;
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intentional, test-only: capturing the warning under test, not debug code.
			static function ( $errno, $errstr ) use ( &$captured ) {
				$captured = array( $errno, $errstr );
				return true;
			}
		);

		try {
			$result = execute_transfer( $this->group_site_id, self::factory()->user->create() );
		} finally {
			restore_error_handler();
			remove_action( 'set_user_role', $corrupt, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'transfer_execution_inconsistent', $result->get_error_code() );
		$this->assertNotNull( $captured, 'execute_transfer() should have triggered a warning.' );
		$this->assertSame( E_USER_WARNING, $captured[0] );

		// The pending record is left in place rather than silently finalized,
		// so a network admin can retry after fixing the roles by hand.
		$this->assertNotNull( get_pending_transfer( $this->group_site_id ) );
	}

	/**
	 * History is capped at `HISTORY_LIMIT`, newest first.
	 */
	public function test_history_is_capped_and_newest_first() {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		for ( $i = 0; $i < HISTORY_LIMIT + 5; $i++ ) {
			$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
			decline_transfer( $this->group_site_id, $candidate_id );
		}

		$history = get_transfer_history( $this->group_site_id );
		$this->assertCount( HISTORY_LIMIT, $history );

		// The most recently declined candidate is first.
		$this->assertSame( $candidate_id, $history[0]['to_user_id'] );
	}

	/**
	 * The Network Admin listing functions query for sites that actually
	 * carry transfer meta, rather than iterating every group on the
	 * network -- a site with no transfer activity must not show up in
	 * either listing.
	 */
	public function test_listing_functions_only_include_sites_with_transfer_activity() {
		$untouched_site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/ownership-transfer-untouched/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		try {
			$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

			initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );

			$pending_site_ids = $this->site_ids_from_rows( get_sites_with_pending_transfers() );
			$this->assertContains( $this->group_site_id, $pending_site_ids );
			$this->assertNotContains( $untouched_site_id, $pending_site_ids );

			decline_transfer( $this->group_site_id, $candidate_id );

			$recent_site_ids = $this->site_ids_from_rows( get_recent_decided_transfers() );
			$this->assertContains( $this->group_site_id, $recent_site_ids );
			$this->assertNotContains( $untouched_site_id, $recent_site_ids );

			// The now-declined transfer must no longer appear as pending.
			$this->assertNotContains( $this->group_site_id, $this->site_ids_from_rows( get_sites_with_pending_transfers() ) );
		} finally {
			wp_delete_site( $untouched_site_id );
		}
	}

	/**
	 * Every `final_status` `finalize_transfer()` can write has a translated
	 * label; anything unrecognised still shows the raw value rather than
	 * going blank.
	 */
	public function test_get_final_status_label_covers_every_final_status() {
		foreach ( array( 'declined', 'cancelled', 'completed', 'rejected' ) as $status ) {
			$label = get_final_status_label( $status );
			$this->assertNotSame( $status, $label );
			$this->assertNotSame( '', $label );
		}

		$this->assertSame( 'something_new', get_final_status_label( 'something_new' ) );
	}

	/**
	 * Declining is the candidate's half of the acceptance step. Once they've
	 * accepted, the decision belongs to a network admin -- a stale panel (or a
	 * second click) must not be able to pull an accepted transfer back out
	 * from under the admin about to approve it.
	 */
	public function test_decline_is_refused_once_the_transfer_has_been_accepted() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		$result = decline_transfer( $this->group_site_id, $candidate_id );

		$this->assertWPError( $result );
		$this->assertSame( 'transfer_not_awaiting_acceptance', $result->get_error_code() );

		// The accepted transfer is still there, waiting on its approval.
		$pending = get_pending_transfer( $this->group_site_id );
		$this->assertNotNull( $pending );
		$this->assertSame( STATUS_PENDING_APPROVAL, $pending['status'] );
		$this->assertSame( array(), get_transfer_history( $this->group_site_id ) );
	}

	/**
	 * If the promotion doesn't land, the demotion must not run either -- in a
	 * single-owner group, demoting on the assumption that `set_role()` worked
	 * is what leaves the site with zero administrators.
	 */
	public function test_failed_promotion_does_not_demote_the_old_owner() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		// Stands in for anything that swallows the promotion -- another
		// plugin's `set_user_role` handler, a failed write -- by putting the
		// candidate straight back on `editor` as the promotion completes.
		$undo = static function ( $user_id, $role ) use ( $candidate_id ) {
			if ( $candidate_id === $user_id && 'administrator' === $role ) {
				update_user_meta( $user_id, self::capabilities_meta_key(), array( 'editor' => true ) );
			}
		};
		add_action( 'set_user_role', $undo, 10, 2 );

		$demotions = array();
		$recorder  = static function ( $user_id, $role ) use ( &$demotions, $owner_id ) {
			if ( $owner_id === $user_id ) {
				$demotions[] = $role;
			}
		};
		add_action( 'set_user_role', $recorder, 10, 2 );

		$this->capture_warnings();

		try {
			$result = execute_transfer( $this->group_site_id, self::factory()->user->create() );
		} finally {
			restore_error_handler();
			remove_action( 'set_user_role', $undo, 10 );
			remove_action( 'set_user_role', $recorder, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'transfer_promotion_failed', $result->get_error_code() );
		$this->assertNotEmpty( $this->captured_warnings );

		$this->assertSame( array(), $demotions, 'The old owner must not be touched once the promotion is known to have failed.' );
		$this->assertContains( 'administrator', get_userdata( $owner_id )->roles );
		$this->assertNotNull( get_pending_transfer( $this->group_site_id ), 'The transfer stays pending so it can simply be retried.' );
	}

	/**
	 * A demotion that leaves the old owner with no roles at all is a failure,
	 * not a success: "not an administrator any more" is satisfied by a user
	 * locked out of the group entirely. Both parties are put back as they
	 * were, so the decision can be retried rather than needing hand-repair.
	 */
	public function test_demotion_that_lands_nowhere_is_rolled_back() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		// Wipe the old owner's capabilities as their demotion completes,
		// standing in for a concurrent write racing the same swap.
		$wipe = static function ( $user_id, $role ) use ( $owner_id ) {
			if ( $owner_id === $user_id && 'editor' === $role ) {
				update_user_meta( $user_id, self::capabilities_meta_key(), array() );
			}
		};
		add_action( 'set_user_role', $wipe, 10, 2 );

		$this->capture_warnings();

		try {
			$result = execute_transfer( $this->group_site_id, self::factory()->user->create() );
		} finally {
			restore_error_handler();
			remove_action( 'set_user_role', $wipe, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'transfer_execution_inconsistent', $result->get_error_code() );
		$this->assertNotEmpty( $this->captured_warnings );

		// Rolled back: the group has exactly the owner it started with, and
		// the candidate wasn't left holding `administrator`, which would have
		// made every retry fail on `candidate_already_administrator`.
		$this->assertContains( 'administrator', get_userdata( $owner_id )->roles );
		$this->assertNotContains( 'administrator', get_userdata( $candidate_id )->roles );
		$this->assertContains( 'editor', get_userdata( $candidate_id )->roles );
		$this->assertNotNull( get_pending_transfer( $this->group_site_id ) );
	}

	/**
	 * A group that has been archived or marked as spam is out of circulation;
	 * a transfer request made before that must not still be sitting on the
	 * approvals screen with a live Approve button next to it.
	 */
	public function test_archived_and_spammed_groups_are_excluded_from_listings() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		$this->assertContains( $this->group_site_id, $this->site_ids_from_rows( get_sites_with_pending_transfers() ) );

		foreach ( array( 'archived', 'spam' ) as $flag ) {
			update_blog_status( $this->group_site_id, $flag, '1' );

			$this->assertNotContains(
				$this->group_site_id,
				$this->site_ids_from_rows( get_sites_with_pending_transfers() ),
				"A {$flag} group must not appear on the approvals screen."
			);

			update_blog_status( $this->group_site_id, $flag, '0' );
		}

		// ...and it comes back once the group does.
		$this->assertContains( $this->group_site_id, $this->site_ids_from_rows( get_sites_with_pending_transfers() ) );
	}

	/**
	 * A failed lookup must not be indistinguishable from "nothing to approve"
	 * -- that is the one wrong answer this screen can give the people
	 * responsible for acting on these requests.
	 */
	public function test_a_failed_lookup_is_reported_rather_than_read_as_empty() {
		global $wpdb;

		$break = static function ( $query ) {
			return false !== strpos( $query, $GLOBALS['wpdb']->blogmeta ) ? 'SELECT * FROM a_table_that_is_not_there' : $query;
		};

		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );

		$this->capture_warnings();

		try {
			$sites   = get_group_sites_with_meta_key( META_KEY_PENDING );
			$pending = get_sites_with_pending_transfers();
			$recent  = get_recent_decided_transfers();
		} finally {
			restore_error_handler();
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppressed );
			$wpdb->last_error = '';
		}

		$this->assertWPError( $sites );
		$this->assertSame( 'transfer_query_failed', $sites->get_error_code() );
		$this->assertWPError( $pending );
		$this->assertWPError( $recent );
		$this->assertNotEmpty( $this->captured_warnings );
	}

	/**
	 * A decision whose site-meta write doesn't land is reported, not reported
	 * as done -- otherwise the transfer keeps showing up as awaiting a
	 * decision that everyone has been told was already made.
	 */
	public function test_a_decision_that_cannot_be_saved_is_reported() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );

		add_filter( 'update_blog_metadata', '__return_false' );

		$this->capture_warnings();

		try {
			$result = reject_transfer( $this->group_site_id, $admin_id, 'Nope' );
		} finally {
			restore_error_handler();
			remove_filter( 'update_blog_metadata', '__return_false' );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'transfer_not_recorded', $result->get_error_code() );
		$this->assertNotEmpty( $this->captured_warnings );

		// The pending record survives the failure, so the decision can be
		// made again rather than the request disappearing unrecorded.
		$this->assertNotNull( get_pending_transfer( $this->group_site_id ) );
	}

	/**
	 * The Network Admin form handler is the only way to approve or reject, so
	 * its own capability check and nonce are load-bearing rather than
	 * decoration on top of a check further in: `execute_transfer()` and
	 * `reject_transfer()` don't re-check who is asking.
	 */
	public function test_handle_decision_requires_manage_sites() {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $owner_id );
		$this->assertFalse( current_user_can_approve(), 'Precondition: a group owner is not a network admin.' );

		$this->post_decision( $this->group_site_id, 'approve', true );

		$this->expectException( WPDieException::class );

		handle_decision();
	}

	/**
	 * Same, for the nonce: a valid network admin session must not be enough
	 * on its own to approve a transfer from a cross-site request.
	 */
	public function test_handle_decision_requires_a_valid_nonce() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		wp_set_current_user( $admin_id );
		$this->post_decision( $this->group_site_id, 'approve', false );

		try {
			handle_decision();
			$this->fail( 'handle_decision() should have died on the missing nonce.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotNull( get_pending_transfer( $this->group_site_id ), 'No decision may be applied without a valid nonce.' );
		}

		$this->assertContains( 'administrator', get_userdata( $owner_id )->roles );
		$this->assertNotContains( 'administrator', get_userdata( $candidate_id )->roles );
	}

	/**
	 * Anything other than approve/reject is refused outright, rather than
	 * falling through to the reject branch of the ternary that picks between them.
	 */
	public function test_handle_decision_rejects_an_unknown_decision() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );

		wp_set_current_user( $admin_id );
		$this->post_decision( $this->group_site_id, 'delete', true );

		try {
			handle_decision();
			$this->fail( 'handle_decision() should have died on the unknown decision.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotNull( get_pending_transfer( $this->group_site_id ) );
			$this->assertSame( array(), get_transfer_history( $this->group_site_id ) );
		}
	}

	/**
	 * The reject form has to actually collect a reason. `reject_transfer()`
	 * stores one and the rejection email has a line for it, but neither can do
	 * anything if the only screen that rejects transfers never asks.
	 */
	public function test_reject_form_collects_a_reason() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		wp_set_current_user( $admin_id );

		ob_start();
		render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="reason"', $html );
		$this->assertStringContainsString( 'id="wporg-transfer-reason-' . $this->group_site_id . '"', $html );

		// Labelled, since it renders as a bare box in a table cell.
		$this->assertStringContainsString( 'for="wporg-transfer-reason-' . $this->group_site_id . '"', $html );

		// And it belongs to the reject form, not the approve one -- approving
		// has no reason to give.
		$reject_form = strstr( $html, 'value="reject"' );
		$this->assertStringContainsString( 'name="reason"', $reject_form );
		$this->assertStringNotContainsString( 'name="reason"', strstr( $html, 'value="reject"', true ) );
	}

	/**
	 * A reason typed into that field survives the round trip into the history
	 * entry the notification reads from.
	 */
	public function test_a_rejection_reason_is_recorded() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin_id     = $this->create_network_admin();

		initiate_transfer( $this->group_site_id, $owner_id, $candidate_id, $owner_id );
		accept_transfer( $this->group_site_id, $candidate_id );

		wp_set_current_user( $admin_id );
		$this->assertTrue( reject_transfer( $this->group_site_id, $admin_id, 'Candidate asked us to hold off.' ) );

		$this->assertSame( 'Candidate asked us to hold off.', get_transfer_history( $this->group_site_id )[0]['reason'] );
	}

	/**
	 * `maxlength` on the input is a courtesy to whoever is typing; the POST can
	 * carry any length regardless, so the cap is enforced server-side too.
	 */
	public function test_reason_is_capped_and_sanitized() {
		$this->assertSame( REASON_MAX_LENGTH, mb_strlen( sanitize_reason( str_repeat( 'a', REASON_MAX_LENGTH * 2 ) ) ) );
		$this->assertSame( 'ok', sanitize_reason( 'ok' ) );
		$this->assertSame( '', sanitize_reason( '' ) );

		// Counted in characters, not bytes -- a multibyte reason must not be
		// cut mid-character.
		$multibyte = str_repeat( 'é', REASON_MAX_LENGTH * 2 );
		$this->assertSame( REASON_MAX_LENGTH, mb_strlen( sanitize_reason( $multibyte ) ) );

		$this->assertStringNotContainsString( '<script>', sanitize_reason( 'nope <script>alert(1)</script>' ) );
	}

	/**
	 * Populate the `$_POST`/`$_REQUEST` a `handle_decision()` call reads.
	 * `tearDown()` clears them.
	 *
	 * @param int    $site_id  Group site being decided.
	 * @param string $decision 'approve', 'reject', or something invalid.
	 * @param bool   $nonce    Whether to include a valid nonce.
	 */
	private function post_decision( int $site_id, string $decision, bool $nonce ): void {
		$_POST['site_id']  = (string) $site_id;
		$_POST['decision'] = $decision;

		if ( $nonce ) {
			$_POST['_wpnonce'] = wp_create_nonce( DECIDE_ACTION . '_' . $site_id );
		}

		$_REQUEST = $_POST;
	}

	/**
	 * Swap in an error handler that collects `trigger_error()` calls into
	 * `$this->captured_warnings` instead of letting them surface. Callers
	 * MUST `restore_error_handler()`.
	 */
	private function capture_warnings(): void {
		$this->captured_warnings = array();

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intentional, test-only: capturing the warnings under test, not debug code.
			function ( $errno, $errstr ) {
				$this->captured_warnings[] = array( $errno, $errstr );
				return true;
			}
		);
	}

	/**
	 * The user-meta key holding a user's roles on the group site under test.
	 *
	 * @return string
	 */
	private static function capabilities_meta_key(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix( get_current_blog_id() ) . 'capabilities';
	}

	/**
	 * Pull `blog_id`s out of the `{site, pending|entry}` row shape both
	 * listing functions return.
	 *
	 * @param array $rows Rows from `get_sites_with_pending_transfers()` or `get_recent_decided_transfers()`.
	 * @return int[]
	 */
	private function site_ids_from_rows( array $rows ): array {
		return array_map(
			static function ( array $row ): int {
				return (int) $row['site']->blog_id;
			},
			$rows
		);
	}
}
