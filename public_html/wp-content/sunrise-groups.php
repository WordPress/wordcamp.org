<?php

namespace WordCamp\Sunrise\Groups;
use WP_Network, WP_Site;
use function WordCamp\Sunrise\{ get_top_level_domain, get_renamed_site_url };

defined( 'WPINC' ) || die();
use const WordCamp\Sunrise\PATTERN_GROUP_PATH;

// Redirecting would interfere with bin scripts, unit tests, etc.
if ( php_sapi_name() !== 'cli' ) {
	main();
}

/**
 * Controller for this file.
 */
function main() {
	set_network_and_site();
	do_redirects();
}

/**
 * Determine the current network and site for the WordPress Groups network.
 *
 * The groups network shares its hostname (`events.wordpress.org`) with the
 * events network — the network swap happens earlier in `wp-config.php` based
 * on whether the request URI starts with `/group/`. By the time this file
 * loads, `SITE_ID_CURRENT_SITE` is already `GROUPS_NETWORK_ID`. All this
 * function has to do is resolve which group subsite (or the network root)
 * the request belongs to.
 *
 * URL structure:
 *   - `events.wordpress.org/group/{slug}/` → an individual group subsite
 *   - `events.wordpress.org/group/`        → the network root (placeholder)
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

	if ( 1 === preg_match( PATTERN_GROUP_PATH, $path ) ) {
		if ( is_admin() ) {
			$path = preg_replace( '#(.*)/wp-admin/.*#', '$1/', $path );
		}

		list( $path ) = explode( '?', $path );

		$current_blog = get_site_by_path( DOMAIN_CURRENT_SITE, $path, 2 );

		// `get_site_by_path()` falls back to the deepest matching site, which
		// for the groups network is `/group/` (the network root). Treat that
		// as "no group matched" so the not-found chain (renamed-url →
		// NOBLOGREDIRECT) can handle unknown `/group/<slug>/` paths. Bare
		// `/group/` requests are intentionally left on the root blog so
		// `do_redirects()` can send them to the events root below.
		$is_bare_group_request = '/group/' === $path || '/group' === rtrim( $path, '/' );
		if ( $current_blog && '/group/' === $current_blog->path && ! $is_bare_group_request ) {
			$current_blog = false;
		}
	} else {
		// Anything that isn't `/group/...` on the groups network resolves to
		// the network root blog. `do_redirects()` will then bounce front-end
		// requests to the events root.
		$current_blog = WP_Site::get_instance( BLOG_ID_CURRENT_SITE );
	}

	if ( ! $current_blog ) {
		// Check if this URL was previously used by a group that has since been renamed.
		$renamed_url = get_renamed_site_url( DOMAIN_CURRENT_SITE, $path );

		if ( $renamed_url ) {
			header( 'X-Redirect-By: Groups/Sunrise::set_network_and_site (renamed site)' );
			header( 'Location: ' . $renamed_url, true, 301 );
			exit;
		}

		if ( defined( 'NOBLOGREDIRECT' ) ) {
			header( 'X-Redirect-By: Groups/Sunrise::set_network_and_site' );
			header( 'Location: ' . NOBLOGREDIRECT, true, 302 );
			exit;
		}

		// No redirect available; fall back to the root site and let WordPress handle the 404.
		$current_blog = WP_Site::get_instance( BLOG_ID_CURRENT_SITE );
	}

	$blog_id = $current_blog->id;
	$domain  = $current_blog->domain;
	$public  = $current_blog->public;
}

/**
 * Handle any redirects needed on the Groups network.
 */
function do_redirects() {
	global $blog_id, $site_id;

	// The groups network root (`events.wordpress.org/group/`) is a placeholder
	// that exists only because WordPress multisite requires every network to
	// have a root site. Front-end requests bounce to the events root.
	if ( GROUPS_ROOT_BLOG_ID === $blog_id && ! is_admin() && ! is_network_admin() ) {
		header( 'X-Redirect-By: Groups/Sunrise::do_redirects' );
		header( 'Location: https://events.wordpress.' . get_top_level_domain() . '/', true, 302 );
		exit;
	}
}
