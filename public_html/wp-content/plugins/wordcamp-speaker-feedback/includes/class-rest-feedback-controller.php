<?php

namespace WordCamp\SpeakerFeedback;

use WP_Error, WP_REST_Request, WP_REST_Response;
use WP_REST_Comments_Controller;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();


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
	 * Ensure a request has the correct parameters set for our feedback comment type.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Request
	 */
	protected function sanitize_request( $request ) {
		$request['type'] = COMMENT_TYPE;

		return $request;
	}

	/**
	 * Retrieves a list of feedback items.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or error object on failure.
	 */
	public function get_items( $request ) {
		$request = $this->sanitize_request( $request );

		return parent::get_items( $request );
	}

	/**
	 * Checks if a given request has access to read comments.
	 *
	 * @since 4.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|bool True if the request has read access, error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		$request = $this->sanitize_request( $request );

		$default_comment_check = parent::get_item_permissions_check( $request );

		if ( is_wp_error( $default_comment_check ) ) {
			return $default_comment_check;
		}

		// TODO See #340.

		return true;
	}
}
