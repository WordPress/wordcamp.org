<?php

namespace WordCamp\Jetpack_Tweaks\Asset_CDN;

defined( 'WPINC' ) || die();

add_filter( 'jetpack_cdn_core_version_and_locale', __NAMESPACE__ . '\skip_core_versions_the_cdn_lacks' );

/**
 * Keep Jetpack's asset CDN off core versions it doesn't have.
 *
 * WordPress.org runs unreleased core builds, and their version can look like a public release (`7.1.3` while
 * 7.1.2 is the newest one out). The CDN only carries released versions, so ask it before letting Jetpack rewrite
 * core script and style URLs; when the answer is no, hand back a version Jetpack won't treat as public.
 *
 * @param array $value array( $version, $locale ).
 *
 * @return array
 */
function skip_core_versions_the_cdn_lacks( $value ) {
	if ( ! is_array( $value ) || ! isset( $value[0] ) || ! is_string( $value[0] ) ) {
		return $value;
	}

	if ( ! cdn_has_core_version( $value[0] ) ) {
		$value[0] .= '-unpublished';
	}

	return $value;
}

/**
 * Ask the CDN whether it serves a given core version, and remember the answer network-wide.
 *
 * A "yes" is cached for a day; a "no" only for an hour, so sites pick up the real release soon after it ships.
 * A request that fails outright isn't cached at all, and counts as "no" for this request only.
 *
 * @param string $version The core version, e.g. `7.1.3`.
 *
 * @return bool
 */
function cdn_has_core_version( $version ) {
	$transient_key = 'wc_cdn_core_version_' . md5( $version );
	$cached        = get_site_transient( $transient_key );

	if ( 'yes' === $cached || 'no' === $cached ) {
		return 'yes' === $cached;
	}

	$response = wp_remote_head(
		sprintf( 'https://c0.wp.com/c/%s/wp-includes/js/jquery/jquery.min.js', rawurlencode( $version ) ),
		array( 'timeout' => 2 )
	);

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$available = 200 === wp_remote_retrieve_response_code( $response );

	set_site_transient(
		$transient_key,
		$available ? 'yes' : 'no',
		$available ? DAY_IN_SECONDS : HOUR_IN_SECONDS
	);

	return $available;
}
