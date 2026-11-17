<?php

namespace WordCamp\Groups\Tests;

defined( 'WPINC' ) || die();

/**
 * Act as a Program Supporter on the Groups network.
 *
 * The suite runs with the WordCamp network current, and the
 * `program_supporter` subrole only applies on the Groups one, so tests switch
 * networks the way a real Groups request would have it. Call
 * `save_role_fixtures()` from `setUp()` and `restore_role_fixtures()` from
 * `tearDown()`.
 */
trait Program_Supporter_Fixtures {
	/**
	 * The network that was current before `use_groups_network()`.
	 *
	 * @var \WP_Network|null
	 */
	private $original_network;

	/**
	 * `$wcorg_subroles` and `$trusted_deputies` as they were before the test.
	 *
	 * @var array
	 */
	private $original_role_globals = array();

	/**
	 * Remember the globals these fixtures change.
	 */
	private function save_role_fixtures(): void {
		$this->original_role_globals = array(
			'wcorg_subroles'   => $GLOBALS['wcorg_subroles'] ?? null,
			'trusted_deputies' => $GLOBALS['trusted_deputies'] ?? null,
		);
	}

	/**
	 * Undo the network switch and any subrole, deputy or super admin fixtures.
	 */
	private function restore_role_fixtures(): void {
		if ( $this->original_network ) {
			$GLOBALS['current_site'] = $this->original_network; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->original_network  = null;
		}

		foreach ( $this->original_role_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		unset( $GLOBALS['super_admins'] );
	}

	/**
	 * Make the Groups network current.
	 */
	private function use_groups_network(): void {
		$this->original_network  = $GLOBALS['current_site'];
		$GLOBALS['current_site'] = get_network( GROUPS_NETWORK_ID ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Create a user who holds only the `program_supporter` subrole, and no
	 * role on any site.
	 */
	private function create_program_supporter(): int {
		global $wcorg_subroles;

		$user_id = self::factory()->user->create( array( 'role' => '' ) );

		$wcorg_subroles = array( $user_id => array( 'program_supporter' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return $user_id;
	}

	/**
	 * Switch to the Groups network and log in as a new Program Supporter.
	 */
	private function become_program_supporter(): int {
		$user_id = $this->create_program_supporter();

		$this->use_groups_network();
		wp_set_current_user( $user_id );

		return $user_id;
	}
}
