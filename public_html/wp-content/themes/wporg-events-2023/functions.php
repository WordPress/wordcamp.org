<?php

namespace WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/inc/events-query.php';
require_once __DIR__ . '/inc/city-landing-pages.php';

// Block files.
require_once __DIR__ . '/src/event-list/index.php';
require_once __DIR__ . '/src/post-meta/index.php';

add_action( 'after_setup_theme', __NAMESPACE__ . '\theme_support' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_filter( 'wporg_block_navigation_menus', __NAMESPACE__ . '\add_site_navigation_menus' );
add_filter( 'wporg_block_site_breadcrumbs', __NAMESPACE__ . '\update_site_breadcrumbs' );


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
				'label' => __( 'Upcoming events', 'wordcamporg' ),
				'url' => '/upcoming-events/',
			),
			array(
				'label' => __( 'Organize an event', 'wordcamporg' ),
				'url' => '/organize-an-event/',
			),
		),
	);
}

/**
 * Update the breadcrumbs to the current page.
 */
function update_site_breadcrumbs( $breadcrumbs ) {
	// Build up the breadcrumbs from scratch.
	$breadcrumbs = array(
		array(
			'url' => home_url(),
			'title' => __( 'Home', 'wporg' ),
		),
	);

	if ( is_search() ) {
		$breadcrumbs[] = array(
			'url' => false,
			'title' => __( 'Search results', 'wporg' ),
		);
		return $breadcrumbs;
	}

	return $breadcrumbs;
}
