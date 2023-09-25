<?php

namespace WordPressdotorg\MU_Plugins\Global_Header_Footer;

use Rosetta_Sites, WP_Post, WP_REST_Server, WP_Theme_JSON_Resolver;

defined( 'WPINC' ) || die();

/* Actions & filters */
add_action( 'admin_bar_menu', __NAMESPACE__ . '\filter_admin_bar_links', 500 ); // 500 to run after all items are added to the menu.
add_filter( 'show_admin_bar', __NAMESPACE__ . '\should_show_admin_bar' );

/**
 * Hide the admin bar for logged out users.
 *
 * The admin bar can be shown to logged out users by activating the Logged Out Admin Bar plugin.
 *
 * @param bool $show_admin_bar Whether the admin bar should be shown.
 * @return bool
 */
function should_show_admin_bar( $show_admin_bar ) {
	return is_super_admin() || current_user_can( 'read' );
}

/**
 * Filter the admin bar links to simplify the frontend view.
 *
 * @param \WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance, passed by reference.
 */
function filter_admin_bar_links( $wp_admin_bar ) {
	// Only change the admin bar on the frontend.
	if ( is_admin() ) {
		return;
	}

	if ( is_user_logged_in() ) {
		// Remove all items in these submenus.
		$parent_remove_list = [ 'wp-logo-external', 'wp-logo', 'site-name', 'appearance' ];

		// Remove all these items (includes the top-level items from the parent list).
		$remove_list = array_merge(
			array_diff( $parent_remove_list, [ 'site-name' ] ),
			[ 'updates', 'stats', 'search', 'my-sites', 'admin-bar-likes-widget' ]
		);

		// Empty array so we can reverse it to keep the correct order.
		$edit_items = [];

		foreach ( $wp_admin_bar->get_nodes() as $ab_item ) {
			if ( in_array( $ab_item->parent, $parent_remove_list ) ) {
				$wp_admin_bar->remove_node( $ab_item->id );
			} else if ( in_array( $ab_item->id, $remove_list ) ) {
				$wp_admin_bar->remove_node( $ab_item->id );
			} else if ( 'my-sites' === $ab_item->parent ) {
				// Move items in "My Sites" into user's dropdown.
				$ab_item->parent = 'my-account';
				if ( empty( $ab_item->meta['class'] ) ) {
					$ab_item->meta['class'] = 'ab-sub-secondary';
				}
				$wp_admin_bar->add_node( $ab_item );
			} else if ( in_array( $ab_item->id, [ 'edit-profile', 'logout' ] ) ) {
				// Move "Edit Profile" and "Logout" to a new group at the end of the dropdown.
				$ab_item->parent = 'my-account-actions';
				$wp_admin_bar->add_node( $ab_item );
			} else if ( in_array( $ab_item->id, [ 'edit', 'site-editor', 'customize' ] ) ) {
				// Move "Edit [object]", "Customize", and "Edit Site" (if exist) to list to be
				// added to a new dropdown later.
				$ab_item->parent = 'edit-actions';
				$wp_admin_bar->remove_node( $ab_item->id );
				$edit_items[] = $ab_item;
			} else if ( preg_match( '/blog-\d+/', $ab_item->parent ) ) {
				$wp_admin_bar->remove_node( $ab_item->id );
			}
		}

		// Reverse the list to preserve order.
		$edit_items = array_reverse( $edit_items );
		if ( 1 === count( $edit_items ) ) {
			// Only one item, let it be the top level item.
			$edit_items[0]->parent = false;
			$wp_admin_bar->add_node( $edit_items[0] );
		} else if ( count( $edit_items ) > 1 ) {
			// If many, add a new top level "Edit" to hold them.
			$wp_admin_bar->add_node(
				array(
					'id' => 'edit-actions',
					'title' => $edit_items[0]->title,
					'parent' => false,
					'href' => $edit_items[0]->href,
				)
			);

			foreach ( $edit_items as $ab_item ) {
				$wp_admin_bar->add_node( $ab_item );
			}
		}

		// Add this group after all the manipulation so that it's at the end.
		$wp_admin_bar->add_node(
			array(
				'id' => 'my-account-actions',
				'title' => false,
				'parent' => 'my-account',
				'href' => false,
				'group' => true,
				'meta' => [ 'class' => 'ab-sub-secondary' ],
			)
		);
	} else {
		// Remove everything but Register & Log In
		foreach ( $wp_admin_bar->get_nodes() as $ab_item ) {
			if ( ! in_array( $ab_item->id, array( 'top-secondary', 'register', 'log-in' ) ) ) {
				$wp_admin_bar->remove_node( $ab_item->id );
			}
		}

		// Add log in link if it doesn't exist.
		$log_in = $wp_admin_bar->get_node( 'log-in' );
		if ( ! $log_in ) {
			$args = array(
				'id' => 'log-in',
				'title' => __( 'Log In', 'wporg' ),
				'parent' => 'top-secondary',
				'href' => wp_login_url(),
			);
			$wp_admin_bar->add_node( $args );
		}

		// Add register link if it doesn't exist.
		$register = $wp_admin_bar->get_node( 'register' );
		if ( ! $register ) {
			$args = array(
				'id' => 'register',
				'title' => __( 'Register', 'wporg' ),
				'parent' => 'top-secondary',
				'href' => wp_registration_url(),
			);
			$wp_admin_bar->add_node( $args );
		}
	}
}
