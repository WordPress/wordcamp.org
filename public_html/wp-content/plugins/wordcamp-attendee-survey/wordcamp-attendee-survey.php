<?php
/**
 * Plugin Name:     WordCamp Attendee Survey
 * Plugin URI:      https://wordcamp.org
 * Description:     Send survey to WordCamp attendees.
 * Author:          WordCamp.org
 * Author URI:      https://wordcamp.org
 * Version:         1
 *
 * @package         WordCamp\AttendeeSurvey
 */

namespace WordCamp\AttendeeSurvey;

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugins_url( '/', __FILE__ ) );

const OPTION_KEY = 'as_survey_page';

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );

/**
 * Check dependencies for loading the plugin.
 *
 * @return bool
 */
function can_load() {
	$skip_feature = wcorg_skip_feature( 'attendee_survey' );

	return ! $skip_feature;
}

/**
 * Include the rest of the plugin.
 *
 * @return void
 */
function load() {
	if ( ! can_load() ) {
		return;
	}

	// Check if the page exists, and add it if not.
	add_action( 'init', __NAMESPACE__ . '\add_page' );
}

/**
 * Create main Feedback page.
 *
 * @param bool $is_network True if activating network-wide.
 *
 * @return void
 */
function activate( $is_network = false ) {
	if ( $is_network ) {
		activate_on_network();
	} else {
		activate_on_current_site();
	}
}

/**
 * Run the activation routine on all valid sites in the network.
 *
 * @return void
 */
function activate_on_network() {
	$valid_sites = get_site_ids_without_skip_flag();

	foreach ( $valid_sites as $blog_id ) {
		switch_to_blog( $blog_id );
		activate_on_current_site();
		restore_current_blog();
	}
}

/**
 * The activation routine for a single site.
 *
 * @return void
 */
function activate_on_current_site() {
	add_page();

	// Flushing the rewrite rules is buggy in the context of `switch_to_blog`.
	// The rules will automatically get recreated on the next request to the site.
	delete_option( 'rewrite_rules' );
}

/**
 * Remove the feedback page.
 *
 * @param bool $is_network True if deactivating network-wide.
 *
 * @return void
 */
function deactivate( $is_network = false ) {
	if ( $is_network ) {
		deactivate_on_network();
	} else {
		deactivate_on_current_site();
	}
}

/**
 * Run the deactivation routine on all valid sites in the network.
 *
 * @return void
 */
function deactivate_on_network() {
	$valid_sites = get_site_ids_without_skip_flag();

	foreach ( $valid_sites as $blog_id ) {
		switch_to_blog( $blog_id );
		deactivate_on_current_site();
		restore_current_blog();
	}
}

/**
 * The deactivation routine for a single site.
 *
 * @return void
 */
function deactivate_on_current_site() {
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
function add_page() {
	$page_id = get_option( OPTION_KEY );
	if ( $page_id ) {
		return;
	}

	$content  = '<!-- wp:paragraph {"textColor":"white","customBackgroundColor":"#94240b"} -->';
	$content .= '<p style="background-color:#94240b" class="has-text-color has-background has-white-color">';
	$content .= __( 'Organizer Note: This page is used to display the session list for the Speaker Feedback form. It will be added after the content you enter here. You can remove this note.', 'wordcamporg' );
	$content .= '</p>';
	$content .= '<!-- /wp:paragraph -->';

	$content .= '<!-- wp:paragraph -->';
	$content .= '<p>';
	$content .= __( 'You can show your appreciation and contribute back to the community by leaving constructive feedback. This not only helps speakers know what worked in their presentation and what didn’t, but it helps organizers get a sense of how successful the event was as a whole.', 'wordcamporg' );
	$content .= '</p>';
	$content .= '<!-- /wp:paragraph -->';

	$content .= '<!-- wp:paragraph -->';
	$content .= '<p>';
	$content .= __( 'The feedback you give will only be shown to speakers and organizers.', 'wordcamporg' );
	$content .= '</p>';
	$content .= '<!-- /wp:paragraph -->';

	$page_id = wp_insert_post( array(
		'post_title'   => __( 'Attendee Survey', 'wordcamporg' ),
		/* translators: Page slug for the attendee survey. */
		'post_name'    => __( 'attendee survey', 'wordcamporg' ),
		'post_content' => $content,
		'post_status'  => 'draft',
		'post_type'    => 'page',
	) );

	if ( $page_id > 0 ) {
		update_option( OPTION_KEY, $page_id );
	}
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
 * Get the IDs of sites that do not have the `speaker_feedback` skip feature flag.
 *
 * @return array
 */
function get_site_ids_without_skip_flag() {
	global $wpdb;

	$blog_ids = $wpdb->get_col( "
		SELECT b.blog_id
		FROM $wpdb->blogs AS b
		LEFT OUTER JOIN $wpdb->blogmeta AS m
		ON b.blog_id = m.blog_id AND m.meta_key = 'wordcamp_skip_feature' AND m.meta_value = 'speaker_feedback'
		WHERE m.meta_value IS NULL
	" );

	return array_map( 'absint', $blog_ids );
}
