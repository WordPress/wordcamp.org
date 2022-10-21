<?php

namespace WPCOTool\Admin;

/**
 * Class Settings
 * Manage plugin settings screens and admin menu
 * @package WPCOTool\Admin
 */
class Settings {

    /**
     * Plugin title
     */
    private $title;

    /**
     * Required capability
     * @var string
     */
    private $capability = 'manage_options';

	/**
	 * Admin menu slug
	 * @var string
	 */
	private $menu_slug;

	/**
	 * Prefix to use
	 * @var string
	 */
	private $prefix;

    /**
     * Settings constructor.
     *
     * @param string $prefix General prefix for plugin
     */
    public function __construct( string $prefix ) {

    	$this->prefix = $prefix;
        $this->title = esc_html__( 'Contributor orientation tool', 'contributor-orientation-tool' );
        $this->menu_slug = sprintf(
        	'%s-%s',
	        $this->prefix,
	        '-settings'
        );

        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );

    }

    /**
     * Add admin submenu page
     */
    public function add_menu_page() {

	    add_options_page(
	        sprintf(
	        	'%s %s',
		        $this->title,
		        esc_html__( 'settings', 'contributor-orientation-tool' )
	        ),
	        $this->title,
            $this->capability,
            $this->menu_slug,
            array( $this, 'settings' )
        );

    }

    /**
     * Add settings output
     */
    public function settings() {

        do_action( sprintf( '%s_settings_page', $this->prefix ) );

    }

}
