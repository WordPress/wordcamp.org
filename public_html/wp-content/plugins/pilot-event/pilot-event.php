<?php
 /**
  * Plugin name:       WordCamp Pilot Events
  * Plugin URI:        https://github.com/WordPress/wordcamp.org
  * Description:       [Experimental] Creates the custom post type for Pilot WordCamp events.
  * Version:           0.1.0-beta
  * Author:            WordPress.org
  * Author URI:        http://wordpress.org/
  * License:           GPLv2 or later
  * Text Domain:       wordcamp
  */

namespace WordCamp\PilotEvents;

defined( 'WPINC' ) || die();

/**
 * Constants.
 */
define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Actions and filters.
 */
add_action( 'plugins_loaded', __NAMESPACE__ . '\load_files' );

/**
 * Load the other PHP files for the plugin.
 *
 * @return void
 */
function load_files() {
	require_once get_includes_path() . 'post-type.php';
}

/**
 * Shortcut to the includes directory.
 *
 * @return string
 */
function get_includes_path() {
	return PLUGIN_DIR . 'includes/';
}
