<?php
/**
 * Plugin Name: Jetpack Form to WPCC WCPT Application Integration
 */
namespace WordPress_Community\Applications\JetpackIntegration;
use WordPress_Community\Applications\WordCamp_Application;

/**
 * This mu-plugin operates on the Events.wordpress.org network, targetting the Jetpack Forms on
 * that main site, and turning them into WCPT applications as needed.
 */
if ( ! defined( 'EVENTS_ROOT_BLOG_ID' ) || EVENTS_ROOT_BLOG_ID !== get_current_blog_id() ) {
	return;
}

add_action( 'grunion_after_feedback_post_inserted', __NAMESPACE__ . '\grunion_after_feedback_post_inserted', 10, 4 );

/**
 * Handle a Jetpack form submission, and turn it into a WCPT submission if appropriate.
 *
 * @param int   $post_id      The post ID of the feedback post created.
 * @param array $fields       The fields submitted.
 * @param bool  $is_spam      Whether the submission was marked as spam.
 * @param array $entry_values The raw entry values.
 */
function grunion_after_feedback_post_inserted( $post_id, $fields, $is_spam, $entry_values ) {
	if ( $is_spam ) {
		return;
	}

	switch ( $entry_values['entry_permalink'] ?? '' ) {
		// The Campus Connect Organize form.
		case 'https://events.wordpress.org/campusconnect/organize/':
			create_campus_connect_tracker( $post_id, $fields, $is_spam, $entry_values );
			break;
		default:
			return;
	}
}

/**
 * Create a Campus Connect application tracker entry.
 *
 * @param int   $post_id      The post ID of the feedback post created.
 * @param array $fields       The fields submitted.
 * @param bool  $is_spam      Whether the submission was marked as spam.
 * @param array $entry_values The raw entry values.
 */
function create_campus_connect_tracker( $post_id, $fields, $is_spam, $entry_values ) {

	$name      = find_first_field_matching_label( $fields, 'Name' );
	$email     = find_first_field_matching_label( $fields, 'Email' );
	$wporg     = find_first_field_matching_label( $fields, 'Username' );
	$campus    = find_first_field_matching_label( $fields, 'Campus' );
	$city      = find_first_field_matching_label( $fields, 'City' );
	$country   = find_first_field_matching_label( $fields, 'Country' );
	$date      = find_first_field_matching_label( $fields, 'Date' );
	$attendees = find_first_field_matching_label( $fields, 'Number of Attendees' );

	// Fetch the user by WP.org username or email.
	$user      = $wporg && wcorg_get_user_by_canonical_names( $wporg ) ? wcorg_get_user_by_canonical_names( $wporg ) : ( $email ? get_user_by( 'email', $email ) : false );

	// Include the application processor, although we're not really using it here...
	require_once WP_PLUGIN_DIR . '/wcpt/wcpt-loader.php';

	// Map fields from Jetpack form to application fields.
	$application_data = [
		'Form URL' => admin_url( 'admin.php?page=jetpack-forms-admin' ) . '#/responses?r=' . $post_id,
	];

	foreach ( $fields as $field_id => $field ) {
		$application_data[ $field->attributes['label'] ?? $field_id ] = $field->value;
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	$post = array(
		'post_type'   => 'wordcamp',
		'post_title'  => 'WordPress Campus Connect ' . ( $campus ?: trim( "$city, $country", ', ' ) ),
		'post_status' => WCPT_DEFAULT_STATUS,
		'post_author' => $user->ID ?? 7694169, // Set `wordcamp` as author if supplied username is not valid.
	);

	$post_id = wp_insert_post( $post, true );
	if ( is_wp_error( $post_id ) ) {
		return;
	}

	// Metadata, These match what's used by the WordCamp application type.
	add_post_meta( $post_id, '_application_data', $application_data );
	add_post_meta( $post_id, '_application_submitter_ip_address', $_SERVER['REMOTE_ADDR'] );
	add_post_meta( $post_id, 'event_subtype', 'campusconnect' );
	add_post_meta( $post_id, 'Organizer Name', $name );
	add_post_meta( $post_id, 'Email Address', $email ); // Lead organizer.
	add_post_meta( $post_id, 'Location', trim( "$city, $country", ', ' ) );
	add_post_meta( $post_id, 'Start Date (YYYY-mm-dd)', strtotime( $date ) );
	add_post_meta( $post_id, 'Number of Anticipated Attendees', $attendees );
	add_post_meta( $post_id, 'WordPress.org Username', $wporg ?: ( $user->user_login ?? '' ) );
	add_post_meta( $post_id, 'Venue Name', $campus );
	add_post_meta( $post_id, 'Physical Address', implode( "\n", array_filter( [ $campus, $city, $country ] ) ) );

	add_post_meta(
		$post_id,
		'_status_change',
		array(
			'timestamp' => time(),
			'user_id'   => is_a( $user, 'WP_User' ) ? $user->ID : 0,
			'message'   => sprintf( '%s &rarr; %s', 'Application', \WordCamp_Loader::get_post_statuses()[ WCPT_DEFAULT_STATUS ] ),
		)
	);

	$edit_link = add_query_arg(
		[
			'post'   => $post_id,
			'action' => 'edit',
		],
		admin_url( 'post.php' )
	);

	restore_current_blog();

	// Suffix the edit url to the contact form.
	add_filter( 'contact_form_message', function ( $message ) use ( $edit_link ) {
		$message .= '<br><strong>Internal details for the Community Team</strong><br>';
		$message .= '<br><strong>Tracker URL:</strong> ' . $edit_link;

		return $message;
	} );
}

/**
 * Find the first field matching a given label.
 *
 * @param array        $fields The fields submitted.
 * @param string|array $needles The needle to search for.
 *
 * @return mixed The field value if found, false otherwise.
 */
function find_first_field_matching_label( $fields, $needles ) {
	// If the needle has uppercase letters, also search for the lowercase version (but secondly).
	if ( is_string( $needles ) && preg_match( '/[A-Z]/', $needles ) ) {
		$needles = [ $needles, strtolower( $needles ) ];
	}

	// Check for a field containing the needle in the CSS class.
	foreach ( (array) $needles as $needle ) {
		foreach ( $fields as $field ) {
			if ( str_contains( $field->attributes['label'], $needle ) ) {
				return $field->value ?? '';
			}
		}
	}

	return false;
}
