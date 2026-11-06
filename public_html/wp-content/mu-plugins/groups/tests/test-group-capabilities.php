<?php

namespace WordCamp\Groups\Tests;

use WordCamp\Groups\Archive;
use WordCamp\Groups\Capabilities;
use WordCamp\Groups\Messaging;
use WordCamp\Groups\Network_Export;
use WordCamp\Groups\Ownership_Transfer;
use WP_Admin_Bar;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/wporg-groups-frontend/tests/class-groups-testcase.php';
require_once __DIR__ . '/trait-program-supporter-fixtures.php';

/**
 * Who can use the Groups tools in Network Admin (#2294).
 *
 * @group groups
 */
class Test_Group_Capabilities extends Groups_TestCase {
	use Program_Supporter_Fixtures;

	/**
	 * Remember the role globals this class changes.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->save_role_fixtures();
	}

	/**
	 * Undo the network switch and any subrole or deputy fixtures.
	 */
	protected function tearDown(): void {
		$this->restore_role_fixtures();

		parent::tearDown();
	}

	/**
	 * Run every Groups tool's capability check as the current user.
	 *
	 * Site provisioning checks `Capabilities\current_user_can_manage_groups()`
	 * inline, so it's covered by that entry.
	 *
	 * @return bool[] Keyed by tool.
	 */
	private function tool_gates(): array {
		return array(
			'manage groups'   => Capabilities\current_user_can_manage_groups(),
			'archive'         => Archive\current_user_can_archive_groups(),
			'approve'         => Ownership_Transfer\current_user_can_approve(),
			'message'         => Messaging\current_user_can_send_messages(),
			'network export'  => Network_Export\current_user_can_export(),
		);
	}

	/**
	 * A Program Supporter can use every Groups tool, and nothing else that's
	 * network-level.
	 */
	public function test_program_supporter_can_use_the_groups_tools_and_nothing_else() {
		$user_id = $this->become_program_supporter();

		foreach ( $this->tool_gates() as $tool => $allowed ) {
			$this->assertTrue( $allowed, "A Program Supporter should be able to use: $tool." );
		}

		foreach ( array( 'manage_network', 'manage_sites', 'create_sites', 'manage_network_users', 'read', 'edit_posts' ) as $cap ) {
			$this->assertFalse( user_can( $user_id, $cap ), "The subrole must not grant $cap." );
		}
	}

	/**
	 * The subrole is scoped to the Groups network, like `report_viewer` is
	 * scoped to Central.
	 */
	public function test_program_supporter_has_nothing_outside_the_groups_network() {
		$user_id = $this->create_program_supporter();

		$this->assertNotSame( GROUPS_NETWORK_ID, get_current_network_id(), 'Precondition: the suite runs on another network.' );
		$this->assertFalse( user_can( $user_id, Capabilities\CAPABILITY ) );
	}

	/**
	 * Administrators of a group run that group, not the network.
	 */
	public function test_group_administrators_cannot_use_the_groups_tools() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->use_groups_network();
		wp_set_current_user( $user_id );

		foreach ( $this->tool_gates() as $tool => $allowed ) {
			$this->assertFalse( $allowed, "A group administrator should not be able to use: $tool." );
		}
	}

	/**
	 * Trusted deputies had every Groups tool through `manage_network` before
	 * the tools moved to their own capability, and keep them.
	 */
	public function test_trusted_deputies_keep_the_groups_tools() {
		global $trusted_deputies;

		$user_id          = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$trusted_deputies = array( $user_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->use_groups_network();
		wp_set_current_user( $user_id );

		$this->assertFalse( is_super_admin( $user_id ), 'Precondition: a deputy is not a super admin.' );

		foreach ( $this->tool_gates() as $tool => $allowed ) {
			$this->assertTrue( $allowed, "A trusted deputy should be able to use: $tool." );
		}
	}

	/**
	 * Super admins can use everything.
	 */
	public function test_super_admins_keep_the_groups_tools() {
		$user_id = self::factory()->user->create();

		$GLOBALS['super_admins'] = array( get_userdata( $user_id )->user_login ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->use_groups_network();
		wp_set_current_user( $user_id );

		foreach ( $this->tool_gates() as $tool => $allowed ) {
			$this->assertTrue( $allowed, "A super admin should be able to use: $tool." );
		}
	}

	/**
	 * Core only links Network Admin for `manage_network`, so a Program
	 * Supporter gets their own way in.
	 */
	public function test_admin_bar_links_program_supporters_to_the_groups_screen() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		$this->become_program_supporter();

		$admin_bar = new WP_Admin_Bar();
		Capabilities\add_admin_bar_link( $admin_bar );
		$node = $admin_bar->get_node( Capabilities\ADMIN_BAR_NODE );

		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'page=' . Archive\PAGE_SLUG, $node->href );
	}

	/**
	 * Network admins already have core's link, and nobody else gets one.
	 */
	public function test_admin_bar_link_is_only_for_program_supporters() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		$this->use_groups_network();

		$super_admin_id          = self::factory()->user->create();
		$GLOBALS['super_admins'] = array( get_userdata( $super_admin_id )->user_login ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$group_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		foreach ( array( $super_admin_id, $group_admin_id ) as $user_id ) {
			wp_set_current_user( $user_id );

			$admin_bar = new WP_Admin_Bar();
			Capabilities\add_admin_bar_link( $admin_bar );

			$this->assertNull( $admin_bar->get_node( Capabilities\ADMIN_BAR_NODE ) );
		}
	}
}
