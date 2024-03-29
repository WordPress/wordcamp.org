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
	 * Register new admin pages
	 */
	public static function add_admin_pages() {
		$page_hook = \add_submenu_page(
			'themes.php',
			__( 'Custom Theme JSON', 'wordcamporg' ),
			__( 'Custom Theme JSON', 'wordcamporg' ),
			'switch_themes',
			'custom-theme-json',
			__NAMESPACE__ . '\UserInterface::render_options_page'
		);

		add_action( 'admin_print_styles-' . $page_hook, __NAMESPACE__ . '\UserInterface::print_css' );
		add_action( 'load-'               . $page_hook, __NAMESPACE__ . '\UserInterface::add_contextual_help_tabs' );
	}
}
