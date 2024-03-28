<?php
/**
 * Plugin Name: Custom Theme JSON
 * Description: Customized theme.json, styles customize plugin
 * Author:      WordCamp Central
 * Author URI:  http://wordcamp.org
 * Version:     0.1
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Override_Theme_JSON
 */
class Override_Theme_JSON {
    /**
     * Constructor
     */
    public function __construct() {
        add_filter('wp_theme_json_data_theme', array(get_called_class(), 'set_custom_theme_json'));
    }

    public static function set_custom_theme_json($theme_json)
	{
        $url  = require_once( __DIR__ . '/custom-theme.json' );
		$json = file_get_contents($url); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$new_data = json_decode($json, true);
		return $theme_json->update_with($new_data);
	}
}

new Override_Theme_JSON();
