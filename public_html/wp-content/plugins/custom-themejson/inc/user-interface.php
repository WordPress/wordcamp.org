<?php
/**
 * Class UserInterface
 *
 * This class is responsible for adding the admin pages and rendering the options page.
 */

namespace WordCamp\CustomThemeJSON;

defined( 'WPINC' ) || die();

class UserInterface {
	/**
	 * UserInterface constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( get_called_class(), 'add_admin_pages' ) );
	}

	/**
	 * Add the admin pages.
	 */
	public static function add_admin_pages() {
		$page_hook = \add_submenu_page(
			'themes.php',
			__( 'Custom Theme JSON', 'wordcamporg' ),
			__( 'Custom Theme JSON', 'wordcamporg' ),
			'switch_themes',
			'custom-theme-json',
			array( get_called_class(), 'render_options_page' )
		);

		add_action( 'admin_print_styles-' . $page_hook, array( get_called_class(), 'print_css' ) );
		add_action( 'load-' . $page_hook, array( get_called_class(), 'add_contextual_help_tabs' ) );
	}

	/**
	 * Render the options page.
	 */
	public static function render_options_page() {
		// TODO: Implement the options page.
	}

	/**
	 * Print the CSS for the options page.
	 */
	public static function print_css() {
		// TODO: Implement the CSS.
	}

	/**
	 * Add contextual help tabs.
	 */
	public static function add_contextual_help_tabs() {
		// TODO: Implement the contextual help tabs.
	}
}
