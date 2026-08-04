<?php

namespace WordCamp\Groups\Tests;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;
use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_group_settings;

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
	 * personally organise) must still be treated as able to manage events.
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
		$user    = get_userdata( $user_id );

		// Overrides the `$super_admins` global directly rather than going through
		// `grant_super_admin()`/the `site_admins` site option, which is shared,
		// mutable state that other tests in the suite can leave in a bad shape.
		$GLOBALS['super_admins'] = array( $user->user_login );
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can_manage_events() );

		unset( $GLOBALS['super_admins'] );
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
}
