<?php

/**
 * Class to access WordCamp CPT posts via the v2 REST API.
 *
 * @see WP_REST_Posts_Controller
 */
class WordCamp_REST_WordCamps_Controller extends WP_REST_Posts_Controller {
	/**
	 * Retrieves the WordCamp post's schema, conforming to JSON Schema.
	 *
	 * WordCamp-specific modifications to the standard post schema.
	 *
	 * @access public
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();

		// Since there is more than one public post status, show it in REST response
		if ( false === array_search( 'view', $schema['properties']['status']['context'] ) ) {
			$schema['properties']['status']['context'][] = 'view';
		}

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Sanitizes and validates the list of post statuses, including whether the
	 * user can query private statuses.
	 *
	 * Based on the method in WP_REST_Posts_Controller, but takes into account that
	 * there are multiple public statuses for the WordCamp CPT.
	 *
	 * @access public
	 *
	 * @param  string|array    $statuses  One or more post statuses.
	 * @param  WP_REST_Request $request   Full details about the request.
	 * @param  string          $parameter Additional parameter to pass to validation.
	 * @return array|WP_Error A list of valid statuses, otherwise WP_Error object.
	 */
	public function sanitize_post_statuses( $statuses, $request, $parameter ) {
		$statuses = wp_parse_slug_list( $statuses );

		$public_statuses = WordCamp_Loader::get_public_post_statuses();

		/*
		 * Camps that are scheduled and then cancelled should still be available (though not included
		 * by default). This allows Official WordPress Events to update their status, so that they'll be removed
		 * from the Events Widget.
		 */
		$public_statuses[] = 'wcpt-cancelled';

		/*
		 * @todo This was originally added so that the Official Events plugin could update the status of postponed
		 * camps, but it only covered the pre-planning status. There are other statuses that could be used during
		 * postponement, so https://meta.trac.wordpress.org/changeset/9786/ was added to cover all the use cases.
		 * Now that this is in the API, though, it shouldn't be removed, because that could break back-compat with
		 * other possible clients. It can be removed in a future version, though, since there's no longer a known
		 * need for it.
		 */
		$public_statuses[] = 'wcpt-pre-planning';

		foreach ( $statuses as $status ) {
			if ( in_array( $status, $public_statuses ) ) {
				continue;
			}

			$post_type_obj = get_post_type_object( $this->post_type );

			if ( current_user_can( $post_type_obj->cap->edit_posts ) ) {
				$result = rest_validate_request_arg( $status, $request, $parameter );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			} else {
				return new WP_Error( 'rest_forbidden_status', __( 'Status is forbidden.' ), array( 'status' => rest_authorization_required_code() ) );
			}
		}

		return $statuses;
	}

	/**
	 * Checks if user can read the WordCamp post.
	 *
	 * First make our custom check against public WordCamp statuses and
	 * after that fallback to default WP_REST_Posts_Controller for assurance.
	 *
	 * @access public
	 *
	 * @param object $post Post object.
	 * @return bool Whether the post can be read.
	 */
	public function check_read_permission( $post ) {
		// A status the plugin calls public defers to the default read permission check.
		if ( in_array( $post->post_status, WordCamp_Loader::get_public_post_statuses(), true ) ) {
			return WP_REST_Posts_Controller::check_read_permission( $post );
		}

		// Everything else is an application record, readable in one case only.
		return self::is_cancelled_after_scheduling( $post );
	}

	/**
	 * Whether a post is a camp that reached the official schedule and was then cancelled.
	 *
	 * Those stay readable so Official WordPress Events can drop them from the events
	 * widget. A camp cancelled while it was still an application was never listed
	 * anywhere, so it is not readable, the same as the rest of the application statuses.
	 *
	 * `menu_order` holds the date the camp was added to the schedule, so it is the record
	 * of whether the camp was ever public. See `WordCamp_Loader::set_scheduled_date()`.
	 * It is only written from 2016-06-23 onwards, so a camp scheduled before that and
	 * cancelled later reads as never scheduled.
	 *
	 * @param object $post Post object.
	 * @return bool
	 */
	protected static function is_cancelled_after_scheduling( $post ) {
		return 'wcpt-cancelled' === $post->post_status && $post->menu_order > 0;
	}
}
