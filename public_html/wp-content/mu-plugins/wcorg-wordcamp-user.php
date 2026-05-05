<?php
/**
 * Manage the shared `wordcamp` support user across the network.
 *
 * - Adds the `wordcamp` user as an administrator on every newly created site, so
 *   support always has direct access without having to flip super-admin powers.
 * - Prevents non-super-admins from editing, deleting, removing, or promoting the
 *   `wordcamp` user from a site, so changes to it don't cause unintended side
 *   effects across the network.
 *
 * @package WordCamp\WordCampUser
 */

namespace WordCamp\WordCampUser;

defined( 'WPINC' ) || die();

const USER_LOGIN = 'wordcamp';

add_action( 'wp_initialize_site', __NAMESPACE__ . '\add_to_new_site', 100, 1 );
add_filter( 'map_meta_cap', __NAMESPACE__ . '\protect_user', 10, 4 );


/**
 * Look up the ID of the `wordcamp` user once per request.
 *
 * Returns 0 if the user doesn't exist (e.g. on a network where it hasn't been created),
 * so callers can short-circuit without breaking site creation or cap checks.
 */
function get_user_id() : int {
	static $user_id = null;

	if ( null === $user_id ) {
		$user    = get_user_by( 'login', USER_LOGIN );
		$user_id = $user ? (int) $user->ID : 0;
	}

	return $user_id;
}

/**
 * Add the `wordcamp` user as an administrator to a newly created site.
 *
 * @param \WP_Site $site The newly created site.
 */
function add_to_new_site( $site ) {
	$user_id = get_user_id();

	if ( ! $user_id ) {
		return;
	}

	add_user_to_blog( (int) $site->blog_id, $user_id, 'administrator' );
}

/**
 * Block non-super-admins from editing, deleting, removing, or promoting the `wordcamp` user.
 *
 * @param string[] $required_caps Primitive caps the actor must have.
 * @param string   $cap           The meta cap being mapped.
 * @param int      $acting_user   The acting user's ID.
 * @param array    $args          Additional args; $args[0] is the target user ID for these caps.
 *
 * @return string[]
 */
function protect_user( $required_caps, $cap, $acting_user, $args ) {
	$protected = array( 'edit_user', 'delete_user', 'remove_user', 'promote_user' );

	if ( ! in_array( $cap, $protected, true ) ) {
		return $required_caps;
	}

	if ( empty( $args[0] ) ) {
		return $required_caps;
	}

	$wordcamp_user_id = get_user_id();

	if ( ! $wordcamp_user_id || (int) $args[0] !== $wordcamp_user_id ) {
		return $required_caps;
	}

	if ( is_super_admin( $acting_user ) ) {
		return $required_caps;
	}

	return array( 'do_not_allow' );
}
