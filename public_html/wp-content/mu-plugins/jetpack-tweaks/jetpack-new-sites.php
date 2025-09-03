<?php

namespace WordCamp\Jetpack_Tweaks;

defined( 'WPINC' ) || die();

add_filter( 'site_option_jetpack-network-settings', __NAMESPACE__ . '\auto_connect_new_sites' );
add_filter( 'default_site_option_jetpack-network-settings', __NAMESPACE__ . '\auto_connect_new_sites' );

/**
 * Automatically connect new sites to WordPress.com.
 * All sites at present have SSL on their primary domain, so we can safely auto-connect.
 *
 * If this ever changes, see https://github.com/WordPress/wordcamp.org/pull/1515.
 *
 * @param array $value
 *
 * @return array
 */
function auto_connect_new_sites( $value ) {
	if ( ! $value ) {
		$value = array();
	}

	$value['auto-connect'] = 1;
	$value['sub-site-connection-override'] = 0;

	return $value;
}
