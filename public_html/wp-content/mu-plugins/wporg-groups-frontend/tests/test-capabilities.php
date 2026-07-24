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

	public function test_current_user_can_manage_events_false_when_logged_out() {
		wp_set_current_user( 0 );

		$this->assertFalse( current_user_can_manage_events() );
	}

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
