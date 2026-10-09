<?php

namespace WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

add_filter( 'request', __NAMESPACE__ . '\use_events_for_default_feed' );

/**
 * Serve events from the site's default feed.
 *
 * Every page advertises `/feed/` in its `<head>`, but that is the `post` feed, and this site publishes no
 * posts, so it has always been empty. The events live in the `wporg_events` post type, whose feed only
 * answered at `/feed/?post_type=wporg_events`, which nothing linked to. A feed request that names no post
 * type now gets the events instead. A request that asks for a type, or for the comments feed, is left alone.
 *
 * @param array $query_vars The main request's query vars.
 *
 * @return array
 */
function use_events_for_default_feed( array $query_vars ): array {
	if ( empty( $query_vars['feed'] ) || ! empty( $query_vars['post_type'] ) || isset( $query_vars['withcomments'] ) ) {
		return $query_vars;
	}

	$query_vars['post_type'] = 'wporg_events';

	return $query_vars;
}
