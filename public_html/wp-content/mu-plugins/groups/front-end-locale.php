<?php
/**
 * Serve the group front end in the visitor's own language.
 *
 * WordPress reads a user's profile language in wp-admin and nowhere else:
 * `determine_locale()` returns the *site* locale for any front-end request,
 * so someone who set their language to Spanish saw Spanish in wp-admin and
 * English on every public group page. Translators raised it because it left
 * them unable to see a string in the place it actually renders (#2038).
 *
 * Only logged-in visitors, and only their stored profile language. The
 * browser's `Accept-Language` is deliberately not consulted: an anonymous
 * visitor's locale would have to become part of the page cache key, which is
 * a much larger change than this one and belongs with whoever owns the cache.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Front_End_Locale;

defined( 'WPINC' ) || die();

/**
 * Whether this request should follow the visitor rather than the site.
 *
 * Kept away from wp-admin and the REST API, which core already resolves for
 * itself, and away from cron and WP-CLI, where "the visitor" is nobody.
 *
 * @return bool
 */
function should_follow_visitor(): bool {
	/*
	 * `determine_locale()` can run before `pluggable.php` is loaded -- core
	 * calls it while loading its own default text domain. Asking who the
	 * visitor is before then would fatal, and the answer at that point is
	 * "nobody" anyway; the filter runs again once the request is further
	 * along, which is when the front end's own strings are translated.
	 */
	if ( ! function_exists( 'is_user_logged_in' ) || ! function_exists( 'wp_get_current_user' ) ) {
		return false;
	}

	if ( ! is_user_logged_in() ) {
		return false;
	}

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return false;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return false;
	}

	// `REST_REQUEST` is defined by the time any of our routes run, and core
	// already applies the user's locale there.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	return true;
}

/**
 * Swap the site locale for the visitor's on public group pages.
 *
 * @param string $locale The locale core settled on.
 *
 * @return string The locale to render in.
 */
function use_visitor_locale( $locale ) {
	if ( ! should_follow_visitor() ) {
		return $locale;
	}

	$user_locale = get_user_locale();

	// `get_user_locale()` falls back to the site locale for a user who never
	// chose one, so this is a no-op for most visitors.
	return $user_locale ? $user_locale : $locale;
}
add_filter( 'determine_locale', __NAMESPACE__ . '\use_visitor_locale' );
