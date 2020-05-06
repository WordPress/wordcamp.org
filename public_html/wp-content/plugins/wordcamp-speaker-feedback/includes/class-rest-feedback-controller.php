<?php

namespace WordCamp\SpeakerFeedback;

use WP_Error, WP_REST_Request, WP_REST_Response, WP_REST_Server, WP_User;
use WP_REST_Comments_Controller;
use function WordCamp\SpeakerFeedback\Comment\{ add_feedback, is_feedback, update_feedback };
use function WordCamp\SpeakerFeedback\CommentMeta\{ get_feedback_meta_field_schema, validate_feedback_meta };
use function WordCamp\SpeakerFeedback\Post\post_accepts_feedback;
use function WordCamp\SpeakerFeedback\Spam\spam_check;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use const WordCamp\SpeakerFeedback\Cron\SPEAKER_OPT_OUT_KEY;

defined( 'WPINC' ) || die();

/**
 * Class REST_Feedback_Controller
 *
 * @package WordCamp\SpeakerFeedback
 */
class REST_Feedback_Controller extends WP_REST_Comments_Controller {
	/**
	 * REST_Feedback_Controller constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->namespace = 'wordcamp-speaker-feedback/v1';
		$this->rest_base = 'feedback';
	}

	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * Currently, the only route needed for feedback is `create`.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the object.' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
			)
		);

		// The Core users endpoint does not allow updating a user that is not a member of the site,
		// so we have to create our own endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/notifications/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the user.', 'wordcamporg' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_notifications' ),
					'permission_callback' => array( $this, 'update_notifications_permissions_check' ),
					'args'                => array(
						'speaker_opt_out'           => array(
							'description' => __( 'Speaker has opted out of notifications about feedback.', 'wordcamporg' ),
							'type'        => 'boolean',
							'required'    => false,
							'arg_options' => array(
								'sanitize_callback' => array( $this, 'wp_validate_boolean' ),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Creates a comment.
	 *
	 * Based off of WP_REST_Comments_Controller::create_item, but uses a specific custom comment type.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or error object on failure.
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new WP_Error(
				'rest_feedback_exists',
				__( 'Cannot create existing feedback.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

		// We don't want these values set via request parameters.
		$request['content'] = '';
		$request['date']    = wp_date( 'c' );
		$request['parent']  = 0;

		$prepared_feedback = $this->prepare_item_for_creation( $request );
		if ( is_wp_error( $prepared_feedback ) ) {
			return $prepared_feedback;
		}

		if ( ! isset( $prepared_feedback['comment_post_ID'] ) ) {
			return new WP_Error(
				'rest_feedback_post_id_required',
				__( 'Feedback must be associated with a specific post.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

		if ( ! isset( $prepared_feedback['comment_author'], $prepared_feedback['comment_author_email'] ) ) {
			return new WP_Error(
				'rest_feedback_author_data_required',
				__( 'Feedback must have a valid author name and email.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

		if ( ! isset( $prepared_feedback['comment_meta'] ) ) {
			return new WP_Error(
				'rest_feedback_meta_data_required',
				__( 'Feedback must include data from the feedback form.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

		$spam_check = spam_check( $prepared_feedback );
		if ( 'discard' === $spam_check ) {
			return new WP_Error(
				'rest_feedback_spam_discarded',
				__( 'Feedback submission has been discarded as spam.', 'wordcamporg' ),
				array(
					'status' => 403,
				)
			);
		}

		if ( 'spam' === $spam_check ) {
			$prepared_feedback['comment_approved'] = 'spam';
		}

		// We're not using the comment content field, but this also checks the length of other fields.
		// The length of meta fields is checked separately. See `validate_feedback_meta()`.
		$check_comment_lengths = wp_check_comment_data_max_lengths( $prepared_feedback );
		if ( is_wp_error( $check_comment_lengths ) ) {
			return new WP_Error(
				$check_comment_lengths->get_error_code(),
				__( 'Feedback field exceeds maximum length allowed.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

		add_filter( 'duplicate_comment_id', array( $this, 'duplicate_check' ), 10, 2 );

		// We're not using this to set the status of the feedback comment, since it's always set to `hold`. However,
		// this also checks for duplicates, flooding, and blacklisting.
		$allowed = wp_allow_comment( $prepared_feedback, true );
		if ( is_wp_error( $allowed ) ) {
			$error_code    = $allowed->get_error_code();
			$error_message = $allowed->get_error_message();

			if ( 'comment_duplicate' === $error_code ) {
				return new WP_Error( $error_code, $error_message, array( 'status' => 409 ) );
			}

			if ( 'comment_flood' === $error_code ) {
				return new WP_Error( $error_code, $error_message, array( 'status' => 400 ) );
			}

			return $allowed;
		}

		remove_filter( 'duplicate_comment_id', array( $this, 'duplicate_check' ), 10 );

		$comment_id = add_feedback( $prepared_feedback );

		if ( false === $comment_id ) {
			return new WP_Error(
				'rest_feedback_creation_failed',
				__( 'Feedback submission failed.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

		$response = array(); // At this point we have no reason to return details about the new feedback comment.
		$response = rest_ensure_response( $response );

		$response->set_status( 201 ); // This is a sufficient signal that the request was successful.

		return $response;
	}

	/**
	 * Checks if a given request has access to create a feedback comment.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|bool True if the request has access to create items, error object otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		if ( empty( $request['post'] ) ) {
			return new WP_Error(
				'rest_feedback_invalid_post_id',
				__( 'Sorry, you are not allowed to create this comment without a post.', 'wordcamporg' ),
				array(
					'status' => 403,
				)
			);
		}

		$accepts_feedback = post_accepts_feedback( (int) $request['post'] );

		if ( is_wp_error( $accepts_feedback ) ) {
			return $accepts_feedback;
		}

		// TODO Should we do a nonce check, or other permissions?

		return true;
	}

	/**
	 * Prepares a single feedback comment to be inserted into the database.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error Prepared feedback comment, otherwise WP_Error object.
	 */
	protected function prepare_item_for_creation( $request ) {
		$prepared_feedback = parent::prepare_item_for_database( $request );

		if ( isset( $request['meta'] ) ) {
			$validated_meta = validate_feedback_meta( $request['meta'], 'create' );

			if ( is_wp_error( $validated_meta ) ) {
				return $validated_meta;
			}

			$prepared_feedback['comment_meta'] = $validated_meta;
		}

		// These indexes might be missing, but need to be present for `wp_allow_comment`.
		$prepared_feedback = wp_parse_args(
			$prepared_feedback,
			array(
				'comment_author_url' => '',
				'comment_agent'      => '',
				'comment_type'       => COMMENT_TYPE, // We set this later, but it still needs to be here.
			)
		);

		return $prepared_feedback;
	}

	/**
	 * Updates a feedback comment.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or error object on failure.
	 */
	public function update_item( $request ) {
		$prepared_feedback = $this->prepare_item_for_update( $request );
		if ( is_wp_error( $prepared_feedback ) ) {
			return $prepared_feedback;
		}

		if ( ! isset( $prepared_feedback['comment_meta'] ) || empty( $prepared_feedback['comment_meta'] ) ) {
			return new WP_Error(
				'rest_feedback_meta_data_required',
				__( 'No valid data was submitted for update.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

		update_feedback( $prepared_feedback['id'], $prepared_feedback['comment_meta'] );

		$response = array(); // At this point we have no reason to return details about the updated feedback comment.
		$response = rest_ensure_response( $response );

		$response->set_status( 201 ); // This is a sufficient signal that the request was successful.

		return $response;
	}

	/**
	 * Checks if a given REST request has access to update a comment.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|bool True if the request has access to update the item, error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		$comment = $this->get_comment( $request['id'] );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}

		if ( ! is_feedback( $comment ) ) {
			return new WP_Error(
				'rest_feedback_not_feedback',
				__( 'This endpoint can only be used to update feedback comments.', 'wordcamporg' )
			);
		}

		if ( ! current_user_can( 'read_post_' . COMMENT_TYPE, $comment->comment_post_ID ) ) {
			return new WP_Error(
				'rest_feedback_no_permission',
				__( 'Sorry, you are not allowed to update this comment.', 'wordcamporg' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Prepares a single feedback comment to be updated in the database.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error Prepared feedback comment, otherwise WP_Error object.
	 */
	protected function prepare_item_for_update( $request ) {
		$prepared_feedback = array();

		$comment = $this->get_comment( $request['id'] );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}

		$prepared_feedback['id'] = $comment->comment_ID;

		if ( isset( $request['meta'] ) ) {
			$validated_meta = validate_feedback_meta( $request['meta'], 'update' );

			if ( is_wp_error( $validated_meta ) ) {
				return $validated_meta;
			}

			$prepared_feedback['comment_meta'] = $validated_meta;
		}

		return $prepared_feedback;
	}

	/**
	 * Incorporate meta values into the duplicate check.
	 *
	 * Note that this assumes the meta data in `$comment_data` has already been validated by `validate_feedback_meta`.
	 *
	 * @param string|int|null $duplicate_id Unused.
	 * @param array           $comment_data The current comment data that might be a duplication.
	 *
	 * @return bool
	 */
	public function duplicate_check( $duplicate_id, $comment_data ) {
		$args = array(
			'number'     => 1,
			'type'       => COMMENT_TYPE,
			'status'     => array( 'approve', 'hold', 'spam' ),
			'post_id'    => $comment_data['comment_post_ID'],
			'meta_query' => array( 'relation' => 'AND' ),
		);

		if ( isset( $comment_data['user_id'] ) ) {
			$args['user_id'] = $comment_data['user_id'];
		} else {
			$args['author_email'] = $comment_data['comment_author_email'];
		}

		$schema = get_feedback_meta_field_schema( 'create' );

		foreach ( $comment_data['comment_meta'] as $key => $value ) {
			$args['meta_query'][] = array(
				'key'   => $key,
				'value' => $value,
				// This might need to change if we introduce a meta field with a type other than string or integer.
				'type'  => ( 'string' === $schema[ $key ]['type'] ) ? 'CHAR' : 'NUMERIC',
			);

			unset( $schema[ $key ] );
		}

		foreach ( array_keys( $schema ) as $missing_key ) {
			$args['meta_query'][] = array(
				'key'     => $missing_key,
				'compare' => 'NOT EXISTS',
			);
		}

		$duplicates = get_comments( $args );

		return ! empty( $duplicates );
	}

	/**
	 * Updates a user's notification preferences.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or error object on failure.
	 */
	public function update_notifications( $request ) {
		$user = new WP_User( $request['id'] );
		if ( ! $user->exists() ) {
			return new WP_Error(
				'rest_feedback_notifications_invalid_user_id',
				__( 'Invalid user ID.', 'wordcamporg' ),
				array( 'status' => 404 )
			);
		}

		if ( ! isset( $request['speaker_opt_out'] ) ) {
			return new WP_Error(
				'rest_feedback_notifications_data_required',
				__( 'No valid notifications parameters were submitted.', 'wordcamporg' ),
				array( 'status' => 400 )
			);
		}

		$requested_state = wp_validate_boolean( $request['speaker_opt_out'] );
		$current_state   = wp_validate_boolean( get_user_meta( $user->ID, SPEAKER_OPT_OUT_KEY, true ) );

		if ( $requested_state === $current_state ) {
			return new WP_Error(
				'rest_feedback_notifications_data_not_changed',
				__( 'The requested notifications update has already been applied.', 'wordcamporg' ),
				array( 'status' => 409 )
			);
		}

		if ( true === $requested_state ) {
			$result = update_user_meta( $user->ID, SPEAKER_OPT_OUT_KEY, true );
		} else {
			$result = delete_user_meta( $user->ID, SPEAKER_OPT_OUT_KEY );
		}

		if ( false === $result ) {
			return new WP_Error(
				'rest_feedback_notifications_update_failed',
				__( 'The requested notifications update could not be completed.', 'wordcamporg' ),
				array( 'status' => 500 )
			);
		}

		$response = array(); // At this point we have no reason to return details about the updated notifications.
		$response = rest_ensure_response( $response );

		$response->set_status( 201 ); // This is a sufficient signal that the request was successful.

		return $response;
	}

	/**
	 * Checks if a given REST request has access to update a user.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|bool True if the request has access to update the item, error object otherwise.
	 */
	public function update_notifications_permissions_check( $request ) {
		$user = new WP_User( $request['id'] );
		if ( ! $user->exists() ) {
			return new WP_Error(
				'rest_feedback_invalid_user_id',
				__( 'Invalid user ID.', 'wordcamporg' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return new WP_Error(
				'rest_feedback_cannot_edit_user',
				__( 'Sorry, you are not allowed to edit this user.', 'wordcamporg' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
