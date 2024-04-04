<?php
namespace WordCamp\CustomThemeJSON;

defined( 'WPINC' ) || die();

/**
 * Class UserInterface
 *
 * This class is responsible for adding the admin pages and rendering the options page.
 */
class UserInterface {

	const OPTION_NAME = 'wcctjsn-custom-themejson-url';

	/**
	 * UserInterface constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
	}

	/**
	 * Add the admin pages.
	 */
	public function add_admin_pages() {
		$page_hook = \add_submenu_page(
			'themes.php',
			__( 'Custom Theme JSON', 'wordcamporg' ),
			__( 'Custom Theme JSON', 'wordcamporg' ),
			'switch_themes',
			'custom-theme-json',
			array( $this, 'render_options_page' )
		);
	}

	/**
	 * Render the options page.
	 */
	public function render_options_page() {
		$notice               = null;
		$custom_themejson_url = \get_option( self::OPTION_NAME, '' );

		require_once dirname( __DIR__ ) . '/view/page-custom-themejson.php';
	}
}
