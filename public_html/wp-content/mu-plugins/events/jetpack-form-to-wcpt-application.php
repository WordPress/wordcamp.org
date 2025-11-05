<?php
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
	switch ( $entry_values['entry_permalink'] ?? '' ) {
		// The Campus Connect Organize form.
		case 'https://events.wordpress.org/campusconnect/organize/':
			$data = get_campusconnect_field_mapping( $fields );
			break;
		default:
			return;
	}

	// Map fields from Jetpack form to application fields.
	$application_data = [
		'Form URL' => admin_url( 'admin.php?page=jetpack-forms-admin' ) . '#/responses?r=' . $post_id,
	];
	if ( $is_spam ) {
		$application_data['Jetpack Marked as Spam'] = 'Yes';
	}
	foreach ( $fields as $field ) {
		$application_data[ $field->attributes['label'] ] = $field->value;
	}

	// Include the application processor.
	require_once WP_PLUGIN_DIR . '/wcpt/wcpt-loader.php';

	$application = new WordCamp_Application();

	$sanitized_data = $application->validate_data( $data );
	if ( is_wp_error( $sanitized_data ) ) {
		return;
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	$post_id = $application->create_post( $sanitized_data );
	if ( ! $post_id || is_wp_error( $post_id ) ) {
		restore_current_blog();
		return;
	}

	if ( ! empty( $data['post_title'] ) ) {
		wp_update_post( [
			'ID'         => $post_id,
			'post_title' => $data['post_title'],
		] );
	}
	if ( ! empty( $data['event_subtype'] ) ) {
		add_post_meta( $post_id, 'event_subtype', $data['event_subtype'] );
	}

	update_post_meta( $post_id, '_application_data', $application_data );

	$edit_link = add_query_arg( 
		[
			'post'   => $post_id,
			'action' => 'edit',
		],
		admin_url( 'post.php' )
	);

	restore_current_blog();

	// Suffix the edit url to the contact form.
	add_filter( 'contact_form_message', function( $message ) use ( $edit_link ) {
		$message .= "<br><strong>Internal details for the Community Team</strong><br>";
		$message .= "<br><strong>Tracker URL:</strong> " . $edit_link;

		return $message;
	} );

}

/**
 * Get the field mapping for Organize a CampusConnect form.
 *
 * This should match the mapping that's present within the form itself,
 * AND within plugins/wcpt/wcpt-wordcamp/class-wordcamp-application.php
 *
 * Not all fields need to be mapped here, only the ones that the WordCamp
 * Application cares about.
 *
 * @return array The field mapping.
 */
function get_campusconnect_field_mapping( $fields ) {
	return [
		'event_subtype'                => 'campusconnect',
		'post_title'                   => 'WordPress Campus Connect ' . ( $fields['g16153-campusname']->value ?: ( ( $fields['g16153-eventcity']->value ?? '' ) . ', ' . ( $fields['g16153-eventcountry']->value ?? '' ) ) ),

		'q_1079074_first_name'         => explode( ' ', $fields['g16153-fullname']->value, 2 )[0],
		'q_1079074_last_name'          => explode( ' ', $fields['g16153-fullname']->value, 2 )[1] ?? '',

		'q_1079059_email'              => $fields['g16153-emailaddress']->value ?? '',
		'q_4236565_wporg_username'     => $fields['g16153-wordpress-orgusername']->value ?? '',
		'q_1079103_wordcamp_location'  => ( $fields['g16153-eventcity']->value ?? '' ) . ', ' . ( $fields['g16153-eventcountry']->value ?? '' ),
		'q_1046007_how_many_attendees' => $fields['g16153-approximatenumberofattendees']->value ?? '',
	];
}