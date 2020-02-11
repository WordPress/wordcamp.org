<?php
/**
 * Plugin Name:     WordCamp Speaker Feedback
 * Plugin URI:      https://wordcamp.org
 * Description:     Tools to provide feedback to speakers at WordCamp events.
 * Author:          WordCamp.org
 * Author URI:      https://wordcamp.org
 * Version:         1
 *
 * @package         WordCamp\SpeakerFeedback
 */

namespace WordCamp\SpeakerFeedback;

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', \plugins_url( '/', __FILE__ ) );
define( __NAMESPACE__ . '\OPTION_KEY', 'sft_feedback_page' );

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );

/**
 * Include the rest of the plugin.
 */
function load() {
	require_once PLUGIN_DIR . 'includes/class-feedback.php';
	require_once PLUGIN_DIR . 'includes/comment.php';
	require_once PLUGIN_DIR . 'includes/form.php';
	require_once PLUGIN_DIR . 'includes/page.php';
}

/**
 * Create main Feedback page.
 */
function activate() {
	add_feedback_page();
}

/**
 * Create the Feedback page, save ID into an option.
 */
function add_feedback_page() {
	$page_id = wp_insert_post( array(
		'post_title'  => __( 'Leave Feedback', 'wordcamporg' ),
		/* translators: Page slug for the feedback page. */
		'post_name'   => __( 'feedback', 'wordcamporg' ),
		'post_status' => 'publish',
		'post_type'   => 'page',
	) );
	if ( $page_id > 0 ) {
		update_option( OPTION_KEY, $page_id );
	}
}

/**
 * Remove the feedback page.
 */
function deactivate() {
	$page_id = get_option( OPTION_KEY );
	wp_delete_post( $page_id, true );
}

/**
 * Shortcut to the includes directory.
 *
 * @return string
 */
function get_includes_path() {
	return plugin_dir_path( __FILE__ ) . 'includes/';
}

/**
 * Shortcut to the views directory.
 *
 * @return string
 */
function get_views_path() {
	return plugin_dir_path( __FILE__ ) . 'views/';
}

/**
 * Shortcut to the assets URL.
 *
 * @return string
 */
function get_assets_url() {
	return plugin_dir_url( __FILE__ ) . 'assets/';
}
