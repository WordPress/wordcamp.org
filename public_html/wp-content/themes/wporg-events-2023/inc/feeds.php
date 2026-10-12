<?php

namespace WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

// Before `redirect_canonical()` (10), which would first send `/feed/rss2/` and `?feed=rss2` to `/feed/`.
add_action( 'template_redirect', __NAMESPACE__ . '\redirect_default_feed', 9 );

/**
 * Send the site's default feed to the Upcoming Events feed.
 *
 * Every page advertises `/feed/` in its `<head>`, but that is the `post` feed, and this site publishes no
 * posts, so it has always been empty. `/upcoming-events/feed/` serves the events properly, with links to
 * each event, its meetup as the author, and its location and time. Redirecting keeps a single feed
 * implementation and makes the advertised link work.
 */
function redirect_default_feed(): void {
	global $wp;

	$location = get_default_feed_redirect( $wp->query_vars );

	if ( ! $location ) {
		return;
	}

	wp_safe_redirect( $location, 301, 'WordPress Events' );
	exit;
}

/**
 * Where a request for the default feed should go, or '' when the request isn't for it.
 *
 * The default feed is a request for a feed and nothing else (`/feed/`, `/feed/rss2/`, `/feed/atom/`, ...).
 * A feed that names a post type or a page, including the Upcoming Events feed itself, is left alone, as is
 * the comments feed. Every format goes to the RSS feed, since that's the one the events have.
 *
 * @param array $query_vars The main request's query vars.
 *
 * @return string
 */
function get_default_feed_redirect( array $query_vars ): string {
	if ( array( 'feed' ) !== array_keys( array_filter( $query_vars ) ) ) {
		return '';
	}

	if ( str_starts_with( (string) $query_vars['feed'], 'comments-' ) ) {
		return '';
	}

	return home_url( '/upcoming-events/feed/' );
}
