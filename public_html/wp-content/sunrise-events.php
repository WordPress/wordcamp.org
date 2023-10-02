<?php

namespace WordCamp\Sunrise\Events;
use WP_Network, WP_Site;
use function WordCamp\Sunrise\{ get_top_level_domain };

defined( 'WPINC' ) || die();
use const WordCamp\Sunrise\PATTERN_CITY_YEAR_TYPE_PATH;

main();


/**
 * Controller for this file.
 */
function main() {
	// Redirecting would interfere with bin scripts, unit tests, etc.
	if ( php_sapi_name() !== 'cli' ) {
		$redirect_url = get_redirect_url( $_SERVER['REQUEST_URI'] );

		if ( $redirect_url ) {
			header( 'Location: ' . $redirect_url, true, 301 );
			die();
		}
	}

	set_network_and_site();
}

/**
 * Get the URL to redirect to, if any.
 */
function get_redirect_url( string $request_uri ): string {
	$domain       = 'events.wordpress.' . get_top_level_domain();
	$old_full_url = sprintf(
		'https://%s/%s',
		$domain,
		ltrim( $request_uri, '/' )
	);

	$renamed_sites = array(
		'/uganda/2024/wordpress-showcase/' => '/masaka/2024/wordpress-showcase/',
	);

	foreach ( $renamed_sites as $old_site_path => $new_site_path ) {
		if ( str_starts_with( $request_uri, $old_site_path ) ) {
			$new_full_url = str_replace( $old_site_path, $new_site_path, $old_full_url );
			return $new_full_url;
		}
	}

	return '';
}

/**
 * Determine the current network and site.
 *
 * This is needed to achieve the `events.wordpress.org/{year}/{event-type}{city}` URL structure.
 *
 * @see https://paulund.co.uk/wordpress-multisite-with-nested-folder-paths
 *
 * phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WP is designed in a way that requires this.
 * That's the whole point of `sunrise.php`.
 */
function set_network_and_site() {
	global $current_site, $current_blog, $blog_id, $site_id, $domain, $path, $public;

	// Originally WP referred to networks as "sites" and sites as "blogs".
	$current_site = WP_Network::get_instance( EVENTS_NETWORK_ID );
	$site_id      = $current_site->id;
	$path         = stripslashes( $_SERVER['REQUEST_URI'] );

	if ( 1 === preg_match( PATTERN_CITY_YEAR_TYPE_PATH, $path ) ) {
		if ( is_admin() ) {
			$path = preg_replace( '#(.*)/wp-admin/.*#', '$1/', $path );
		}

		list( $path ) = explode( '?', $path );

		$current_blog = get_site_by_path( DOMAIN_CURRENT_SITE, $path, 3 );

	} else {
		$current_blog = WP_Site::get_instance( EVENTS_ROOT_BLOG_ID );
	}

	$blog_id = $current_blog->id;
	$domain  = $current_blog->domain;
	$public  = $current_blog->public;
}
