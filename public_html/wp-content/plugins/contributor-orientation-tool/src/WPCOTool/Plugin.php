<?php
/**
 * contributor-orientation-tool plugin
 *
 * @package    contributor-orientation-tool
 * @subpackage Core
 * @since      0.0.1
 * @author     Aleksandar Predic
 */

namespace WPCOTool;

use WPCOTool\Admin\Options;
use WPCOTool\Admin\Settings;
use WPCOTool\Frontend\Shortcode;

/**
 * Class Plugin
 *
 * @package WPCOTool
 */
class Plugin {

	/**
	 * Flag to track if the plugin is loaded
	 *
	 * @since    0.0.1
	 * @access   private
	 * @var bool
	 */
	private $loaded;

	/**
	 * Plugin version.
	 *
	 * @since    0.0.1
	 * @access   public
	 * @var string
	 */
	public $version = '1.1.2';

	/**
	 * Absolute path to the directory where WordPress installed the plugin with the trailing slash
	 *
	 * @since   0.0.1
	 * @access   private
	 * @var string
	 */
	private $plugin_path;

	/**
	 * URL to the directory where WordPress installed the plugin with the trailing slash
	 *
	 * @since   0.0.1
	 * @access   private
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Prefix to use for all we need
	 * @var string
	 */
	private $prefix = 'wpcot';

	/**
	 * Constructor.
	 *
	 * @access public
	 * @since 0.0.1
	 *
	 * @param string $file
	 */
	public function __construct( string $file ) {
		$this->loaded      = false;
		$this->plugin_path = plugin_dir_path( $file );
		$this->plugin_url  = plugin_dir_url( $file );
	}

	/**
	 * Checks if the plugin is loaded.
	 *
	 * @access public
	 * @since 0.0.1
	 * @return bool
	 */
	public function is_loaded() {
		return $this->loaded;
	}

	/**
	 * Loads the plugin into WordPress and add all needed hooks.
	 *
	 * @access public
	 * @since 0.0.1
	 */
	public function load() {

		if ( $this->is_loaded() ) {
			return;
		}

		/*
		 * Add actions sorted via components we are adding trought plugin
		 * All hooks are going to be added via class __construct method to make plugin modular
		 */

		if ( ! is_admin() ) {

			/**
			 * Add shortcode
			 */
			new Shortcode( $this->version, $this->prefix );

		}

		if ( is_admin() ) {

			/**
			 * Add submenu page
			 */
			new Settings( $this->prefix );

			/**
			 * Add options
			 */
			$admin_page_options = new Options( $this->prefix );
			$admin_page_options->init_admin_form();

		}


		// Set all as loaded.
		$this->loaded = true;

	}

	/**
	 * Return asset url for the plugin.
	 *
	 * @access public
	 * @since 0.0.1
	 *
	 * @param string $file File relative to assets dir
	 *
	 * @return string
	 */
	public static function assets_url( $file ) {

		return plugins_url(
			sprintf( 'assets/%s',
				sanitize_text_field( $file )
			),
			__FILE__
		);

	}

	/**
	 * Return configuration array used for form logic
	 * @param static $file Filename
	 *
	 * @return array
	 */
	public static function get_form_config( $file ) {

		return require_once plugin_dir_path( __FILE__ ) . sprintf( 'config/%s', $file );

	}

	/**
	 * Fired during plugin activation.
	 *
	 * @access public
	 * @since 0.0.1
	 */
	public function activation() {

	}
}
