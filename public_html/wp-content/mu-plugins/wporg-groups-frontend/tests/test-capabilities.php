<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;
use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_group_settings;
use function WordCamp\Groups\Frontend\Capabilities\current_user_can_switch_own_role;
use function WordCamp\Groups\Frontend\Capabilities\get_current_group_slug;
use function WordCamp\Groups\Frontend\Capabilities\self_serve_roles_enabled;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/class-groups-testcase.php';

/**
 * @group groups
 */
class Test_Groups_Capabilities extends Groups_TestCase {

	/**
	 * Data provider for test_current_user_can_manage_events_by_role().
	 */
	public function data_manage_events_roles(): array {
		return array(
			'administrator manages events' => array( 'administrator', true ),
			'editor manages events'        => array( 'editor', true ),
			'author manages events'        => array( 'author', true ),
			'contributor cannot'           => array( 'contributor', false ),
			'subscriber cannot'            => array( 'subscriber', false ),
		);
	}

	/**
	 * @dataProvider data_manage_events_roles
	 */
	public function test_current_user_can_manage_events_by_role( string $role, bool $expected ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		$this->assertSame( $expected, current_user_can_manage_events() );
	}

	/**
	 * Logged-out visitors can never manage events.
	 */
	public function test_current_user_can_manage_events_false_when_logged_out() {
		wp_set_current_user( 0 );

		$this->assertFalse( current_user_can_manage_events() );
	}

	/**
	 * Data provider for test_current_user_can_manage_group_settings_by_role().
	 */
	public function data_manage_group_settings_roles(): array {
		return array(
			'administrator manages settings' => array( 'administrator', true ),
			'editor manages settings'        => array( 'editor', true ),
			'author cannot'                  => array( 'author', false ),
			'subscriber cannot'              => array( 'subscriber', false ),
		);
	}

	/**
	 * @dataProvider data_manage_group_settings_roles
	 */
	public function test_current_user_can_manage_group_settings_by_role( string $role, bool $expected ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		$this->assertSame( $expected, current_user_can_manage_group_settings() );
	}

	/**
	 * Regression test: a super admin whose nominal role on a given group is
	 * `subscriber` (e.g. a deputy checking in on a group they don't
	 * personally organize) must still be treated as able to manage events.
	 *
	 * `current_user_can_manage_group_settings()` naturally picks up core's
	 * super-admin capability elevation (it calls `current_user_can()`), so
	 * the "Set up your group" button renders for such a user. But this
	 * function is a role-array check, which does NOT pick up that
	 * elevation -- without the `is_super_admin()` check, the
	 * `wp-components`/`wp-block-editor` styles gated on it in
	 * `Modal::enqueue_supplementary_assets()` never load, and the settings
	 * modal renders with no CSS (invisible, but present in the DOM).
	 */
	public function test_super_admin_can_manage_events_despite_subscriber_role() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Reset to a clean array first -- `site_admins` is shared, mutable state
		// scoped to the current network, and other tests in the suite can leave
		// it in a shape `grant_super_admin()` doesn't expect.
		update_site_option( 'site_admins', array() );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		// `revoke_super_admin()` must run even if the assertion fails, or this
		// user's super-admin status leaks into later tests in the suite.
		try {
			$this->assertTrue( current_user_can_manage_events() );
		} finally {
			revoke_super_admin( $user_id );
		}
	}

	/**
	 * Regression test for the privilege-escalation bug fixed before #1793
	 * shipped: editors were briefly granted `promote_users` so this plugin
	 * could let them manage member roles, which also silently unlocked the
	 * stock wp-admin Users screen for promoting anyone to Administrator.
	 * Role management must go through this plugin's own REST layer
	 * (`current_user_can_manage_group_settings()`), never a real core
	 * capability grant.
	 */
	public function test_editor_does_not_have_promote_users() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertFalse( user_can( $user_id, 'promote_users' ) );
	}

	/**
	 * The fixture site is `events.wordpress.test/group/sunshine-coast-qld/`,
	 * which is on the `SELF_SERVE_ROLE_GROUPS` allow-list as the local
	 * development / test group.
	 */
	public function test_get_current_group_slug_reads_the_site_path() {
		$this->assertSame( 'sunshine-coast-qld', get_current_group_slug() );
	}

	/**
	 * Self-serve role switching is on for the allow-listed fixture group.
	 */
	public function test_self_serve_roles_enabled_on_allow_listed_group() {
		$this->assertTrue( self_serve_roles_enabled() );
	}

	/**
	 * ...and off for every group that isn't on the list. This is the check
	 * that keeps a real community group from handing out the Organizer tier
	 * to anyone who joins, so it's worth asserting directly rather than
	 * inferring it from the allow-list constant.
	 */
	public function test_self_serve_roles_disabled_on_other_group() {
		$other_site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/not-a-beta-group/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		switch_to_blog( $other_site_id );

		try {
			$this->assertSame( 'not-a-beta-group', get_current_group_slug() );
			$this->assertFalse( self_serve_roles_enabled() );
		} finally {
			restore_current_blog();
			wp_delete_site( $other_site_id );
		}
	}

	/**
	 * Sandboxes opt in through the filter rather than by editing the
	 * allow-list constant.
	 */
	public function test_self_serve_roles_filter_can_enable_a_group() {
		$other_site_id = self::factory()->blog->create(
			array(
				'domain'     => 'events.wordpress.test',
				'path'       => '/group/filtered-beta-group/',
				'network_id' => GROUPS_NETWORK_ID,
			)
		);

		switch_to_blog( $other_site_id );
		add_filter( 'wporg_groups_frontend_self_serve_roles_enabled', '__return_true' );

		try {
			$this->assertTrue( self_serve_roles_enabled() );
		} finally {
			remove_filter( 'wporg_groups_frontend_self_serve_roles_enabled', '__return_true' );
			restore_current_blog();
			wp_delete_site( $other_site_id );
		}
	}

	/**
	 * Data provider for test_current_user_can_switch_own_role_by_role().
	 */
	public function data_switch_own_role_roles(): array {
		return array(
			'subscriber switches'    => array( 'subscriber', true ),
			'author switches'        => array( 'author', true ),
			'editor switches'        => array( 'editor', true ),
			'administrator does not' => array( 'administrator', false ),
		);
	}

	/**
	 * Every tier but administrator can move itself between the three
	 * switchable roles; see `current_user_can_switch_own_role()` for why
	 * administrators are held back.
	 *
	 * @dataProvider data_switch_own_role_roles
	 */
	public function test_current_user_can_switch_own_role_by_role( string $role, bool $expected ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		$this->assertSame( $expected, current_user_can_switch_own_role() );
	}

	/**
	 * Logged-out visitors have no role to switch.
	 */
	public function test_current_user_cannot_switch_own_role_when_logged_out() {
		wp_set_current_user( 0 );

		$this->assertFalse( current_user_can_switch_own_role() );
	}

	/**
	 * Someone who hasn't joined the group can't promote themselves into it.
	 */
	public function test_current_user_cannot_switch_own_role_when_not_a_member() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		remove_user_from_blog( $user_id, get_current_blog_id() );
		wp_set_current_user( $user_id );

		$this->assertFalse( current_user_can_switch_own_role() );
	}

	/**
	 * The allow-list gates the capability, not just the markup — a member of
	 * a non-beta group gets no self-serve switch at all.
	 */
	public function test_current_user_cannot_switch_own_role_on_disabled_group() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		add_filter( 'wporg_groups_frontend_self_serve_roles_enabled', '__return_false' );

		try {
			$this->assertFalse( current_user_can_switch_own_role() );
		} finally {
			remove_filter( 'wporg_groups_frontend_self_serve_roles_enabled', '__return_false' );
		}
	}
}
