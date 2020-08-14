<?php

/**
 * WCPT_Admin
 *
 * Loads plugin admin area.
 *
 * @package WordCamp Post Type
 * @subpackage Admin
 * @since WordCamp Post Type (0.1)
 */
class WCPT_Admin {
	/**
	 * Initialize WCPT Admin
	 */
	public function __construct() {
		// Attach the WordCamp Post Type admin init action to the WordPress admin init action.
		add_action( 'admin_init',               array( $this, 'init' ) );

		// Add some general styling to the admin area.
		add_action( 'admin_head',               array( $this, 'admin_head' ) );

		// Add separator to admin menu.
		add_action( 'custom_menu_order',        array( $this, 'admin_custom_menu_order' ) );
		add_action( 'menu_order',               array( $this, 'admin_menu_order'        ) );
		add_filter( 'post_row_actions',			array( $this, 'add_post_row_actions' ), 10, 2 );

		// todo add custom hover link to go to "event website"
	}

	/**
	 * WordCamp Post Type's dedicated admin init action.
	 *
	 * @uses do_action
	 */
	public function init() {
		do_action( 'wcpt_admin_init' );
	}

	/**
	 * Add the metabox.
	 *
	 * @uses add_meta_box
	 */
	public function metabox() {
		do_action( 'wcpt_metabox' );
	}

	/**
	 * Add some general styling to the admin area.
	 */
	public function admin_head() {
		?>

		<style type="text/css" media="screen">
			/*<![CDATA[*/
			<?php
				// Add extra actions to WordCamp Post Type admin header area
				do_action( 'wcpt_admin_head' );
			?>
			/*]]>*/
		</style>

		<?php
	}

	/**
	 * Tell WordPress we have a custom menu order.
	 *
	 * @param bool $menu_order Menu order.
	 *
	 * @return bool Always true
	 */
	public function admin_custom_menu_order( $menu_order ) {
		return true;
	}

	/**
	 * Move our custom separator above our custom post types.
	 *
	 * @param array $menu_order Menu Order.
	 *
	 * @uses bbp_get_forum_post_type() To get the forum post type.
	 *
	 * @return array Modified menu order
	 */
	public function admin_menu_order( $menu_order ) {
		global $menu;

		$menu[] = array( '', 'read', 'separator-wcpt', '', 'wp-menu-separator' );

		// Initialize our custom order array.
		$wcpt_menu_order = array();

		// Get the index of our custom separator.
		$wcpt_separator = array_search( 'separator-wcpt', $menu_order );

		// Loop through menu order and do some rearranging.
		foreach ( $menu_order as $index => $item ) {
			// Current item is our forum CPT, so set our separator here.
			if ( ( ( 'edit.php?post_type=' . WCPT_POST_TYPE_ID ) === $item ) ) {
				$wcpt_menu_order[] = 'separator-wcpt';
				unset( $menu_order[ $wcpt_separator ] );
			}

			// Skip our separator.
			if ( ! in_array( $item, array( 'separator-wcpt' ) ) ) {
				$wcpt_menu_order[] = $item;
			}
		}

		// Return our custom order.
		return $wcpt_menu_order;
	}

	public function add_post_row_actions( $actions, $post ) {
		$actions['view-wordcamp-site'] = 'View WordCamp site';

		return $actions;
	}
}
