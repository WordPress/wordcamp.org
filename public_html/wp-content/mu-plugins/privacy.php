<?php

namespace WordCamp\Privacy;

defined( 'WPINC' ) || die();

add_filter( 'privacy_policy_url', __NAMESPACE__ . '\set_privacy_policy_url', 10 );
add_filter( 'the_privacy_policy_link', __NAMESPACE__ . '\use_privacy_policy_link', 10, 2 );
add_filter( 'map_meta_cap', __NAMESPACE__ . '\disable_496_privacy_tools', 10, 4 );

/**
 * Get the URL for the Privacy Policy used across all sites.
 *
 * @return string
 */
function get_privacy_policy_url() {
	return 'https://wordpress.org/about/privacy/';
}

/**
 * Set a consistent Privacy Policy across all sites.
 *
 * @param string $url
 *
 * @return string
 */
function set_privacy_policy_url( $url ) {
	return get_privacy_policy_url();
}

/**
 * Bypass the site's privacy policy to show the global wporg page.
 *
 * @param string $link The privacy policy link. Empty string if it doesn't exist.
 *
 * @return string
 */
function use_privacy_policy_link( $link ) {
	if ( ! $link ) {
		return sprintf( '<a class="privacy-policy-link" href="%s">%s</a>',
			esc_url( get_privacy_policy_url() ),
			esc_html__( 'Privacy Policy', 'wordcamporg')
		);
	}
	return $link;
}

/**
 * Disable the privacy tools added in WordPress 4.9.6.
 *
 * Note that this is temporary until we have a system in place for handling privacy-related requests
 * on a network-wide basis.
 *
 * @param array  $required_capabilities The primitive capabilities that are required to perform the requested meta capability.
 * @param string $requested_capability  The requested meta capability.
 * @param int    $user_id               The user ID.
 * @param array  $args                  Adds the context to the cap. Typically the object ID.
 *
 * @return array The primitive capabilities that are required to perform the requested meta capability.
 */
function disable_496_privacy_tools( $required_capabilities, $requested_capability, $user_id, $args ) {
	$privacy_capabilities = array( 'manage_privacy_options', 'erase_others_personal_data', 'export_others_personal_data' );

	if ( in_array( $requested_capability, $privacy_capabilities ) ) {
		$required_capabilities[] = 'do_not_allow';
	}

	return $required_capabilities;
}
