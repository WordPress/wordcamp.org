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
    require_once __DIR__ . '/inc/ThemeJSON.php';

    // Override the theme.json file with custom settings.
    \WordCamp\CustomThemeJSON\ThemeJSON::override( __DIR__ . '/test/custom-theme.json');
 }


