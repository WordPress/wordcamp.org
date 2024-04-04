<?php
namespace WordCamp\CustomThemeJSON;

defined( 'WPINC' ) || die();

/**
 * Class ThemeJSON
 *
 * This class is responsible for the theme.json file.
 */
class ThemeJSON {

	const SAVED_THEME_JSON_URL = WCCTJSN_PATH . '/data/custom-theme.json';

	/**
	 * constructor
	 */
	public function __construct() {
		// Override
		self::override( self::get_custom_file() );
	}

	/**
	 * Override the theme.json file with custom settings.
	 *
	 * @param string $file_path The path to the theme.json file.
	 */
	public static function override( $file_path ) {
		// Read the theme.json file.
		if ( empty($file_path) ) {
			return;
		}
		$custom_theme_json = json_decode(file_get_contents($file_path), true);

		add_filter(
			'wp_theme_json_data_theme',
			function ( $theme_json ) use ( $custom_theme_json ) {
				return $theme_json->update_with( $custom_theme_json );
			}
		);
	}

	/**
	 * Get the current theme.json file data.
	 *
	 * @return array
	 */
	public static function get_current_theme_json() {
		$current_data = \WP_Theme_JSON_Resolver::get_theme_data();
		if ( empty($current_data) ) {
			return;
		}
		return $current_data;
	}

	/**
	 * Get the custom theme.json file data.
	 *
	 * @return array
	 */
	public static function get_custom_file() {
		$custom_theme_json_file = self::SAVED_THEME_JSON_URL;
		if ( ! file_exists($custom_theme_json_file) || empty($custom_theme_json_file) ) {
			return;
		}
		return $custom_theme_json_file;
	}

	/**
	 * Clear the custom theme.json cache.
	 */
	public static function clear_custom_themejson() {
		//clean the cache
		\wp_clean_theme_json_cache();
	}

}
