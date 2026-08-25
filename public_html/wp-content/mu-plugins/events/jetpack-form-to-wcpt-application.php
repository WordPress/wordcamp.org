<?php
/**
 * Plugin Name: Jetpack Form to WPCC WCPT Application Integration
 */
namespace WordPress_Community\Applications\JetpackIntegration;
use WordPress_Community\Applications\WordCamp_Application;
use WordCamp\Logger;

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

	/*
	 * This form is a public, unauthenticated submission, so there is no signed-in
	 * user to attribute the application to. Bridged applications are always owned by
	 * the `wordcamp` service account, and the submitted username is retained only as
	 * data for the Community Team to review rather than used to set the owner.
	 */
	$service_account = 7694169;

	// Include the application processor, although we're not really using it here...
	require_once WP_PLUGIN_DIR . '/wcpt/wcpt-loader.php';

	// Map fields from Jetpack form to application fields.
	$application_data = [
		'Form URL' => admin_url( 'admin.php?page=jetpack-forms-admin' ) . '#/responses?r=' . $post_id,
	];

	foreach ( $fields as $field_id => $field ) {
		// `sanitize_textarea_field()` rather than the single-line variant, because the
		// record is rendered through `nl2br()` and some answers are multi-line.
		$application_data[ $field->attributes['label'] ?? $field_id ] = map_deep( $field->value, 'sanitize_textarea_field' );
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	/*
	 * The cap counts by post status, and this network runs `init` without ever
	 * registering the `wordcamp` statuses, so they have to be registered before the
	 * check or the query filters on statuses that cannot match and the cap never trips.
	 */
	$viewable = \WordCamp_Loader::get_publicly_viewable_post_statuses();

	foreach ( \WordCamp_Loader::get_post_statuses() as $status => $label ) {
		if ( ! get_post_status_object( $status ) ) {
			// `public` / `protected` to match how `Event_Loader::register_post_statuses()`
			// registers them on the central site. Left to the defaults these would come
			// out `internal`, which is a different status on one network to the other.
			$is_viewable = in_array( $status, $viewable, true );

			register_post_status(
				$status,
				array(
					'label'     => $label,
					'public'    => $is_viewable,
					'protected' => ! $is_viewable,
				)
			);
		}
	}

	/*
	 * Same 3-per-IP-per-hour cap the regular application form applies. It runs after the
	 * switch, because the tracker posts and the IP meta it counts both live on the
	 * central site.
	 */
	if ( ( new WordCamp_Application() )->is_rate_limited() ) {
		Logger\log( 'campus_connect_application_rate_limited', compact( 'post_id' ) );
		restore_current_blog();

		return;
	}

	$post = array(
		'post_type'   => 'wordcamp',
		'post_title'  => 'WordPress Campus Connect ' . ( $campus ?: trim( "$city, $country", ', ' ) ),
		'post_status' => WCPT_DEFAULT_STATUS,
		'post_author' => $service_account, // Public submission: owned by the service account.
	);

	$post_id = wp_insert_post( $post, true );
	if ( is_wp_error( $post_id ) ) {
		// Without this the rest of the request runs against the central site.
		restore_current_blog();

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
	add_post_meta( $post_id, 'WordPress.org Username', $wporg ?: '' );
	add_post_meta( $post_id, 'Venue Name', $campus );
	add_post_meta( $post_id, 'Physical Address', implode( "\n", array_filter( [ $campus, $city, $country ] ) ) );

	add_post_meta(
		$post_id,
		'_status_change',
		array(
			'timestamp' => time(),
			'user_id'   => 0, // Public submission: no signed-in user to attribute this to.
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
 * The form is public and unauthenticated, so the value is sanitised here rather than at
 * each `add_post_meta()` call, matching `validate_data()` in the other application
 * converters. Single-line, because these values are the components the callers build
 * `post_title` and the address from, not the multi-line answers.
 *
 * A field can be an array (a checkbox group whose label matches the needle), so those
 * are flattened the way the application metabox already displays them.
 *
 * @param array        $fields The fields submitted.
 * @param string|array $needles The needle to search for.
 *
 * @return string|false The sanitised field value if found, false otherwise.
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
				$value = $field->value ?? '';

				return sanitize_text_field( is_array( $value ) ? implode( ', ', $value ) : $value );
			}
		}
	}

	return false;
}
