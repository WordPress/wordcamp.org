<?php

namespace WordCamp\Jetpack_Tweaks\Search;

use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\Connection\Rest_Authentication;

defined( 'WPINC' ) || die();

add_action( 'add_option_instant_search_enabled',       __NAMESPACE__ . '\revert_provisioned_enable_on_add', 10, 2 );
add_action( 'update_option_instant_search_enabled',    __NAMESPACE__ . '\revert_provisioned_enable_on_update', 10, 2 );
add_action( 'add_option_jetpack_search_experience',    __NAMESPACE__ . '\revert_provisioned_overlay_on_add', 10, 2 );
add_action( 'update_option_jetpack_search_experience', __NAMESPACE__ . '\revert_provisioned_overlay_on_update', 10, 2 );

/**
 * Handle the option being created (first time it's set).
 *
 * @param string $option The option name.
 * @param mixed  $value  The new option value.
 */
function revert_provisioned_enable_on_add( $option, $value ) {
	maybe_revert_provisioned_enable( $value );
}

/**
 * Handle the option being updated.
 *
 * @param mixed $old_value The previous option value.
 * @param mixed $value     The new option value.
 */
function revert_provisioned_enable_on_update( $old_value, $value ) {
	maybe_revert_provisioned_enable( $value );
}

/**
 * Handle the experience option being created (first time it's set).
 *
 * @param string $option The option name.
 * @param mixed  $value  The new option value.
 */
function revert_provisioned_overlay_on_add( $option, $value ) {
	maybe_revert_provisioned_overlay( $value );
}

/**
 * Handle the experience option being updated.
 *
 * @param mixed $old_value The previous option value.
 * @param mixed $value     The new option value.
 */
function revert_provisioned_overlay_on_update( $old_value, $value ) {
	maybe_revert_provisioned_overlay( $value );
}

/**
 * Disable the Jetpack Search instant-search overlay when provisioning auto-enables it.
 *
 * WordCamp sites are auto-provisioned with a Jetpack Complete plan. As part of that, WordPress.com
 * enables Jetpack Search *and* its instant-search "live results" overlay by default (it writes
 * `instant_search_enabled = true`). That overlay renders broken on WordCamp themes -- it overflows
 * the viewport and overlaps site content (#1742).
 *
 * Since Jetpack Search 0.60 the overlay is also recorded as `jetpack_search_experience = 'overlay'`,
 * and that option is read *first*: while it says `overlay`, the legacy boolean is ignored. Jetpack
 * writes the experience option before the boolean, so reverting only the boolean left the overlay on
 * for every site provisioned since. `maybe_revert_provisioned_overlay()` handles the experience option.
 *
 * The automated enable arrives as a WordPress.com-signed REST request authenticated as the Jetpack
 * connection owner -- on WordCamp that is always the system `wordcamp` user (we force it during
 * provisioning). An organizer enabling the overlay themselves does so from wp-admin as their own
 * account, so we leave those changes alone. We therefore revert the option only when the change is
 * made by the connection owner via a signed request, which uniquely identifies the automated path.
 *
 * The Search module itself stays active, so Jetpack falls back to its classic search experience and
 * search keeps working without the broken overlay.
 *
 * @param mixed $value The new option value.
 */
function maybe_revert_provisioned_enable( $value ) {
	// Only react to it being turned on. The revert below re-fires this hook with a falsey value,
	// which this guard short-circuits, so there's no recursion.
	if ( ! $value ) {
		return;
	}

	if ( ! is_provisioning_request() ) {
		return;
	}

	update_option( 'instant_search_enabled', false );
}

/**
 * Clear the `overlay` search experience when provisioning selects it.
 *
 * See `maybe_revert_provisioned_enable()` for the background. An empty experience is what Jetpack
 * itself stores for "Inline" (the absence of an opt-in), which is the experience that works on
 * WordCamp themes. Organizers can still pick Overlay in wp-admin; only signed requests from the
 * connection owner are reverted.
 *
 * @param mixed $value The new option value.
 */
function maybe_revert_provisioned_overlay( $value ) {
	// Only react to the overlay being selected. The revert below re-fires this hook with an empty
	// value, which this guard short-circuits, so there's no recursion.
	if ( 'overlay' !== $value ) {
		return;
	}

	if ( ! is_provisioning_request() ) {
		return;
	}

	update_option( 'jetpack_search_experience', '' );
}

/**
 * Whether the current request is WordPress.com provisioning the site's Jetpack plan.
 *
 * @return bool
 */
function is_provisioning_request() {
	/**
	 * Filters whether the current request counts as WordPress.com provisioning.
	 *
	 * The default identity check needs a live Jetpack connection, so this lets environments without
	 * one (the test suite, sandboxes) stand in for a signed connection-owner request.
	 *
	 * @param bool $is_provisioning_request Whether the request is a signed connection-owner request.
	 */
	return (bool) apply_filters( 'wcorg_jetpack_search_is_provisioning_request', is_connection_owner_request() );
}

/**
 * Whether the current request is a Jetpack-signed change made as the connection owner.
 *
 * This is the identity WordPress.com uses for automated/provisioning calls. A human organizer
 * editing the setting in wp-admin is authenticated by cookie (not a signed token) and as their own
 * account, so this returns false for them.
 *
 * @return bool
 */
function is_connection_owner_request() {
	if ( ! class_exists( Rest_Authentication::class ) || ! class_exists( Manager::class ) ) {
		return false;
	}

	if ( ! Rest_Authentication::is_signed_with_user_token() ) {
		return false;
	}

	$owner_id = ( new Manager() )->get_connection_owner_id();

	return $owner_id && get_current_user_id() === $owner_id;
}
