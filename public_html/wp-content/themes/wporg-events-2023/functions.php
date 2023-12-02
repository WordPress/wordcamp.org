<?php

namespace WordPressdotorg\Events_2023;
defined( 'WPINC' ) || die();

require_once __DIR__ . '/inc/city-landing-pages.php';

// Block files
require_once __DIR__ . '/src/event-list/index.php';

add_action( 'after_setup_theme', __NAMESPACE__ . '\theme_support' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_filter( 'wporg_block_navigation_menus', __NAMESPACE__ . '\add_site_navigation_menus' );
add_filter( 'wporg_query_filter_options_map_type', __NAMESPACE__ . '\get_map_type_options' );
add_filter( 'query_vars', __NAMESPACE__ . '\add_query_vars' );

/**
 * Register theme supports.
 */
function theme_support() {
	add_editor_style( 'editor.css' );
}

/**
 * Enqueue scripts and styles.
 */
function enqueue_assets() {
	wp_enqueue_style(
		'wporg-events-2023-style',
		get_stylesheet_uri(),
		array( 'wporg-parent-2021-style', 'wporg-global-fonts' ),
		filemtime( __DIR__ . '/style.css' )
	);
}

/**
 * Provide a list of local navigation menus.
 */
function add_site_navigation_menus( $menus ) {
	return array(
		'local-navigation' => array(
			array(
				'label' => __( 'All Events', 'wordcamporg' ),
				'url' => '/upcoming-events/',
			),
			array(
				'label' => __( 'Organize an Event', 'wordcamporg' ),
				'url' => '/organize-an-event/',
			),
		),
	);
}

// Setup the filters
function get_map_type_options( array $options ): array {
	global $wp_query;
	$selected = isset( $wp_query->query['map_type'] ) ? (array) $wp_query->query['map_type'] : array();
	$count = count( $selected );
	$label = sprintf(
		/* translators: The dropdown label for filtering, %s is the selected term count. */
		_n( 'Type <span>%s</span>', 'Type <span>%s</span>', $count, 'wporg' ),
		$count
	);

	return array(
		'label' => $label,
		'title' => __( 'Type', 'wporg' ),
		'key' => 'map_type',
		'action' => get_permalink(),
		'options' => array(
			'meetup'   => 'Meetup',
			'wordcamp' => 'WordCamp',
		),
		'selected' => $selected,
	);
}

function add_query_vars($query_vars) {
	$query_vars[] = 'map_type';
    return $query_vars;
}
