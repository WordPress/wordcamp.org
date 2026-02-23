<?php

namespace WordCamp\Sunrise;
defined( 'WPINC' ) || die();


/*
 * Matches `2020-foo.narnia.wordcamp.org/`, with or without additional `REQUEST_URI` params.
 */
const PATTERN_YEAR_DOT_CITY_DOMAIN_PATH = '
	@ ^
	( \d{4} [\w-]* )           # Capture the year, plus any optional extra identifier.
	\.
	( [\w-]+ )                 # Capture the city.
	\.
	( wordcamp | buddycamp )   # Capture the second-level domain.
	\.
	( org | test )             # Capture the top level domain.
	/
	@ix
';

/*
 * Matches `narnia.wordcamp.org/2020-foo/`, with or without additional `REQUEST_URI` params.
 */
const PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH = '
	@ ^
	( [\w-]+ )                 # Capture the city.
	\.
	( wordcamp | buddycamp )   # Capture the second-level domain.
	\.
	( org | test )             # Capture the top-level domain.
	( / \d{4} [\w-]* / )       # Capture the site path (the year, plus any optional extra identifier).
	@ix
';

/*
 * Matches a request URI like `/2020/2019/save-the-date-for-wordcamp-vancouver-2020/`.
 */
const PATTERN_CITY_SLASH_YEAR_REQUEST_URI_WITH_DUPLICATE_DATE = '
	@ ^
	( / \d{4} [\w-]* / )   # Capture the site path (the year, plus any optional extra identifier).

	(                      # Capture the `/%year%/%monthnum%/%day%/` permastruct tags.
		[0-9]{4} /         # The year is required.

		(?:                # The month and day are optional.
			[0-9]{2} /
		){0,2}
	)

	(.+)                   # Capture the slug.
	$ @ix
';

/*
 * Matches a URL path like '/vancouver/2023/diversity-day/`.
 *
 * These are used by the `events.wordpress.org` network.
 */
const PATTERN_CITY_YEAR_TYPE_PATH = '
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

/*
 * Matches a URL path like '/vancouver/`.
 *
 * These are used by the `campus.wordpress.org` network.
 */
const PATTERN_CITY_PATH = '
	@ ^
	/
	( [\w-]+ )    # Capture the city.
	/?
	@ix
';

/**
 * Load the sunrise file for the current network.
 */
function load_network_sunrise() {
	switch ( SITE_ID_CURRENT_SITE ) {
		case CAMPUS_NETWORK_ID:
			// Intentional Fall through. Load Events plugins for now.
		case EVENTS_NETWORK_ID:
			require __DIR__ . '/sunrise-events.php';
			break;

		case WORDCAMP_NETWORK_ID:
		default:
			require __DIR__ . '/sunrise-wordcamp.php';
			break;
	}
}

/**
 * Get the TLD for the current environment.
 *
 * @return string
 */
function get_top_level_domain() {
	return 'local' === WORDCAMP_ENVIRONMENT ? 'test' : 'org';
}

/**
 * Get the Network ID for a given domain.
 *
 * @param string $domain The domain to check.
 * @return int The Network ID.
 */
function get_domain_network_id( string $domain ): int {
	$tld = get_top_level_domain();

	switch ( $domain ) {
		case "campus.wordpress.{$tld}":
			return CAMPUS_NETWORK_ID;

		case "events.wordpress.{$tld}":
			return EVENTS_NETWORK_ID;

		default:
			return WORDCAMP_NETWORK_ID;
	}
}

/**
 * Look up the current URL for a site that was previously at the given domain/path.
 *
 * When a site's domain or path is changed, the old URL is stored in `blogmeta`
 * by the `site-url-history` mu-plugin. This queries that data so the caller can
 * redirect old URLs to the current location.
 *
 * @param string $domain The requested domain.
 * @param string $path   The requested path.
 *
 * @return string|false The new URL to redirect to, or false if no match.
 */
function get_renamed_site_url( string $domain, string $path ) {
	global $wpdb;

	$old_home_url = 'https://' . $domain . trailingslashit( $path );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sunrise runs before caching is available.
	$site = $wpdb->get_row( $wpdb->prepare(
		"SELECT b.domain, b.path
		FROM {$wpdb->blogmeta} bm
		JOIN {$wpdb->blogs} b ON b.blog_id = bm.blog_id
		WHERE bm.meta_key = 'old_home_url'
			AND bm.meta_value = %s
			AND b.public = 1
			AND b.deleted = 0
		LIMIT 1",
		$old_home_url
	) );

	if ( ! $site ) {
		return false;
	}

	return 'https://' . $site->domain . $site->path;
}

load_network_sunrise();
