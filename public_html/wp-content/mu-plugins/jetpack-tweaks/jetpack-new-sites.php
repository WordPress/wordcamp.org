<?php

namespace WordCamp\Jetpack_Tweaks;

defined( 'WPINC' ) || die();

add_filter( 'pre_update_site_option_jetpack-network-settings', __NAMESPACE__ . '\auto_connect_new_sites', 10, 2 );

/**
 * Automatically connect new sites to WordPress.com.
 * All sites at present have SSL on their primary domain, so we can safely auto-connect.
 *
 * If this ever changes, see https://github.com/WordPress/wordcamp.org/pull/1515.
 *
 * @param array $new_value
 * @param array $old_value
 *
 * @return array
 */
function auto_connect_new_sites( $new_value, $old_value ) {
	$new_value['auto-connect'] = 1;

	return $new_value;
}
