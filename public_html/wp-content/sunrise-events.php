<?php

namespace WordCamp\Sunrise\Events;
use WP_Network, WP_Site;

defined( 'WPINC' ) || die();

/*
 * Matches URLs of regular sites, but not the root site.
 *
 * For example, `events.wordpress.org/vancouver/2023/diversity-day/`.
 *
 */
const PATTERN_EVENT_SITE = '
	@ ^
	/
	( [\w-]+ )    # Capture the city.
	/
	( \d{4} )     # Capture the year.
	/
	( [\w-]+ )    # Capture the event type.
	/?
	@ix
';

set_network_and_site();


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

	if ( 1 === preg_match( PATTERN_EVENT_SITE, $path ) ) {
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
