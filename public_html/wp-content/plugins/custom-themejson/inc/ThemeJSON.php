<?php
/**
 * Class ThemeJSON
 *
 * This class is responsible for overriding the theme.json file.
 */

namespace WordCamp\CustomThemeJSON;

defined( 'WPINC' ) || die();

class ThemeJSON {
	/**
	 * Override the theme.json file with custom settings.
	 *
	 * @param string $file_path The path to the theme.json file.
	 */
	public static function override( $file_path ) {
		// Read the theme.json file
		if ( empty($file_path) ) {
			return;
		}
		$custom_theme_json = json_decode(file_get_contents($file_path), true);

		add_filter('wp_theme_json_data_theme', function ( $theme_json ) use ( $custom_theme_json ) {
			return $theme_json->update_with( $custom_theme_json );
		});
	}
}
