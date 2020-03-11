<?php

namespace WordCamp\CampTix_Tweaks\Ticket_Types\Remote;
use function WordCamp\CampTix_Tweaks\Ticket_Types\get_type_slug;
defined( 'WPINC' ) || die();

const SLUG = 'remote';

add_filter( 'camptix_ticket_types', __NAMESPACE__ . '\add_type' );
add_filter( 'camptix_metabox_questions_default_fields_list', __NAMESPACE__ . '\modify_default_fields_list' );
add_filter( 'camptix_accommodations_question_text', __NAMESPACE__ . '\accommodations_question_text', 10, 2 );

add_action( 'camptix_attendee_form_after_questions', __NAMESPACE__ . '\set_allergy_filter', 10, 2 );
add_action( 'camptix_form_edit_attendee_after_questions', __NAMESPACE__ . '\set_allergy_filter', 10 );
add_filter( 'camptix_checkout_attendee_info', __NAMESPACE__ . '\set_allergy_filter', 10 );

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

/**
 * Set a boolean filter on the "Allergy" question, to skip the question if it's remote. This will also skip the
 * validation step when creating an attendee record.
 *
 * @param array    $form_data
 * @param int|null $i
 * @return array The form data, used by `camptix_checkout_attendee_info` hook. Should be unchanged, only used
 *               to set the skip filter.
 */
function set_allergy_filter( $form_data, $i = null ) {
	if ( ! is_null( $i ) ) {
		$form_data = ( isset( $form_data['tix_attendee_info'][ $i ] ) ) ? $form_data['tix_attendee_info'][ $i ] : array();
	}

	if ( ! isset( $form_data['ticket_id'] ) ) {
		return $form_data;
	}

	if ( SLUG === get_type_slug( $form_data['ticket_id'] ) ) {
		remove_filter( 'camptix_allergy_should_skip', '__return_false' );
		add_filter( 'camptix_allergy_should_skip', '__return_true' );
	} else {
		remove_filter( 'camptix_allergy_should_skip', '__return_true' );
		add_filter( 'camptix_allergy_should_skip', '__return_false' );
	}

	return $form_data;
}

