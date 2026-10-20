<?php

namespace WordCamp\Jetpack_Tweaks;

defined( 'WPINC' ) || die();

/**
 * Ensure admin color schemes have at least 4 color entries.
 *
 * Workaround for zero-bs-crm accessing index 3 of `$_wp_admin_css_colors[scheme]->colors`
 * when some color schemes only define 3 colors.
 *
 * @see ZeroBSCRM.AdminStyling.php:264
 * @see https://github.com/Automattic/jetpack/pull/47143
 */
add_action(
	'admin_head',
	function () {
		if ( ! defined( 'ZBS_PLUGIN_DIR' ) ) {
			return;
		}

		global $_wp_admin_css_colors;

		$current_color = get_user_option( 'admin_color' );

		if ( ! isset( $_wp_admin_css_colors[ $current_color ] ) ) {
			return;
		}

		$colors     = &$_wp_admin_css_colors[ $current_color ]->colors;
		$color_count = count( $colors );

		while ( $color_count < 4 ) {
			$colors[] = end( $colors );
			$color_count++;
		}
	},
	9
);
