<?php
/**
 * Plugin Name: Override Theme JSON
 * Description: Allows organizers to develop their Custom Theme.json with whatever tools and environment they prefer.
 * Author:      WordCamp Central
 * Author URI:  http://wordcamp.org
 * Version:     0.1
 */

namespace WordCamp\CustomThemeJSON;

defined( 'WPINC' ) || die();

if ( ! class_exists( '\WordCamp\CustomThemeJSON\Resister' ) ) {

	define( 'WCCTJSN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
	define( 'WCCTJSN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );

	// TODO make activation check.
	if(!wp_theme_has_theme_json()) {
		var_dump('Theme does not support theme.json');
	}

	require_once __DIR__ . '/inc/custom-post-type.php';
	require_once __DIR__ . '/inc/theme-json.php';
	require_once __DIR__ . '/inc/user-interface.php';

	// Temporary: Clear the custom theme.json settings.
	// wp_clean_theme_json_cache();

	// Register variables and the custom post type.
	\WordCamp\CustomThemeJSON\CustomPostType::register();

	// Add the admin pages.
	add_action(
		'plugins_loaded',
		function () {
			new UserInterface();
		}
	);

	// Temporary: Override the theme.json file with custom settings.
	// \WordCamp\CustomThemeJSON\ThemeJSON::override( __DIR__ . '/test/custom-theme.json');
	// new \WordCamp\CustomThemeJSON\ThemeJSON();

	$theme_json_file = \WordCamp\CustomThemeJSON\ThemeJSON::get_current_theme_json();
	var_dump($theme_json_file);
}
