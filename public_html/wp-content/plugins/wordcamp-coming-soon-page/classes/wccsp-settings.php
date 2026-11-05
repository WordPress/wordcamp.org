<?php

/*
 * todo
 * Now that using Customizer, this class isn't really needed and doesn't make sense. Its functions can be moved
 * into wordcamp-coming-soon-page.php and wccsp-customizer.php
 */

class WCCSP_Settings {
	protected $settings;
	public const REQUIRED_CAPABILITY = 'edit_theme_options';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu',                   array( $this, 'register_settings_pages' ) );
		add_action( 'init',                         array( $this, 'init' ) );
		add_action( 'update_option_wccsp_settings', array( $this, 'clear_static_page_cache' ) );
		add_action( 'update_option_wccsp_settings', array( $this, 'resync_posts_on_toggle' ), 10, 2 );
		add_filter( 'jetpack_sync_prevent_sending_post_data', array( $this, 'prevent_post_sync' ) );
	}

	/**
	 * Initializes variables
	 */
	public function init() {
		$this->settings = $this->get_settings();
	}

	/**
	 * Retrieves all of the settings from the database.
	 *
	 * @return array
	 */
	public function get_settings() {
		$defaults = array(
			'enabled'                    => 'off',        // So that sites created before the plugin was deployed won't display the home page when the plugin is activated.
			'body_background_color'      => '#0073AA',
			'image_id'                   => 0,
			'contact_form_page_id'       => 0,
			'background_id'              => 0,
			'container_background_color' => '#FFFFFF', // Deprecated.
			'text_color'                 => '#000000', // Deprecated.
			'introduction'               => '',
		);

		$settings = shortcode_atts(
			$defaults,
			get_option( 'wccsp_settings', array() )
		);

		return $settings;
	}

	/**
	 * Add a link to the Settings menu.
	 *
	 * Even though this lives in the Customizer, having a link in the regular admin menus helps with
	 * discoverability.
	 */
	public function register_settings_pages() {
		$menu_slug = WordCamp_Coming_Soon_Page::get_menu_slug();

		add_submenu_page(
			'options-general.php',
			__( 'Coming Soon', 'wordcamporg' ),
			__( 'Coming Soon', 'wordcamporg' ),
			self::REQUIRED_CAPABILITY,
			$menu_slug
		);
	}

	/**
	 * Keep post data out of Jetpack sync while the page is enabled.
	 *
	 * @param bool $prevent Whether Jetpack should withhold the post data.
	 *
	 * @return bool
	 */
	public function prevent_post_sync( $prevent ) {
		return $prevent || 'on' === $this->get_settings()['enabled'];
	}

	/**
	 * Re-sync posts and site details when the page is enabled or disabled, so synced copies match the new state.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $new_value New settings.
	 */
	public function resync_posts_on_toggle( $old_value, $new_value ) {
		$was_enabled = is_array( $old_value ) && 'on' === ( $old_value['enabled'] ?? '' );
		$is_enabled  = is_array( $new_value ) && 'on' === ( $new_value['enabled'] ?? '' );

		if ( $was_enabled === $is_enabled || ! class_exists( '\Automattic\Jetpack\Sync\Actions' ) ) {
			return;
		}

		\Automattic\Jetpack\Sync\Actions::do_full_sync(
			array(
				'posts'     => true,
				'functions' => true,
			)
		);
	}

	/**
	 * Clear the static page cache.
	 *
	 * Changing the settings will change the how the page looks, so the cache needs to be refreshed.
	 */
	public function clear_static_page_cache() {
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}

	/**
	 * Renders notices for the administrator when problems are detected
	 */
	public function render_admin_notices() {
		$active_modules            = Jetpack::$instance->get_active_modules();
		$inactive_required_modules = array();
		$required_modules          = array(
			'subscriptions' => __( 'Subscriptions', 'wordcamporg' ),
			'contact-form'  => __( 'Contact Form',  'wordcamporg' ),
		);

		foreach ( $required_modules as $module_id => $module_name ) {
			if ( ! in_array( $module_id, $active_modules ) ) {
				$inactive_required_modules[] = $module_name;
			}
		}

		ob_start();

		if ( $inactive_required_modules ) {
			require_once( dirname( __DIR__ ) . '/views/settings-admin-notices.php' );
		}

		return ob_get_clean();
	}
} // end WCCSP_Settings
