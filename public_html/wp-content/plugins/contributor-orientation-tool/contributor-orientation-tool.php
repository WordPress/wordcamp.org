<?php
/**
 * Plugin Name: Contributor orientation tool
 * Plugin URI: https://github.com/wceu/contributor-orientation-tool
 * Description: A WordPress plugin aiming to help new contributors decide which make team/s to contribute to or join at Contributor Day.
 * Version: 1.1.2
 * Author: Aleksandar Predic
 * Author URI: https://www.acapredic.com/
 * Tags: comments, spam
 * Requires at least: 5.0
 * Tested up to: 5.1
 * Requires PHP: 7.0
 * Stable tag: 1.1.2
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: contributor-orientation-tool
 * Domain Path: /languages
 * Network: true
 */

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct script access denied.' );
}

if ( ! class_exists( 'WPCOTool_Plugin' ) ) {
	// Setup class autoloader.
	require_once plugin_dir_path( __FILE__ ) . 'src/WPCOTool/Autoloader.php';
	\WPCOTool\Autoloader::register();

	// Load plugin.
	$contributor_orientation_tool_plugin = new \WPCOTool\Plugin( __FILE__ );
	add_action( 'plugins_loaded', array( $contributor_orientation_tool_plugin, 'load' ), 1 );
}
