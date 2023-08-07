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

use function WordCamp\AttendeeSurvey\Page\{addPage};

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugins_url( '/', __FILE__ ) );

const SKIP_KEY_ID = 'attendee_survey';
const OPTION_KEY  = 'attendee_survey_page';

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );

/**
 * Returns the option key used to store the survey page ID.
 *
 * @return string
 */
function get_option_key() {
	return OPTION_KEY;
}

/**
 * Check dependencies for loading the plugin.
 *
 * @return bool
 */
function can_load() {
	$skip_feature = wcorg_skip_feature( SKIP_KEY_ID );

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

	require_once get_includes_path() . 'cron.php';
	require_once get_includes_path() . 'page.php';

	if ( WORDCAMP_ROOT_BLOG_ID === get_current_blog_id() ) {
		require_once get_includes_path() . 'admin-page.php';
	}
}

/**
 * Create main survey page.
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
 * Remove the survey page.
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
 * Get the IDs of sites that do not have the SKIP_KEY_ID skip feature flag.
 *
 * @return array
 */
function get_site_ids_without_skip_flag() {
	global $wpdb;

	$blog_ids = $wpdb->get_col(
		$wpdb->prepare("
			SELECT b.blog_id
			FROM $wpdb->blogs AS b
			LEFT OUTER JOIN $wpdb->blogmeta AS m
			ON b.blog_id = m.blog_id AND m.meta_key = 'wordcamp_skip_feature' AND m.meta_value = %s
			WHERE m.meta_value IS NULL
			",
			SKIP_KEY_ID
		)
	);

	return array_map( 'absint', $blog_ids );
}

/**
 * Shortcut to the includes directory.
 *
 * @return string
 */
function get_includes_path() {
	return plugin_dir_path( __FILE__ ) . 'includes/';
}
