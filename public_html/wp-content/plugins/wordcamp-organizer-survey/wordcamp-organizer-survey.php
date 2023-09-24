<?php
/**
 * Plugin Name:     WordCamp Organizer Survey
 * Plugin URI:      https://wordcamp.org
 * Description:     Send survey to WordCamp organizers.
 * Author:          WordCamp.org
 * Author URI:      https://wordcamp.org
 * Version:         1
 *
 * @package         WordCamp\OrganizerSurvey
 */

namespace WordCamp\OrganizerSurvey;

use function WordCamp\OrganizerSurvey\DebriefSurvey\Email\add_email as add_debrief_survey_email;
use function WordCamp\OrganizerSurvey\DebriefSurvey\Email\delete_email as delete_debrief_survey_email;
use function WordCamp\OrganizerSurvey\DebriefSurvey\Email\get_encryption_token;
use function WordCamp\OrganizerSurvey\DebriefSurvey\Cron\delete_temp_attendee as delete_debrief_survey_temp_attendee;

defined( 'WPINC' ) || die();

/**
 * Local dependencies.
 */
require_once get_includes_path() . 'debrief-survey/email.php';

/**
 * Plugin deactivation hook.
 */
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );

/**
 * Actions & hooks
 */
add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );
add_action( 'template_redirect', __NAMESPACE__ . '\validate_token_on_debrief_survey' );

/**
 * Get the ID of the survey feature.
 */
function get_feature_id() {
	return 'organizer_survey';
}

/**
 * Include the rest of the plugin.
 *
 * @return void
 */
function load() {
	if ( is_wordcamp_type( 'next-gen' ) ) {
		// Debrief Survey.
		require_once get_includes_path() . 'debrief-survey/cron.php';

		add_action( 'init', __NAMESPACE__ . '\activate_on_current_site' );
	}
}

/**
 * The activation routine for a single site.
 *
 * @return void
 */
function activate_on_current_site() {
	// Debrief Survey.
	add_debrief_survey_email();
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
	$valid_sites = get_site_ids();

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
	// Debrief Survey.
	delete_debrief_survey_email();

	if ( is_wordcamp_type( 'next-gen' ) ) {
		// A temp attendee is only added in cron.
		delete_debrief_survey_temp_attendee();
	}
}

/**
 * Get the IDs of sites that do not have the FEATURE_ID skip feature flag.
 *
 * @return array
 */
function get_site_ids() {
	global $wpdb;

	$blog_ids = $wpdb->get_col(
		$wpdb->prepare("
			SELECT b.blog_id
			FROM $wpdb->blogs AS b
			LEFT OUTER JOIN $wpdb->blogmeta AS m
			ON b.blog_id = m.blog_id AND m.meta_value = %s
			",
			get_feature_id()
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

/**
 * Validate token on debrief survey page to check if it's an organizer visiting.
 */
function validate_token_on_debrief_survey() {
	if ( is_page( 'organizer-survey-event-debrief' ) ) {
		$token = $_GET['t'] ?? '';
		$email = $_GET['e'] ?? '';

		$expected_token = hash_hmac( 'sha1', base64_decode( $email ), ORGANIZER_SURVEY_ACCESS_TOKEN_KEY );

		if ( $token !== $expected_token ) {
			wp_die('Invalid access token.');
		}
	}
}
