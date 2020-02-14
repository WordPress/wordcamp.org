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
define( __NAMESPACE__ . '\QUERY_VAR', 'sft_feedback' );

// Only add actions to sites without the skip flag, and only if WC Post Types exist.
if ( ! wcorg_skip_feature('speaker_feedback' ) && class_exists( 'WordCamp_Post_Types_Plugin' ) ) {
	register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
	register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );

	add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );
	add_action( 'init', __NAMESPACE__ . '\add_page_endpoint' );

	// Check if the page exists, and add it if not.
	add_action( 'init', __NAMESPACE__ . '\add_feedback_page' );
}

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
	add_page_endpoint();
	flush_rewrite_rules();
}

/**
 * Create the Feedback page, save ID into an option.
 */
function add_feedback_page() {
	$page_id = get_option( OPTION_KEY );
	if ( $page_id ) {
		return;
	}

	$organizer_note = '<!-- wp:paragraph {"textColor":"white","customBackgroundColor":"#94240b"} -->';
	$organizer_note .= '<p style="background-color:#94240b" class="has-text-color has-background has-white-color">';
	$organizer_note .= __( 'This page is a placeholder for the Speaker Feedback form. The content here will not be shown on the site.', 'wordcamporg' );
	$organizer_note .= '</p>';
	$organizer_note .= '<!-- /wp:paragraph -->';

	$page_id = wp_insert_post( array(
		'post_title'   => __( 'Leave Feedback', 'wordcamporg' ),
		/* translators: Page slug for the feedback page. */
		'post_name'    => __( 'feedback', 'wordcamporg' ),
		'post_content' => $organizer_note,
		'post_status'  => 'publish',
		'post_type'    => 'page',
	) );

	if ( $page_id > 0 ) {
		update_option( OPTION_KEY, $page_id );
	}
}

/**
 * Register a rewrite endpoint for the API.
 */
function add_page_endpoint() {
	// Uses EP_SESSIONS mask to only add this endpoint to the session.
	add_rewrite_endpoint( 'feedback', EP_SESSIONS, QUERY_VAR );
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
