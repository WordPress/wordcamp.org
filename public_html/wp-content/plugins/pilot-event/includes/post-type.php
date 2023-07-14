<?php

namespace WordCamp\PilotEvents\PostType;

defined( 'WPINC' ) || die();

/**
 * Constants.
 */
define( 'WCPT_PILOT_EVENT_SLUG', 'pilot_event' );

/**
 * Actions and filters.
 */
add_action( 'init', __NAMESPACE__ . '\create_pilot_event_post_type' );

/**
 * Register the custom post type for Pilot WordCamp events.
 *
 * @return void
 */
function create_pilot_event_post_type() {
	$labels = array(
		'name'               => 'Pilot Events',
		'singular_name'      => 'Pilot Event',
		'menu_name'          => 'Pilot Events',
		'name_admin_bar'     => 'Pilot Event',
		'add_new'            => 'Add New',
		'add_new_item'       => 'Add New Pilot Event',
		'new_item'           => 'New Pilot Event',
		'edit_item'          => 'Edit Pilot Event',
		'view_item'          => 'View Pilot Event',
		'all_items'          => 'All Pilot Events',
		'search_items'       => 'Search Pilot Events',
		'parent_item_colon'  => 'Parent Pilot Events:',
		'not_found'          => 'No pilot events found.',
		'not_found_in_trash' => 'No pilot events found in Trash.',
	);

	$args = array(
		'labels'             => $labels,
		'menu_icon'          => 'dashicons-wordpress',
		'public'             => true,
		'publicly_queryable' => true,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'show_in_rest'       => true,
		'query_var'          => true,
		'rewrite'            => array( 'slug' => 'pilot-event' ),
		'capability_type'    => 'post',
		'has_archive'        => true,
		'hierarchical'       => false,
		'menu_position'      => null,
		'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		'menu_icon'             => 'dashicons-wordpress',
	);

	register_post_type( WCPT_PILOT_EVENT_SLUG, $args );
}
