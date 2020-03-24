<?php

namespace WordCamp\SpeakerFeedback;

use WP_Error, WP_REST_Request, WP_REST_Response, WP_REST_Server;
use WP_REST_Comments_Controller;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use function WordCamp\SpeakerFeedback\Comment\add_feedback;
use function WordCamp\SpeakerFeedback\CommentMeta\validate_feedback_meta;
use function WordCamp\SpeakerFeedback\Spam\spam_check;

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

		$prepared_feedback = $this->prepare_item_for_database( $request );
		if ( is_wp_error( $prepared_feedback ) ) {
			return $prepared_feedback;
		}

		// These values need to be present for `wp_allow_comment`.
		$prepared_feedback = wp_parse_args(
			$prepared_feedback,
			array(
				'comment_author'       => '',
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_author_IP'    => '',
				'comment_agent'        => '',
				'comment_type'         => COMMENT_TYPE, // We set this later, but it still needs to be here.
			)
		);

		if ( ! isset( $prepared_feedback['comment_post_ID'] ) ) {
			return new WP_Error(
				'rest_feedback_no_post',
				__( 'Feedback must be associated with a specific post.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

		$feedback_author = null;
		if ( ! empty( $prepared_feedback['user_id'] ) ) {
			$feedback_author = absint( $prepared_feedback['user_id'] );
		} elseif (
			! empty( $prepared_feedback['comment_author'] )
			&& ! empty( $prepared_feedback['comment_author_email'] )
		) {
			$feedback_author = array(
				'name'  => $prepared_feedback['comment_author'],
				'email' => $prepared_feedback['comment_author_email'],
			);
		} else {
			return new WP_Error(
				'rest_feedback_author_data_required',
				__( 'Submitting feedback requires valid author name and email values.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

		// We're not using the comment content field, but this also checks the length of other fields.
		// The length of meta fields is checked separately. See `validate_feedback_meta()`.
		$check_comment_lengths = wp_check_comment_data_max_lengths( $prepared_feedback );
		if ( is_wp_error( $check_comment_lengths ) ) {
			$error_code = $check_comment_lengths->get_error_code();
			return new WP_Error(
				$error_code,
				__( 'Comment field exceeds maximum length allowed.', 'wordcamporg' ),
				array(
					'status' => 400,
				)
			);
		}

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

		$meta = validate_feedback_meta( $request['meta'] ?? array() );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$spam_check = spam_check( $prepared_feedback, $meta );
		if ( 'discard' === $spam_check ) {
			return new WP_Error(
				'rest_feedback_spam_discarded',
				__( 'Feedback submission has been discarded as spam.', 'wordcamporg' )
			);
		}

		$comment_id = add_feedback( $prepared_feedback['comment_post_ID'], $feedback_author, $meta, 'spam' === $spam_check );

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

		$post = get_post( (int) $request['post'] );
		if ( ! $post ) {
			return new WP_Error(
				'rest_feedback_invalid_post_id',
				__( 'Sorry, you are not allowed to create this comment without a post.', 'wordcamporg' ),
				array(
					'status' => 403,
				)
			);
		}

		if ( ! post_type_supports( get_post_type( $post ), 'wordcamp-speaker-feedback' ) ) {
			return new WP_Error(
				'rest_feedback_post_not_supported',
				__( 'Sorry, this post type does not support feedback.', 'wordcamporg' ),
				array(
					'status' => 403,
				)
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'rest_feedback_post_unavailable',
				__( 'Sorry, you are not allowed to create a comment on this post.', 'wordcamporg' ),
				array(
					'status' => 403,
				)
			);
		}

		// TODO is post "open" for feedback? Should we do a nonce check, or other permissions?

		return true;
	}
}
