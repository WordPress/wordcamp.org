<?php

namespace WordCamp\Jetpack_Tweaks\Search;

defined( 'WPINC' ) || die();

add_filter( 'option_instant_search_enabled',         __NAMESPACE__ . '\disable_instant_search_overlay' );
add_filter( 'default_option_instant_search_enabled', __NAMESPACE__ . '\disable_instant_search_overlay' );

/**
 * Disable the Jetpack Search instant-search overlay by default on WordCamp sites.
 *
 * WordCamp sites are auto-provisioned with a Jetpack Complete plan, which activates Jetpack
 * Search and auto-enables the instant-search "live results" overlay. That overlay renders
 * broken on WordCamp themes (it overflows the viewport and overlaps site content), so we
 * force the `instant_search_enabled` option off here. With instant search reported as off,
 * Jetpack automatically falls back to its classic search experience, so search keeps working
 * without the broken overlay.
 *
 * Filtering the stored option (rather than only `default_option_*`) is required because the
 * provisioning flow writes `instant_search_enabled = true` to the database, so the default
 * alone would never apply on existing sites.
 *
 * A site can opt back in by returning true from the `wordcamp_enable_jetpack_instant_search`
 * filter.
 *
 * @param mixed $value The stored (or default) option value.
 *
 * @return mixed `false` to disable instant search, or the original value when opted in.
 */
function disable_instant_search_overlay( $value ) {
	/**
	 * Allow a site to re-enable the Jetpack instant-search overlay.
	 *
	 * @param bool $enabled Whether the instant-search overlay should be enabled. Default false.
	 */
	if ( apply_filters( 'wordcamp_enable_jetpack_instant_search', false ) ) {
		return $value;
	}

	return false;
}
