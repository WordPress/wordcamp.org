<?php

namespace WordCamp\Sunrise\Events;
use WP_Network, WP_Site;
use function WordCamp\Sunrise\{ get_top_level_domain };

defined( 'WPINC' ) || die();
use const WordCamp\Sunrise\{ PATTERN_CITY_YEAR_TYPE_PATH, PATTERN_CITY_PATH };

// Redirecting would interfere with bin scripts, unit tests, etc.
if ( php_sapi_name() !== 'cli' ) {
	main();
}

/**
 * Controller for this file.
 */
function main() {
	$redirect_url = get_redirect_url( $_SERVER['REQUEST_URI'] );
	if ( $redirect_url ) {
		header( 'Location: ' . $redirect_url, true, 301 );
		die();
	}

	set_network_and_site();

	do_redirects();
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
 * This is needed to achieve the various URL structures we use, including:
 *  - `events.wordpress.org/{city}/{year}/{event-type}`
 *  - `events.wordpress.org/campusconnect/{year}/{city}`
 *  - `campus.wordpress.org/{university-city}`
 *
 * @see https://paulund.co.uk/wordpress-multisite-with-nested-folder-paths
 *
 * phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WP is designed in a way that requires this.
 * That's the whole point of `sunrise.php`.
 */
function set_network_and_site() {
	global $current_site, $current_blog, $blog_id, $site_id, $domain, $path, $public;

	// Originally WP referred to networks as "sites" and sites as "blogs".
	$current_site = WP_Network::get_instance( SITE_ID_CURRENT_SITE );
	$site_id      = $current_site->id;
	$path         = stripslashes( $_SERVER['REQUEST_URI'] );

	if ( 1 === preg_match( PATTERN_CITY_YEAR_TYPE_PATH, $path ) ) {
		if ( is_admin() ) {
			$path = preg_replace( '#(.*)/wp-admin/.*#', '$1/', $path );
		}

		list( $path ) = explode( '?', $path );

		$current_blog = get_site_by_path( DOMAIN_CURRENT_SITE, $path, 3 );

	} elseif (
		CAMPUS_NETWORK_ID === $site_id &&
		1 === preg_match( PATTERN_CITY_PATH, $path )
	) {
		if ( is_admin() ) {
			$path = preg_replace( '#(.*)/wp-admin/.*#', '$1/', $path );
		}

		list( $path ) = explode( '?', $path );

		$current_blog = get_site_by_path( DOMAIN_CURRENT_SITE, $path, 2 );
	} else {
		$current_blog = WP_Site::get_instance( BLOG_ID_CURRENT_SITE ); // The Root site constant defined in wp-config.php.
	}

	if ( ! $current_blog ) {
		// If the request doesn't match a site, redirect to the campus connect page.
		header( 'X-Redirect-By: Events/Sunrise::set_network_and_site' );
		header( 'Location: ' . NOBLOGREDIRECT, true, 302 );
		exit;
	}

	$blog_id = $current_blog->id;
	$domain  = $current_blog->domain;
	$public  = $current_blog->public;
}

/**
 * Handle any redirects needed on the Events & Campus networks.
 */
function do_redirects() {
	global $blog_id, $site_id;

	// campus.wordpress.org should redirect to the landing page.
	if ( CAMPUS_NETWORK_ID === $site_id && CAMPUS_ROOT_BLOG_ID === $blog_id && ! is_admin() && ! is_network_admin() ) {
		header( 'X-Redirect-By: Events/Sunrise::do_redirects' );
		header( 'Location: https://events.wordpress.org/campusconnect/', true, 302 );
		exit;
	}
}
