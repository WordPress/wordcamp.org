<?php
/**
 * Add the local dev environment's non-standard HTTPS port back onto generated URLs.
 *
 * The local Docker stack can bind HTTPS to something other than 443 so it can
 * run alongside another environment that wants that port (`WORDCAMP_HTTPS_PORT`
 * in `.env` -- see `.docker/readme.md`). `.docker/wp-config.php` strips the port
 * out of `HTTP_HOST` before anything reads it, so that every hostname comparison
 * in the codebase -- the network `switch`, the `sunrise*.php` regexes, and
 * WordPress's own lookups against the portless `domain` columns in `wp_blogs`
 * and `wp_site` -- keeps working unchanged.
 *
 * This file is the other half: it puts the port back on the way out, so links
 * and redirects point somewhere that is actually listening.
 *
 * Filtering `siteurl`/`home` rather than rewriting the database is deliberate.
 * It keeps the stored URLs canonical and portless (so the seeded
 * `wordcamp_dev.sql` stays valid, and nothing has to be re-run when the port
 * changes), and those two options are what `admin_url()`, `rest_url()`,
 * `includes_url()` and the canonical redirects are all built from -- so they
 * inherit the port for free.
 *
 * `content_url()` and `plugins_url()` are NOT in that list: locally they come
 * from the `WP_CONTENT_URL` constant, which `.docker/wp-config.php` builds with
 * the port already in it.
 *
 * @package WordCamp
 */

namespace WordCamp\Local_HTTPS_Port;

defined( 'WPINC' ) || die();

/**
 * Insert the local HTTPS port into a URL's host, if it isn't already there.
 *
 * @param mixed $url The URL to rewrite. Non-strings are returned untouched --
 *                   `option_siteurl` fires for every `get_option()` call on
 *                   that key, including ones that return `false` because the
 *                   option isn't set.
 *
 * @return mixed
 */
function add_port( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return $url;
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );

	// Already carries a port, or isn't a URL with a host to rewrite.
	if ( ! $host || wp_parse_url( $url, PHP_URL_PORT ) ) {
		return $url;
	}

	// Anchored on `://` so a host that also appears in the path or query
	// string isn't rewritten too.
	return preg_replace(
		'~^(\w+://)' . preg_quote( $host, '~' ) . '~',
		'$1' . $host . WORDCAMP_LOCAL_URL_PORT,
		$url,
		1
	);
}

/**
 * Send WP-Cron's loopback request to the port nginx actually listens on.
 *
 * `spawn_cron()` builds its URL from `site_url()`, which now carries the host
 * port -- but that port only exists on the *host* side of Docker's mapping.
 * Inside the container nginx is still on 443, so a request to `:8443` is
 * refused and cron silently never runs (verified: due events stay pending).
 *
 * @param array $cron_request The arguments `spawn_cron()` is about to use.
 *
 * @return array
 */
function use_container_port_for_cron( array $cron_request ): array {
	if ( ! isset( $cron_request['url'] ) || ! is_string( $cron_request['url'] ) ) {
		return $cron_request;
	}

	$cron_request['url'] = preg_replace(
		'~^(https?://[^/:]+)' . preg_quote( WORDCAMP_LOCAL_URL_PORT, '~' ) . '~',
		'$1',
		$cron_request['url'],
		1
	);

	return $cron_request;
}

/*
 * Inert unless this is the local Docker environment running on a non-standard
 * port. `WORDCAMP_LOCAL_URL_PORT` is only defined by `.docker/wp-config.php`,
 * which production never loads, but the environment is checked too so this can
 * never alter a real URL.
 *
 * `defined()` deliberately comes first. This file is the second mu-plugin
 * WordPress loads, so an `Error: Undefined constant` here would take down every
 * request before almost anything else runs -- and a bare `WORDCAMP_ENVIRONMENT`
 * is exactly that on PHP 8. Testing the constant that only ever exists locally
 * short-circuits everywhere else before any bare constant is evaluated.
 */
if ( ! defined( 'WORDCAMP_LOCAL_URL_PORT' )
	|| '' === WORDCAMP_LOCAL_URL_PORT
	|| 'local' !== WORDCAMP_ENVIRONMENT
) {
	return;
}

/*
 * Put the port back into `HTTP_HOST` now that it is safe to do so.
 *
 * `.docker/wp-config.php` removed it so that the network `switch`, sunrise, and
 * WordPress's `wp_blogs`/`wp_site` lookups could match on a bare hostname. All
 * of that resolution is finished by the time mu-plugins load, and from here on
 * the opposite is true: code compares the *requested* URL against `home_url()`
 * to decide whether to issue a canonical redirect. Leaving the port out of
 * `HTTP_HOST` while `home_url()` carries it makes every request look
 * non-canonical, which is an infinite redirect to itself.
 */
if ( isset( $_SERVER['HTTP_HOST'] ) && ! str_contains( $_SERVER['HTTP_HOST'], ':' ) ) {
	$_SERVER['HTTP_HOST'] .= WORDCAMP_LOCAL_URL_PORT;
}

add_filter( 'option_siteurl', __NAMESPACE__ . '\add_port' );
add_filter( 'option_home', __NAMESPACE__ . '\add_port' );
add_filter( 'network_site_url', __NAMESPACE__ . '\add_port' );
add_filter( 'network_home_url', __NAMESPACE__ . '\add_port' );
add_filter( 'cron_request', __NAMESPACE__ . '\use_container_port_for_cron' );
