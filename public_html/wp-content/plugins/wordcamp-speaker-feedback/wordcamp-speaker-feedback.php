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

define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugins_url( '/', __FILE__ ) );

const OPTION_KEY           = 'sft_feedback_page';
const QUERY_VAR            = 'sft_feedback';
const SUPPORTED_POST_TYPES = array( 'wcb_session' );

// Only add actions to sites without the skip flag, and only if WC Post Types exist.
if ( ! wcorg_skip_feature( 'speaker_feedback' ) && class_exists( 'WordCamp_Post_Types_Plugin' ) ) {
	register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
	register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );

	add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );
	add_action( 'init', __NAMESPACE__ . '\add_support', 99 );
	add_action( 'init', __NAMESPACE__ . '\add_page_endpoint' );
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_rest_routes', 100 );

	// Check if the page exists, and add it if not.
	add_action( 'init', __NAMESPACE__ . '\add_feedback_page' );
}

/**
 * Include the rest of the plugin.
 *
 * @return void
 */
function load() {
	require_once get_includes_path() . 'class-feedback.php';
	require_once get_includes_path() . 'class-rest-feedback-controller.php';
	require_once get_includes_path() . 'class-walker-feedback.php';
	require_once get_includes_path() . 'cron.php';
	require_once get_includes_path() . 'capabilities.php';
	require_once get_includes_path() . 'comment.php';
	require_once get_includes_path() . 'comment-meta.php';
	require_once get_includes_path() . 'page.php';
	require_once get_includes_path() . 'post.php';
	require_once get_includes_path() . 'query.php';
	require_once get_includes_path() . 'spam.php';
	require_once get_includes_path() . 'view.php';

	require_once get_includes_path() . 'admin.php';
}

/**
 * Create main Feedback page.
 *
 * @return void
 */
function activate() {
	add_feedback_page();
	add_page_endpoint();
	flush_rewrite_rules();
}

/**
 * Remove the feedback page.
 *
 * @return void
 */
function deactivate() {
	$page_id = get_option( OPTION_KEY );
	wp_delete_post( $page_id, true );
}

/**
 * Add post type support for supported post types. Only for the types that are supported though.
 *
 * This makes it easy to check if a particular post type can have feedback comments using Core functionality, rather
 * than having to import a namespaced constant or function.
 *
 * @return void
 */
function add_support() {
	foreach ( SUPPORTED_POST_TYPES as $post_type ) {
		add_post_type_support( $post_type, 'wordcamp-speaker-feedback' );
	}
}

/**
 * Create the Feedback page, save ID into an option.
 *
 * @return void
 */
function add_feedback_page() {
	$page_id = get_option( OPTION_KEY );
	if ( $page_id ) {
		return;
	}

	$organizer_note  = '<!-- wp:paragraph {"textColor":"white","customBackgroundColor":"#94240b"} -->';
	$organizer_note .= '<p style="background-color:#94240b" class="has-text-color has-background has-white-color">';
	$organizer_note .= __( 'Organizer Note: This page is used to display the session list for Speaker Feedback form. It will be added after the content you enter here. You can remove this note.', 'wordcamporg' );
	$organizer_note .= '</p>';
	$organizer_note .= '<!-- /wp:paragraph -->';

	$organizer_note .= '<!-- wp:paragraph -->';
	$organizer_note .= '<p>';
	$organizer_note .= __( 'You can show your appreciation and contribute back to the community by leaving constructive feedback. This not only helps speakers know what worked in their presentation and what didn’t, but it helps organizers get a sense of how successful the event was as a whole.', 'wordcamporg' );
	$organizer_note .= '</p>';
	$organizer_note .= '<!-- /wp:paragraph -->';

	$organizer_note .= '<!-- wp:paragraph -->';
	$organizer_note .= '<p>';
	$organizer_note .= __( 'The feedback you give will only be shown to speakers & organizers.', 'wordcamporg' );
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
 *
 * @return void
 */
function add_page_endpoint() {
	// Uses EP_SESSIONS mask to only add this endpoint to the session.
	add_rewrite_endpoint( 'feedback', EP_SESSIONS, QUERY_VAR );
}

/**
 * Initialize REST API endpoints.
 *
 * @return void
 */
function register_rest_routes() {
	$controller = new REST_Feedback_Controller();
	$controller->register_routes();
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
 * Shortcut to the assets directory.
 *
 * @return string
 */
function get_assets_path() {
	return plugin_dir_path( __FILE__ ) . 'assets/';
}

/**
 * Shortcut to the assets URL.
 *
 * @return string
 */
function get_assets_url() {
	return plugin_dir_url( __FILE__ ) . 'assets/';
}
