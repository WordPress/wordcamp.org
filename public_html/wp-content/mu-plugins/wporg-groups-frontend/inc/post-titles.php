<?php
/**
 * Keep event and venue titles as plain text, whichever route writes them.
 *
 * `inc/rest.php` applies `wcorg_sanitize_plain_text()` at its own three
 * `post_title` write sites, but that only covers this plugin's REST layer.
 * Venues are also written through core's posts controller — the modal's
 * "+ Add a new venue" overlay POSTs to `/wp/v2/gatherpress_venues`, and so
 * does the block editor in wp-admin — which never reaches those call sites.
 *
 * Core allows a subset of markup in every `post_title`: `wp_filter_kses()`
 * permits `$allowedtags`, which includes `<a href title>`, and
 * `core/post-title` emits `get_the_title()` into element content without
 * `wp_kses_post()`. That is ordinary WordPress behaviour -- a plain `post`
 * or `page` does the same -- so it belongs here as site policy rather than
 * upstream in GatherPress, which registers these as ordinary post types and
 * adds nothing to the storage path.
 *
 * `rest_pre_insert_{$post_type}` is the hook that makes the policy scoped
 * and correct at the same time: it is per-post-type, so nothing else on the
 * site changes, and it runs *before* kses. Ordering is the whole game here.
 * By `wp_insert_post_data` the value has already been through kses, and
 * `Hall < 100 > seats` has already become `Hall  seats` -- the data the
 * helper exists to preserve is gone before a later hook can see it.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Post_Titles;

defined( 'WPINC' ) || die();

/**
 * Post types whose titles are organizer-submitted text rather than markup.
 *
 * Events are included even though `inc/rest.php` already covers this
 * plugin's own write paths: core's `/wp/v2/gatherpress_events` route is
 * reachable directly and bypasses them, and the same reasoning applies to
 * the title either way.
 *
 * Spelled as literals rather than `Venue::POST_TYPE`, because this file is
 * required at the top of `wporg-groups-frontend.php` -- before the
 * `class_exists()` guard in its `bootstrap()`. Naming the class here is a
 * fatal on every site in the network that doesn't run GatherPress, which is
 * most of them. `test_post_type_names_match_gatherpress()` pins the literals
 * to the classes so an upstream rename still fails loudly.
 */
const PLAIN_TEXT_TITLE_POST_TYPES = array(
	'gatherpress_venue',
	'gatherpress_event',
);

/**
 * Register the filters.
 */
function bootstrap(): void {
	foreach ( PLAIN_TEXT_TITLE_POST_TYPES as $post_type ) {
		add_filter( "rest_pre_insert_{$post_type}", __NAMESPACE__ . '\sanitize_title_to_plain_text' );
	}
}

/**
 * Reduce a submitted title to text that stays text once WordPress saves it.
 *
 * Applying this to an already-encoded title is a no-op, so the double write
 * that `inc/rest.php` performs for events -- once through core's route, once
 * through `wp_insert_post()` -- can't encode twice.
 *
 * @param object $prepared The post about to be inserted, as built by the REST
 *                         posts controller.
 *
 * @return object The same object, with `post_title` reduced to plain text.
 */
function sanitize_title_to_plain_text( $prepared ) {
	// A partial update that doesn't touch the title leaves `post_title` unset;
	// writing one in would blank the stored title.
	if ( ! isset( $prepared->post_title ) || '' === $prepared->post_title ) {
		return $prepared;
	}

	$prepared->post_title = wcorg_sanitize_plain_text( $prepared->post_title );

	return $prepared;
}
