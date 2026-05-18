<?php
defined( 'WPINC' ) || die();

/**
 * REST API controller for the Campus Connect vetting queue.
 *
 * Exposes two endpoints:
 *
 *   GET  /wordcamp/v1/vetting/queue   — list Campus Connect posts awaiting vetting
 *   POST /wordcamp/v1/vetting/process — attach a vetting note and advance to Needs Action
 *
 * Both endpoints require the caller to be authenticated and hold the
 * `wordcamp_wrangle_wordcamps` capability (the same capability checked by
 * WordCamp_Admin::enforce_post_status() for status changes made through the UI).
 *
 * Because WordPress only loads WordCamp_Admin when is_admin() is true, status-change
 * logging (normally handled via the transition_post_status hook in that class) must
 * be performed explicitly by this controller.
 */
class WordCamp_REST_Vetting_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wordcamp/v1';

	/**
	 * REST API base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'vetting';

	/**
	 * Register the /queue and /process routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/queue',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_queue' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'description'       => __( 'Page of results to return.', 'wordcamporg' ),
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
							'description'       => __( 'Number of items per page (max 100).', 'wordcamporg' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/process',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'process_application' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_process_args(),
				),
			)
		);
	}

	/**
	 * Verify the caller can wrangle WordCamps.
	 *
	 * Mirrors the capability check in WordCamp_Admin::enforce_post_status().
	 *
	 * @param WP_REST_Request $request Full request details.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $request is part of the required WP_REST_Controller signature.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to use the vetting API.', 'wordcamporg' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'wordcamp_wrangle_wordcamps' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage WordCamp applications.', 'wordcamporg' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Return Campus Connect posts that are pending vetting, with pagination.
	 *
	 * Results are ordered oldest-first so the agent processes them in submission order.
	 * Pagination is exposed via `page` / `per_page` query args and the standard
	 * `X-WP-Total` / `X-WP-TotalPages` response headers.
	 *
	 * @param WP_REST_Request $request Full request details.
	 * @return WP_REST_Response
	 */
	public function get_queue( $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );

		$query = new WP_Query(
			array(
				'post_type'      => WCPT_POST_TYPE_ID,
				'post_status'    => 'wcpt-needs-vetting',
				'meta_key'       => 'event_subtype',
				'meta_value'     => 'campusconnect',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$items = array_map(
			function ( $post ) use ( $request ) {
				return $this->prepare_queue_item( $post, $request );
			},
			$query->posts
		);

		$total       = (int) $query->found_posts;
		$total_pages = (int) $query->max_num_pages;

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		return $response;
	}

	/**
	 * Build the response shape for a single queue entry.
	 *
	 * Returns the post ID, title, status, submission date, content (application
	 * text), admin edit URL, and a `meta` object with the key organizer fields
	 * the vetting agent needs to evaluate.
	 *
	 * Named `prepare_queue_item` (not `prepare_item_for_response`) to avoid
	 * accidentally overriding the WP_REST_Controller base-class method, which has
	 * a different signature and semantics.
	 *
	 * @param WP_Post         $post    The wordcamp post.
	 * @param WP_REST_Request $request Full request details.
	 * @return array
	 */
	public function prepare_queue_item( $post, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $request is available for future callers / subclasses.
		$meta_fields = array(
			'Organizer Name',
			'WordPress.org Username',
			'Email Address',
			'Location',
			'Number of Anticipated Attendees',
		);

		$meta = array();
		foreach ( $meta_fields as $field ) {
			$meta[ $field ] = get_post_meta( $post->ID, $field, true );
		}

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'date'     => $post->post_date,
			'content'  => $post->post_content,
			'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
			'meta'     => $meta,
		);
	}

	/**
	 * Declare the accepted parameters for the /process endpoint.
	 *
	 * @return array
	 */
	protected function get_process_args() {
		return array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'description'       => __( 'The ID of the Campus Connect post to process.', 'wordcamporg' ),
			),
			'note'    => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'description'       => __( 'Vetting notes to attach as a private note on the post.', 'wordcamporg' ),
			),
		);
	}

	/**
	 * Attach a vetting note and advance the post to "Needs Action".
	 *
	 * Validates that the post exists, is a Campus Connect entry, has a non-empty
	 * note, and is currently in "Needs Vetting". On success it:
	 *   1. Writes the note via wcpt_add_private_note().
	 *   2. Re-fetches the post to detect any concurrent status change (optimistic lock).
	 *   3. Changes the status to wcpt-needs-action via wp_update_post().
	 *   4. Writes a status-change log entry (since WordCamp_Admin is not loaded
	 *      in the REST context, this cannot rely on the transition_post_status hook).
	 *
	 * @param WP_REST_Request $request Full request details.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_application( $request ) {
		$post_id = $request->get_param( 'post_id' );
		$note    = $request->get_param( 'note' );

		$post = get_post( $post_id );

		if ( ! $post || WCPT_POST_TYPE_ID !== $post->post_type ) {
			return new WP_Error(
				'wcpt_vetting_invalid_post',
				__( 'Post not found.', 'wordcamporg' ),
				array( 'status' => 404 )
			);
		}

		if ( 'campusconnect' !== get_post_meta( $post_id, 'event_subtype', true ) ) {
			return new WP_Error(
				'wcpt_vetting_not_campus_connect',
				__( 'This post is not a Campus Connect application.', 'wordcamporg' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $note ) {
			return new WP_Error(
				'wcpt_vetting_empty_note',
				__( 'A vetting note is required.', 'wordcamporg' ),
				array( 'status' => 400 )
			);
		}

		if ( 'wcpt-needs-vetting' !== $post->post_status ) {
			return new WP_Error(
				'wcpt_vetting_wrong_status',
				/* translators: %s: current post status slug */
				sprintf(
					__( 'Expected status wcpt-needs-vetting, got %s.', 'wordcamporg' ),
					$post->post_status
				),
				array( 'status' => 409 )
			);
		}

		// 1. Attach the vetting note first so it is always paired with the transition.
		$note_id = wcpt_add_private_note( $post_id, $note, get_current_user_id() );

		if ( ! $note_id ) {
			return new WP_Error(
				'wcpt_vetting_note_failed',
				__( 'Could not save the vetting note. Status was not changed.', 'wordcamporg' ),
				array( 'status' => 500 )
			);
		}

		// 2. Reload the post to guard against a concurrent status change (optimistic concurrency check).
		$post = get_post( $post_id );

		if ( ! $post || 'wcpt-needs-vetting' !== $post->post_status ) {
			return new WP_Error(
				'wcpt_vetting_concurrent_update',
				__( 'The application status changed while this request was being processed. Please reload and try again.', 'wordcamporg' ),
				array( 'status' => 409 )
			);
		}

		// 3. Advance the status.
		$old_status = $post->post_status;

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'wcpt-needs-action',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/*
		 * 4. Log the transition (WordCamp_Admin::log_status_changes() is not
		 *    available outside the admin context, so we write the entry directly).
		 */
		$this->log_status_transition( $post_id, $old_status, 'wcpt-needs-action' );

		return rest_ensure_response(
			array(
				'id'         => $post_id,
				'old_status' => $old_status,
				'new_status' => 'wcpt-needs-action',
			)
		);
	}

	/**
	 * Write a status-change log entry for a Campus Connect post.
	 *
	 * Uses CC-specific labels from WordCamp_Loader::get_campus_connect_statuses()
	 * and follows the same storage format as Event_Admin::log_status_changes().
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $old_status Old post status slug.
	 * @param string $new_status New post status slug.
	 */
	protected function log_status_transition( $post_id, $old_status, $new_status ) {
		// Ensure status labels are stored in English, consistent with log_status_changes().
		$locale_switched = switch_to_locale( 'en_US' );

		$cc_statuses = WordCamp_Loader::get_campus_connect_statuses();
		$old_label   = $cc_statuses[ $old_status ] ?? $old_status;
		$new_label   = $cc_statuses[ $new_status ] ?? $new_status;

		$log_id = add_post_meta(
			$post_id,
			'_status_change',
			array(
				'timestamp' => time(),
				'user_id'   => get_current_user_id(),
				'message'   => sprintf( '%s &rarr; %s', $old_label, $new_label ),
			)
		);

		// Mirror the secondary index key written by log_status_changes().
		if ( $log_id ) {
			add_post_meta( $post_id, '_status_change_log_' . WCPT_POST_TYPE_ID . ' ' . $log_id, time() );
		}

		if ( $locale_switched ) {
			restore_previous_locale();
		}
	}
}
