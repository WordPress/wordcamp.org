<?php

namespace WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/inc/events-query.php';
require_once __DIR__ . '/inc/city-landing-pages.php';

// Block files.
require_once __DIR__ . '/src/event-list/index.php';

add_action( 'after_setup_theme', __NAMESPACE__ . '\theme_support' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_filter( 'wporg_block_navigation_menus', __NAMESPACE__ . '\add_site_navigation_menus' );
add_filter( 'wporg_block_site_breadcrumbs', __NAMESPACE__ . '\update_site_breadcrumbs' );
add_filter( 'jetpack_open_graph_image_default', __NAMESPACE__ . '\filter_social_media_image' );
add_filter( 'jetpack_open_graph_tags', __NAMESPACE__ . '\add_meta_description' );
add_filter( 'jetpack_open_graph_output', __NAMESPACE__ . '\add_generic_meta_description' );


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
 * Replace the default social image.
 */
function filter_social_media_image() {
	return get_stylesheet_directory_uri() . '/images/social-image.png';
}

/**
 * Add the og:description via JetPack meta tags so it can be translated.
 */
function add_meta_description( $tags ) {
	$tags['og:description'] = __( 'Find upcoming WordPress events near you and around the world. Join your local WordPress community at a meetup or WordCamp, or learn how to organize an event for your city.', 'wporg' );
	return $tags;
}

/**
 * Use the og:description tag and output an additional meta description for SEO.
 *
 * Jetpack uses property="thing" as template for its Meta tags,
 * because it's built to output OG Tags. However, we we want to add a general tag here.
 *
 * @filter jetpack_open_graph_output
 * @uses str_replace
 */
function add_generic_meta_description( $og_tag ) {
	if ( false !== strpos( $og_tag, 'property="og:description"' ) ) {
		// Replace property="og:description" by name="description".
		$og_tag .= str_replace( 'property="og:description"', 'name="description"', $og_tag );
	}

	return $og_tag;
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
