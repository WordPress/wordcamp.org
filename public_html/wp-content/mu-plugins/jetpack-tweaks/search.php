<?php

namespace WordCamp\Jetpack_Tweaks\Search;

use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\Connection\Rest_Authentication;

defined( 'WPINC' ) || die();

add_filter( 'pre_update_option_instant_search_enabled',    __NAMESPACE__ . '\block_provisioned_enable' );
add_filter( 'pre_update_option_jetpack_search_experience', __NAMESPACE__ . '\block_provisioned_overlay' );

/**
 * Keep provisioning from turning on the Jetpack Search instant-search overlay.
 *
 * WordCamp sites are auto-provisioned with a Jetpack Complete plan. As part of that, WordPress.com
 * enables Jetpack Search *and* its instant-search "live results" overlay by default (it writes
 * `instant_search_enabled = true`). That overlay renders broken on WordCamp themes -- it overflows
 * the viewport and overlaps site content (#1742).
 *
 * Since Jetpack Search 0.60 the overlay is also recorded as `jetpack_search_experience = 'overlay'`,
 * and that option is read *first*: while it says `overlay`, the legacy boolean is ignored, so
 * blocking only the boolean left the overlay on for every site provisioned since.
 * `block_provisioned_overlay()` handles the experience option.
 *
 * Both run on `pre_update_option_*`, which is before core drops a write of the value already stored.
 * Reverting after the write instead missed sites whose option already said `overlay`: re-provisioning
 * them wrote the same value, no update hook fired, and the overlay stayed on.
 *
 * The automated enable arrives as a WordPress.com-signed REST request authenticated as the Jetpack
 * connection owner -- on WordCamp that is always the system `wordcamp` user (we force it during
 * provisioning). An organizer enabling the overlay themselves does so from wp-admin as their own
 * account, so we leave those changes alone. We therefore block the value only when the change is
 * made by the connection owner via a signed request, which uniquely identifies the automated path.
 *
 * The Search module itself stays active, so Jetpack falls back to its classic search experience and
 * search keeps working without the broken overlay.
 *
 * @param mixed $value The new option value.
 *
 * @return mixed
 */
function block_provisioned_enable( $value ) {
	if ( $value && is_provisioning_request() ) {
		return false;
	}

	return $value;
}

/**
 * Store no experience instead of an overlay one when provisioning selects it.
 *
 * See `block_provisioned_enable()` for the background. Jetpack has two overlays, the legacy `overlay`
 * and the blocks-powered `overlay_blocks`, and both are blocked. An empty experience is what Jetpack
 * itself stores for "Inline" (the absence of an opt-in), which is the experience that works on
 * WordCamp themes. Organizers can still pick an overlay in wp-admin; only signed requests from the
 * connection owner are blocked.
 *
 * @param mixed $value The new option value.
 *
 * @return mixed
 */
function block_provisioned_overlay( $value ) {
	if ( in_array( $value, array( 'overlay', 'overlay_blocks' ), true ) && is_provisioning_request() ) {
		return '';
	}

	return $value;
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
