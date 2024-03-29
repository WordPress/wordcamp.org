<?php
/**
 * Plugin Name: Override Theme JSON
 * Description: Set the custom theme.json settings.
 * Author:      WordCamp Central
 * Author URI:  http://wordcamp.org
 * Version:     0.1
 */

namespace WordCamp\CustomThemeJSON;

defined( 'WPINC' ) || die();

if ( ! class_exists( '\WordCamp\CustomThemeJSON\ThemeJSON' ) ) {
	require_once __DIR__ . '/inc/theme-json.php';
	require_once __DIR__ . '/inc/user-interface.php';

	// Add the admin pages.
	add_action(
		'plugins_loaded',
		function () {
			new UserInterface();
			\WordCamp\CustomThemeJSON\UserInterface::add_admin_pages();
		}
	);

	// Override the theme.json file with custom settings.
	\WordCamp\CustomThemeJSON\ThemeJSON::override( __DIR__ . '/test/custom-theme.json');
}
