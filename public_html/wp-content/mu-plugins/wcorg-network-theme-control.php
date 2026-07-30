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
 * Follows the same pattern as `wcorg-network-plugin-control.php`.
 *
 * @package WordCamp\Themes\Network
 */

namespace WordCamp\Themes\Network;

defined( 'WPINC' ) || die();

add_filter( 'wp_prepare_themes_for_js', __NAMESPACE__ . '\filter_prepared_themes' );
add_action( 'after_switch_theme', __NAMESPACE__ . '\revert_wrong_network_activation', 10, 2 );

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
		if ( $network_id !== get_current_network_id() ) {
			unset( $prepared_themes[ $stylesheet ] );
		}
	}

	return $prepared_themes;
}

/**
 * Hard backstop: revert the theme switch if a restricted theme was activated
 * on the wrong network (e.g. via `wp theme activate`, code, or REST).
 *
 * @param string    $old_name  Name of the theme that was active before the switch.
 * @param \WP_Theme $old_theme WP_Theme instance of the previous theme.
 */
function revert_wrong_network_activation( $old_name, $old_theme ) {
	$restricted = _get_network_restricted_themes();
	$stylesheet = get_stylesheet();

	if ( ! isset( $restricted[ $stylesheet ] ) || $restricted[ $stylesheet ] === get_current_network_id() ) {
		return;
	}

	switch_theme( $old_theme->get_stylesheet() );

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
