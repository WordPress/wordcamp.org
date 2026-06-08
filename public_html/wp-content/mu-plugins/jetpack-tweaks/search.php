<?php

namespace WordCamp\Jetpack_Tweaks\Search;

defined( 'WPINC' ) || die();

/**
 * Per-site flag recording that we've already applied the "instant search off by default"
 * adjustment, so we never override an organizer who later opts back in.
 */
const DEFAULT_APPLIED_FLAG = 'wordcamp_instant_search_default_applied';

add_action( 'init', __NAMESPACE__ . '\disable_provisioned_instant_search' );

/**
 * Disable the Jetpack Search instant-search overlay once, after provisioning forces it on.
 *
 * WordCamp sites are auto-provisioned with a Jetpack Complete plan. As part of that, WordPress.com
 * calls Jetpack's plan-activation endpoint, which enables Jetpack Search *and* its instant-search
 * "live results" overlay by default (it writes `instant_search_enabled = true`). That overlay
 * renders broken on WordCamp themes -- it overflows the viewport and overlaps site content (#1742).
 *
 * Rather than permanently forcing the option off (which would stop organizers from turning the
 * overlay on themselves), we flip it off a single time -- the first time we observe it enabled --
 * and then record a per-site flag and leave the option alone forever after. The Search module stays
 * active, so Jetpack falls back to its classic search experience. Because the admin UI saves through
 * a different endpoint, an organizer can opt back in from Jetpack's search settings and have it stick.
 *
 * Provisioning enables instant search asynchronously (a separate WordPress.com request), so we react
 * to the resulting option state on a later request rather than trying to hook the activation itself.
 * This also self-heals sites that were already provisioned before this code shipped.
 */
function disable_provisioned_instant_search() {
	if ( get_option( DEFAULT_APPLIED_FLAG ) ) {
		return;
	}

	// Not forced on (yet). Leave the flag unset so we still catch it once provisioning enables it.
	if ( ! get_option( 'instant_search_enabled' ) ) {
		return;
	}

	update_option( 'instant_search_enabled', false );
	update_option( DEFAULT_APPLIED_FLAG, true );
}
