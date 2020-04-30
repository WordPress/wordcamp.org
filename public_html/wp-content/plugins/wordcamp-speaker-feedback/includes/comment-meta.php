<?php

namespace WordCamp\SpeakerFeedback\CommentMeta;

use WP_Error;
use function WordCamp\SpeakerFeedback\Comment\get_feedback;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

const META_VERSION = 1;

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
 * Note that if another `type` of field is added besides string or integer, the
 * WordCamp\SpeakerFeedback\REST_Feedback_Controller::duplicate_check() method will
 * need to be updated.
 *
 * @param string $context Optional. The context in which the field schema is being used.
 *                        'all', 'create', or 'update'. Defaults to 'all'.
 *                        Note that this does not map to the context values used in
 *                        the REST API.
 * @param string $key     Optional. A specific key to get the schema for.
 *
 * @return array
 */
function get_feedback_meta_field_schema( $context = 'all', $key = '' ) {
	$schema = array(
		'version' => array(
			'sft_context'       => array( 'create' ),
			'description'       => 'The version of the feedback questions.',
			'type'              => 'integer',
			'single'            => true,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => false,
			'default'           => META_VERSION,
			'required'          => false,
		),
		'rating'  => array(
			'sft_context'       => array( 'create' ),
			'description'       => 'The rating. A number between 1 and 5.',
			'type'              => 'integer',
			'single'            => true,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => false,
			'default'           => 0,
			'required'          => true,
			'attributes'        => array(
				'min'  => 1,
				'max'  => 5,
				'step' => 1,
			),
		),
		'q1'      => array(
			'sft_context'       => array( 'create' ),
			'description'       => 'The answer to the first feedback question.',
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
			'default'           => '',
			'required'          => true,
			'attributes'        => array(
				'maxlength' => 5000,
			),
		),
		'q2'      => array(
			'sft_context'       => array( 'create' ),
			'description'       => 'The answer to the second feedback question.',
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
			'default'           => '',
			'required'          => false,
			'attributes'        => array(
				'maxlength' => 5000,
			),
		),
		'q3'      => array(
			'sft_context'       => array( 'create' ),
			'description'       => 'The answer to the third feedback question.',
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
			'default'           => '',
			'required'          => false,
			'attributes'        => array(
				'maxlength' => 5000,
			),
		),
		'helpful' => array(
			'sft_context'       => array( 'update' ),
			'description'       => 'The speaker found this feedback helpful.',
			'type'              => 'boolean',
			'single'            => true,
			'sanitize_callback' => 'wp_validate_boolean',
			'show_in_rest'      => false,
			'default'           => false,
			'required'          => false,
		),
		'speaker_notified' => array(
			'sft_context'       => array( 'internal' ),
			'description'       => 'The speaker has been notified about this feedback.',
			'type'              => 'boolean',
			'single'            => true,
			'sanitize_callback' => 'wp_validate_boolean',
			'show_in_rest'      => false,
			'default'           => false,
			'required'          => false,
		),
	);

	if ( 'all' !== $context ) {
		$schema = array_filter(
			$schema,
			function( $field ) use ( $context ) {
				$field_context = $field['sft_context'] ?? array();

				return in_array( $context, $field_context, true );
			}
		);
	}

	if ( $key ) {
		return $schema[ $key ] ?? array();
	}

	return $schema;
}

/**
 * Check that an array of meta values has all required keys and contains valid data.
 *
 * @param array  $meta    Associative array of meta values to validate.
 * @param string $context Optional. The context in which the field schema is being used.
 *                        See get_feedback_meta_field_schema() for possible values.
 *                        Default is 'create'.
 *
 * @return array|WP_Error
 */
function validate_feedback_meta( $meta, $context = 'all' ) {
	$fields          = get_feedback_meta_field_schema( $context );
	$required_fields = array_filter(
		wp_list_pluck( $fields, 'required' ),
		function( $is_required ) {
			return $is_required;
		}
	);

	$missing_fields = array_diff_key( $required_fields, $meta );

	if ( ! empty( $missing_fields ) ) {
		return new WP_Error(
			'feedback_meta_missing_field',
			__( 'Please fill in all required fields.', 'wordcamporg' ),
			array(
				'missing_fields' => array_keys( $missing_fields ),
			)
		);
	}

	$invalid_fields = array();

	foreach ( $meta as $key => $value ) {
		if ( ! isset( $fields[ $key ] ) ) {
			// Discard unknown meta keys.
			unset( $meta[ $key ] );
			continue;
		}

		$type = $fields[ $key ]['type'];

		$validation = call_user_func( __NAMESPACE__ . "\\validate_meta_$type", $key, $value );

		if ( is_wp_error( $validation ) ) {
			$invalid_fields = array_merge( $invalid_fields, $validation->get_error_data() );
		}
	}

	if ( ! empty( $invalid_fields ) ) {
		return new WP_Error(
			'feedback_meta_invalid_data',
			__( 'One or more fields has invalid data.', 'wordcamporg' ),
			$invalid_fields
		);
	}

	if ( isset( $fields['version'] ) ) {
		$meta['version'] = $fields['version']['default'];
	}

	return $meta;
}

/**
 * Validate the submitted value of a boolean field.
 *
 * This function is not intended to be called directly. See `validate_feedback_meta()`.
 *
 * @param string $key
 * @param mixed  $value
 *
 * @return bool|WP_Error
 */
function validate_meta_boolean( $key, $value ) {
	$result = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

	if ( is_null( $result ) ) {
		$error_message = __( 'This value must be true or false.', 'wordcamporg' );

		return new WP_Error(
			'feedback_meta_invalid_data',
			$error_message,
			array(
				$key => $error_message,
			)
		);
	}

	return true;
}

/**
 * Validate the submitted value of an integer field.
 *
 * This function is not intended to be called directly. See `validate_feedback_meta()`.
 *
 * @param string $key
 * @param mixed  $value
 *
 * @return bool|WP_Error
 */
function validate_meta_integer( $key, $value ) {
	$schema        = get_feedback_meta_field_schema( 'all', $key );
	$error_code    = 'feedback_meta_invalid_data';
	$error_message = '';

	if ( ! is_numeric( $value ) ) {
		$error_message = __( 'This value must be a number.', 'wordcamporg' );
	}

	$value = absint( $value );

	if ( isset( $schema['attributes']['min'] ) && $value < $schema['attributes']['min'] ) {
		$error_message = sprintf(
			__( 'This value needs to be greater than %s.', 'wordcamporg' ),
			$schema['attributes']['min']
		);
	}

	if ( isset( $schema['attributes']['max'] ) && $value > $schema['attributes']['max'] ) {
		$error_message = sprintf(
			__( 'This value needs to be less than %s.', 'wordcamporg' ),
			$schema['attributes']['max']
		);
	}

	if ( $error_message ) {
		return new WP_Error(
			$error_code,
			$error_message,
			array(
				$key => $error_message,
			)
		);
	}

	return true;
}

/**
 * Validate the submitted value of a string field.
 *
 * This function is not intended to be called directly. See `validate_feedback_meta()`.
 *
 * @param string $key
 * @param mixed  $value
 *
 * @return bool|WP_Error
 */
function validate_meta_string( $key, $value ) {
	$schema        = get_feedback_meta_field_schema( 'all', $key );
	$error_code    = 'feedback_meta_invalid_data';
	$error_message = '';

	if ( isset( $schema['attributes']['maxlength'] ) && mb_strlen( $value ) > $schema['attributes']['maxlength'] ) {
		$error_message = sprintf(
			_n(
				'This answer is too long, it should be less than %s character.',
				'This answer is too long, it should be less than %s characters.',
				$schema['attributes']['maxlength'],
				'wordcamporg'
			),
			$schema['attributes']['maxlength']
		);
	}

	if ( $error_message ) {
		return new WP_Error(
			$error_code,
			$error_message,
			array(
				$key => $error_message,
			)
		);
	}

	return true;
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
	 * Versioned questions. Each case in the switch is an integer version of the set of questions. The case integer
	 * that contains the latest set of questions should match the integer assigned to the `META_VERSION` constant.
	 *
	 * If the questions below need to be modified, a new complete set should be added as a new case. The `META_VERSION`
	 * constant in this file then needs to be updated to match the latest case number. The submission form and JS
	 * process will also need to be updated & tested.
	 */
	switch ( $version ) {
		case 1:
			$questions = array(
				'rating' => __( 'Rate this talk', 'wordcamporg' ),
				'q1'     => __( "What's one good thing you'd keep in this presentation?", 'wordcamporg' ),
				'q2'     => __( "What's one thing you'd tweak to improve the presentation?", 'wordcamporg' ),
				'q3'     => __( "What's one unhelpful thing you'd delete from the presentation?", 'wordcamporg' ),
			);
			break;

		default:
			// Default to the latest version.
			$questions = get_feedback_questions( META_VERSION );
			break;
	}

	return $questions;
}

/**
 * Count feedback comments marked as helpful for a specific post or overall.
 *
 * @param int $post_id
 *
 * @return int
 */
function count_helpful_feedback( $post_id = 0 ) {
	$post__in = array();
	if ( $post_id ) {
		$post__in[] = absint( $post_id );
	}

	$args = array(
		'meta_query' => array(
			array(
				'key'   => 'helpful',
				'value' => 1,
				'type'  => 'NUMERIC',
			),
		),
	);

	$feedbacks = get_feedback( $post__in, array( 'hold', 'approve' ), $args );

	$helpful_count = 0;

	foreach ( $feedbacks as $feedback ) {
		if ( $feedback->helpful ) {
			$helpful_count ++;
		}
	}

	return $helpful_count;
}
