<?php
/**
 * Autoloader
 *
 * @package    contributor-orientation-tool
 * @subpackage Core
 * @since      0.0.1
 * @author     Aleksandar Predic
 */

namespace WPCOTool;

/**
 * Class Autoloader
 *
 * @package WPCOTool
 */
class Autoloader {

	/**
	 * Handles autoloading of CommentIQ classes.
	 *
	 * @since 0.0.1
	 *
	 * @param string $class
	 */
	function autoload( $class_name ) {

		// Check our namespace and prevent other classes from autoload
		if ( 0 !== strpos( $class_name, 'WPCOTool' ) ) {
			return;
		}

		$fileName = wp_normalize_path( plugin_dir_path( dirname( __FILE__ ) ) . str_replace( '_', DIRECTORY_SEPARATOR, $class_name ) . '.php' );

		if ( is_file( $fileName ) ) {
			require $fileName;
		}

	}

	/**
	 * Registers contributor-orientation-tool_Autoloader as an SPL autoloader.
	 *
	 * @since 0.0.1
	 *
	 * @param bool $prepend
	 */
	public static function register( $prepend = false ) {
		if ( version_compare( phpversion(), '5.3.0', '>=' ) ) {
			spl_autoload_register( array( new self(), 'autoload' ), true, $prepend );
		} else {
			spl_autoload_register( array( new self(), 'autoload' ) );
		}
	}

}
