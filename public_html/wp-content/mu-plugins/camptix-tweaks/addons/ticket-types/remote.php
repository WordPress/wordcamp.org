<?php

namespace WordCamp\CampTix_Tweaks\Ticket_Types\Remote;
use function WordCamp\CampTix_Tweaks\Ticket_Types\get_type_slug;
defined( 'WPINC' ) || die();

const SLUG = 'remote';

add_filter( 'camptix_ticket_types', __NAMESPACE__ . '\add_type' );
add_filter( 'camptix_metabox_questions_default_fields_list', __NAMESPACE__ . '\modify_default_fields_list' );
add_filter( 'camptix_accommodations_question_text', __NAMESPACE__ . '\accommodations_question_text', 10, 2 );

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

/**
 * Modify the accommodations question.
 *
 * @param string $question
 * @return string
 */
function accommodations_question_text( $question, $ticket_id ) {
	if ( SLUG === get_type_slug( $ticket_id ) ) {
		return __( 'Do you have any accessibility needs, such as a sign language interpreter, to participate in WordCamp?', 'wordcamporg' );
	}
	return $question;
}
