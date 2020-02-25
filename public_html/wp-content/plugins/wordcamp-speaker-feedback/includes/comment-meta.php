<?php

namespace WordCamp\SpeakerFeedback\CommentMeta;

use WP_Error;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

const META_VERSION    = 1;
const META_MAX_LENGTH = 5000;

add_filter( 'get_object_subtype_comment', __NAMESPACE__ . '\add_comment_subtype', 10, 2 );
add_action( 'init',                       __NAMESPACE__ . '\register_meta_fields' );

/**
 * Filter: Register a subtype for feedback comments.
 *
 * Unlike for posts, Core does not recognize subtypes for comment objects out-of-the-box.
 *
 * @param string $object_subtype
 * @param int    $object_id
 *
 * @return string
 */
function add_comment_subtype( $object_subtype, $object_id ) {
	$comment = get_comment( $object_id );

	if ( COMMENT_TYPE === $comment->comment_type ) {
		$object_subtype = $comment->comment_type;
	}

	return $object_subtype;
}

/**
 * Register meta fields for the feedback comment subtype.
 *
 * This allows for automatic sanitization of meta values when storing and retrieving.
 *
 * Note that Core doesn't normally support subtypes for the comment object, even though it kind of
 * supports comment types. See `add_comment_subtype` in this file.
 *
 * @return void
 */
function register_meta_fields() {
	$fields = get_feedback_meta_field_schema();

	foreach ( $fields as $key => $schema ) {
		$schema['object_subtype'] = COMMENT_TYPE;

		register_meta( 'comment', $key, $schema );
	}
}

/**
 * Define the properties of the feedback meta fields.
 *
 * @param string $key Optional. A specific key to get the schema for.
 *
 * @return array
 */
function get_feedback_meta_field_schema( $key = '' ) {
	$schema = array(
		'version' => array(
			'description'       => 'The version of the feedback questions.',
			'type'              => 'integer',
			'single'            => true,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => false,
			'default'           => META_VERSION,
			'required'          => true,
		),
		'rating'  => array(
			'description'       => 'The rating. A number between 1 and 5.',
			'type'              => 'integer',
			'single'            => true,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => false,
			'default'           => 0,
			'required'          => true,
		),
		'q1'      => array(
			'description'       => 'The answer to the first feedback question.',
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
			'default'           => '',
			'required'          => false,
		),
		'q2'      => array(
			'description'       => 'The answer to the second feedback question.',
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
			'default'           => '',
			'required'          => false,
		),
		'q3'      => array(
			'description'       => 'The answer to the third feedback question.',
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
			'default'           => '',
			'required'          => false,
		),
	);

	if ( $key ) {
		return $schema[ $key ] ?? array();
	}

	return $schema;
}

/**
 * Check that an array of meta values has all required keys and contains valid data.
 *
 * @param array $meta
 *
 * @return array|WP_Error
 */
function validate_feedback_meta( $meta ) {
	if ( ! isset( $meta['version'] ) ) {
		$meta['version'] = META_VERSION;
	}

	$fields          = get_feedback_meta_field_schema();
	$required_fields = array_filter(
		wp_list_pluck( $fields, 'required' ),
		function( $is_required ) {
			return $is_required;
		}
	);

	$missing_fields = array_diff_key( $required_fields, $meta );

	if ( ! empty( $missing_fields ) ) {
		return new WP_Error(
			'feedback_missing_meta',
			__( 'Please fill in all required fields.', 'wordcamporg' ),
			array(
				'missing_fields' => array_keys( $missing_fields ),
			)
		);
	}

	$integer_fields = array_keys( wp_list_pluck( $fields, 'type' ), 'integer', true );

	foreach ( $integer_fields as $key ) {
		if ( isset( $meta[ $key ] ) && ! is_numeric( $meta[ $key ] ) ) {
			return new WP_Error(
				'feedback_meta_not_numeric',
				__( 'Feedback submission contains invalid data.', 'wordcamporg' ),
				array(
					'meta_key' => $key,
				)
			);
		}
	}

	$string_fields = array_keys( wp_list_pluck( $fields, 'type' ), 'string', true );

	foreach ( $string_fields as $key ) {
		if ( isset( $meta[ $key ] ) && mb_strlen( $meta[ $key ] ) > META_MAX_LENGTH ) {
			return new WP_Error(
				'feedback_meta_too_long',
				__( 'Feedback submission is too long.', 'wordcamporg' ),
				array(
					'meta_key' => $key,
				)
			);
		}
	}

	return $meta;
}

/**
 *
 *
 * @param int $version
 *
 * @return array
 */
function get_feedback_questions( $version = META_VERSION ) {
	/**
	 * Versioned questions. The array key for each set of questions is the version number.
	 *
	 * If the questions below need to be modified, a new complete set should be added as an additional item in the
	 * array, with a new version key. The `META_VERSION` constant in this file then needs to be updated to match the
	 * latest key in the array.
	 */
	$questions = array(
		1 => array(
			'rating' => __( 'Rate this talk', 'wordcamporg' ),
			'q1'     => __( "What's one good thing you'd keep in this presentation?", 'wordcamporg' ),
			'q2'     => __( "What's one thing you'd tweak to improve the presentation?", 'wordcamporg' ),
			'q3'     => __( "What's one unhelpful thing you'd delete from the presentation?", 'wordcamporg' ),
		),
	);

	return $questions[ $version ] ?? array();
}
