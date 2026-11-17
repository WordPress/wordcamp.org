<?php
/**
 * The capability behind the Groups tools in Network Admin.
 *
 * Creating, archiving and reactivating groups, deciding ownership transfers,
 * messaging organizers and exporting activity all check `CAPABILITY` instead
 * of `manage_sites`/`manage_network`, so Program Supporters can use them
 * without being network admins (#2294).
 *
 * Who holds it:
 * - Program Supporters, through the `program_supporter` subrole in
 *   `wcorg-subroles.php`. The list lives in `$wcorg_subroles`.
 * - Anyone with `manage_network`: network admins and trusted deputies
 *   (`trusted-deputy-capabilities.php`), who had all of these tools before.
 *
 * Only loaded on the Groups network, via
 * `load-other-mu-plugins.php::wcorg_include_network_only_plugins()`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Capabilities;

use WP_Admin_Bar;

defined( 'WPINC' ) || die();

const CAPABILITY = 'manage_groups';

/** Admin bar node ID for the link to the Groups screen. */
const ADMIN_BAR_NODE = 'wporg-groups-admin';

// After `trusted-deputy-capabilities.php` and `wcorg-subroles.php`, both at 10,
// so a deputy's `manage_network` is already in `$allcaps` here.
add_filter( 'user_has_cap', __NAMESPACE__ . '\grant_to_network_managers', 20 );
add_action( 'admin_bar_menu', __NAMESPACE__ . '\add_admin_bar_link', 25 );

/**
 * Whether the current user may use the Groups tools in Network Admin.
 */
function current_user_can_manage_groups(): bool {
	return current_user_can( CAPABILITY );
}

/**
 * Give `CAPABILITY` to everyone who can manage the network.
 *
 * Super admins pass every check anyway; this is for trusted deputies, whose
 * `manage_network` comes from a filter rather than `site_admins`.
 *
 * @param bool[] $allcaps The user's capabilities.
 *
 * @return bool[]
 */
function grant_to_network_managers( $allcaps ) {
	if ( ! empty( $allcaps['manage_network'] ) ) {
		$allcaps[ CAPABILITY ] = true;
	}

	return $allcaps;
}

/**
 * Link Program Supporters to the Groups screen from the admin bar.
 *
 * Core only links Network Admin from "My Sites" for `manage_network`, so
 * without this a Program Supporter has no way in short of typing the URL.
 * Network admins already have that link and don't get a second one.
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar.
 */
function add_admin_bar_link( WP_Admin_Bar $wp_admin_bar ): void {
	if ( ! current_user_can_manage_groups() || current_user_can( 'manage_network' ) ) {
		return;
	}

	$wp_admin_bar->add_node(
		array(
			'id'     => ADMIN_BAR_NODE,
			'parent' => $wp_admin_bar->get_node( 'my-sites' ) ? 'my-sites' : false,
			'title'  => __( 'Manage Groups', 'wordcamporg' ),
			'href'   => network_admin_url( 'admin.php?page=' . \WordCamp\Groups\Archive\PAGE_SLUG ),
		)
	);
}
