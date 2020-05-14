<?php

namespace WordCamp\SpeakerFeedback;

use WP_Error, WP_REST_Request, WP_REST_Response, WP_REST_Server, WP_User;
use WP_REST_Controller;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;
use const WordCamp\SpeakerFeedback\Cron\SPEAKER_OPT_OUT_KEY;

defined( 'WPINC' ) || die();

/**
 * Class REST_Notifications_Controller
 *
 * @package WordCamp\SpeakerFeedback
 */
class REST_Notifications_Controller extends WP_REST_Controller {
	/**
	 * REST_Feedback_Controller constructor.
	 */
	public function __construct() {
		$this->namespace = 'wordcamp-speaker-feedback/v1';
		$this->rest_base = 'notifications';
	}

	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * @return void
	 */
	public function register_routes() {
		// The Core users endpoint does not allow updating a user that is not a member of the site,
		// so we have to create our own endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
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
								'sanitize_callback' => 'wp_validate_boolean',
							),
						),
					),
				),
			)
		);
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

		// On multisite, only super admins can edit users. Single site admins should be able to modify notifications
		// for speakers, so we also check our custom feedback moderation capability here.
		if ( ! current_user_can( 'edit_user', $user->ID ) && ! current_user_can( 'moderate_' . COMMENT_TYPE ) ) {
			return new WP_Error(
				'rest_feedback_cannot_edit_user',
				__( 'Sorry, you are not allowed to edit this user.', 'wordcamporg' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
