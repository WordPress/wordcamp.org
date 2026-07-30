<?php
/**
 * Plugin Name:        WordCamp.org Network Theme Control
 * Plugin Description: Restrict certain themes to specific WordCamp.org networks.
 *
 * Some themes are built to run only within a certain network's ecosystem —
 * e.g. `groups-site` depends on GatherPress post types and the groups-only
 * mu-plugin tweaks (event ordering, non-public venues, timezone display).
 * Nothing in WordPress core prevents a theme from being activated on any
 * site across a shared multisite install, so without this guard, an admin
 * on an unrelated WordCamp or Central site could switch to one of these
 * themes and end up with a broken-looking site.
 *
 * `revert_wrong_network_activation()`'s `after_switch_theme` hook only fires
 * on the *next* request after a wrong-network switch (WordPress core defers
 * it to `check_theme_switched()`, hooked on `init` at priority 99) — well
 * after that next request has already loaded the restricted theme's
 * `functions.php`. `prevent_wrong_network_theme_boot()` closes that gap by
 * filtering `stylesheet`/`template` back to the previous theme for the
 * remainder of that one request, so the restricted theme's `functions.php`
 * never runs on the wrong network, not even once.
 *
 * Follows the same pattern as `wcorg-network-plugin-control.php`.
 *
 * @package WordCamp\Themes\Network
 */

namespace WordCamp\Themes\Network;

defined( 'WPINC' ) || die();

add_filter( 'wp_prepare_themes_for_js', __NAMESPACE__ . '\filter_prepared_themes' );
add_action( 'after_switch_theme', __NAMESPACE__ . '\revert_wrong_network_activation', 10, 2 );
add_action( 'switch_theme', __NAMESPACE__ . '\mark_theme_switched_this_request' );
add_filter( 'stylesheet', __NAMESPACE__ . '\prevent_wrong_network_theme_boot' );
add_filter( 'template', __NAMESPACE__ . '\prevent_wrong_network_theme_boot' );

/**
 * Map of theme stylesheet slug => network ID it's restricted to.
 *
 * When adding a network-specific theme, add it here.
 *
 * @access private
 *
 * @return array
 */
function _get_network_restricted_themes() {
	return array(
		'groups-site' => GROUPS_NETWORK_ID,
	);
}

/**
 * Hide restricted themes from the Appearance > Themes grid on the wrong network.
 *
 * @param array $prepared_themes Keyed by theme stylesheet slug.
 *
 * @return array
 */
function filter_prepared_themes( $prepared_themes ) {
	foreach ( _get_network_restricted_themes() as $stylesheet => $network_id ) {
		if ( get_current_network_id() !== $network_id ) {
			unset( $prepared_themes[ $stylesheet ] );
		}
	}

	return $prepared_themes;
}

/**
 * Records that `switch_theme()` ran during *this* request, so
 * `prevent_wrong_network_theme_boot()` can tell "a switch to a restricted
 * theme is pending from an earlier request" apart from "this request is
 * the one performing the switch."
 *
 * Theme `functions.php` files are only ever loaded once, very early during
 * bootstrap (`wp-settings.php`, well before any application code —
 * including whatever called `switch_theme()` — has run), using whichever
 * theme was already active when *this* request started. So a request that
 * calls `switch_theme()` itself never loads the new theme's `functions.php`
 * regardless; only a *later* request would. Skipping the override here
 * also avoids `wp theme activate`/wp-admin's own success feedback for this
 * request going stale (`get_stylesheet()` would otherwise still report the
 * previous theme immediately after a switch it just performed).
 */
function mark_theme_switched_this_request() {
	$GLOBALS['wcorg_network_theme_control_switched_this_request'] = true;
}

/**
 * Filters `stylesheet`/`template` so a restricted theme switched to on the
 * wrong network during an *earlier* request never actually boots on this
 * one either — not just from the request after the deferred backstop runs.
 *
 * `switch_theme()` records the previous theme in the `theme_switched`
 * option and only fires `after_switch_theme` (see
 * `revert_wrong_network_activation()`) on the *next* request, via
 * `check_theme_switched()` on `init` at priority 99. But theme
 * `functions.php` files load — and template files resolve — earlier in
 * the request, based on the raw `stylesheet`/`template` options, well
 * before `init` runs. Left alone, a restricted theme's `functions.php` —
 * which may assume plugins/constants unique to its own network — would
 * execute at least once, on the very first request to load it after the
 * wrong-network activation.
 *
 * This only overrides what the *current request* resolves to; it doesn't
 * touch the `theme_switched` option, so `revert_wrong_network_activation()`
 * still runs normally afterwards to persist the correction and show the
 * admin notice.
 *
 * @param string $value The `stylesheet` or `template` option's raw value.
 *
 * @return string
 */
function prevent_wrong_network_theme_boot( $value ) {
	static $cached_for_stylesheet = null;
	static $cached_old_theme      = false;

	if ( ! empty( $GLOBALS['wcorg_network_theme_control_switched_this_request'] ) ) {
		return $value;
	}

	// `get_option()` is already cheap (served from the options cache), but
	// `wp_get_theme()` does file I/O to read the theme's headers — only
	// redo that when the pending switch's stylesheet actually changes,
	// rather than on every one of the many `stylesheet`/`template` filter
	// calls in a request.
	$pending_stylesheet = get_option( 'theme_switched' );

	if ( $cached_for_stylesheet !== $pending_stylesheet ) {
		$cached_for_stylesheet = $pending_stylesheet;
		$cached_old_theme      = $pending_stylesheet ? wp_get_theme( $pending_stylesheet ) : false;
	}

	if ( ! $cached_old_theme || ! $cached_old_theme->exists() ) {
		return $value;
	}

	// Restriction is keyed by the child theme's *stylesheet* slug, but the
	// `template` filter receives the *parent* theme's slug as `$value` for
	// a child theme like `groups-site` — read the raw `stylesheet` option
	// directly rather than trusting `$value` to identify which theme is
	// active, regardless of which of the two filters is currently running.
	$restricted         = _get_network_restricted_themes();
	$current_stylesheet = get_option( 'stylesheet' );

	if ( ! isset( $restricted[ $current_stylesheet ] ) || get_current_network_id() === $restricted[ $current_stylesheet ] ) {
		return $value;
	}

	return 'template' === current_filter() ? $cached_old_theme->get_template() : $cached_old_theme->get_stylesheet();
}

/**
 * Hard backstop: revert the theme switch if a restricted theme was activated
 * on the wrong network (e.g. via `wp theme activate`, code, or REST).
 *
 * This only *persists* the correct `stylesheet`/`template` options and shows
 * the admin notice — `prevent_wrong_network_theme_boot()` above is what
 * actually stops the wrong theme from loading in the meantime (see its
 * docblock). Reads the raw `stylesheet` option rather than `get_stylesheet()`,
 * since that filter already makes the latter report the reverted value by
 * the time this runs.
 *
 * @param string    $old_name  Name of the theme that was active before the switch.
 * @param \WP_Theme $old_theme WP_Theme instance of the previous theme.
 */
function revert_wrong_network_activation( $old_name, $old_theme ) {
	$restricted = _get_network_restricted_themes();
	$stylesheet = get_option( 'stylesheet' );

	if ( ! isset( $restricted[ $stylesheet ] ) || get_current_network_id() === $restricted[ $stylesheet ] ) {
		return;
	}

	// `switch_theme()` internally calls `wp_get_theme()` with no argument to
	// determine the theme it's switching *from*, which in turn calls the
	// filtered `get_stylesheet()` — left alone, `prevent_wrong_network_theme_boot()`
	// would make that report the already-old theme instead of the actual
	// current (restricted) one, corrupting the `switch_theme` action's
	// `$old_theme` argument. Not needed here anyway, since this call already
	// knows exactly which theme it's reverting to.
	remove_filter( 'stylesheet', __NAMESPACE__ . '\prevent_wrong_network_theme_boot' );
	remove_filter( 'template', __NAMESPACE__ . '\prevent_wrong_network_theme_boot' );

	switch_theme( $old_theme->get_stylesheet() );

	add_filter( 'stylesheet', __NAMESPACE__ . '\prevent_wrong_network_theme_boot' );
	add_filter( 'template', __NAMESPACE__ . '\prevent_wrong_network_theme_boot' );

	add_action(
		'admin_notices',
		function () use ( $stylesheet ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: theme stylesheet slug */
						__( 'The "%s" theme can only be activated on its intended network. Your site has been reverted to its previous theme.', 'wordcamporg' ),
						$stylesheet
					)
				)
			);
		}
	);
}
