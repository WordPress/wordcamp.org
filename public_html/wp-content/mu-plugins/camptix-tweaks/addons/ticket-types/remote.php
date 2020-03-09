<?php

namespace WordCamp\CampTix_Tweaks\Ticket_Types\Remote;
use function WordCamp\CampTix_Tweaks\Ticket_Types\get_type_slug;
defined( 'WPINC' ) || die();

const SLUG = 'remote';

add_filter( 'camptix_ticket_types', __NAMESPACE__ . '\add_type' );
add_filter( 'camptix_metabox_questions_default_fields_list', __NAMESPACE__ . '\modify_default_fields_list' );

/**
 * Add this type to the available ticket types.
 */
function add_type( $types ) {
	$types[] = array(
		'slug' => SLUG,
		'name' => __( 'Remote/Livestream', 'wordcamporg' ),
	);
	return $types;
}

/**
 * Modify the list of default questions on the ticket registration form.
 *
 * @param string $default_fields
 * @return string
 */
function modify_default_fields_list( $default_fields ) {
	if ( SLUG === get_type_slug( get_the_ID() ) ) {
		return __( 'Top three fields: First name, last name, e-mail address.<br />Bottom three fields: Attendee list opt-out, accessibility needs, Code of Conduct agreement.', 'wordcamporg' );
	}
	return $default_fields;
}
