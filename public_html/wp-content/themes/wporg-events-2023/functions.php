<?php

namespace WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

require_once __DIR__ . '/inc/event-getters.php';

add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );


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
