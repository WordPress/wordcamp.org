<?php
/**
 * REST API endpoints for the front-end event management UI.
 *
 * Namespace: `wporg-groups/v1`
 *
 *   GET  /event-form-data?event_id={id?}
 *        Returns the data needed to render the create/edit form in one
 *        request: prefilled field values, the venue list for the dropdown,
 *        and (when editing) the existing event's stored values.
 *
 *   POST /event
 *        Creates a new gatherpress_event from the form payload.
 *
 *   POST /event/{id}
 *        Updates an existing gatherpress_event.
 *
 *   POST /event/{id}/rsvp
 *        RSVPs the current user to an event, together with their answers to
 *        the event's custom registration questions. Wraps GatherPress's own
 *        RSVP save so the answers and the RSVP are written in one request and
 *        required questions are enforced server-side.
 *
 *   GET  /group-info
 *   POST /group-info
 *        Reads and writes the group's name and description. Exists because
 *        core's /wp/v2/settings requires `manage_options`, which Organisers
 *        (editors) do not have.
 *
 * The event routes require the `current_user_can_manage_events()` capability,
 * plus post-specific capabilities when operating on an existing event. The
 * group-info routes require `current_user_can_manage_group_settings()`.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\REST;

defined( 'WPINC' ) || die();

use GatherPress\Core\Event\Event;
use GatherPress\Core\Venue\Setup as Venue_Setup;
use GatherPress\Core\Venue\Venue;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WordCamp\Groups\Frontend\Defaults\DESCRIPTION_BLOCK_NAMES;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;
use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_group_settings;
use function WordCamp\Groups\Frontend\Defaults\extract_description_blocks;
use function WordCamp\Groups\Frontend\Defaults\get_default_event_data;
use function WordCamp\Groups\Frontend\Defaults\get_event_venue_post_id;
use function WordCamp\Groups\Frontend\RSVP_Questions\get_missing_required;
use function WordCamp\Groups\Frontend\RSVP_Questions\get_questions;
use function WordCamp\Groups\Frontend\RSVP_Questions\sanitize_answers;
use function WordCamp\Groups\Frontend\RSVP_Questions\save_answers;
use function WordCamp\Groups\Frontend\RSVP_Questions\save_questions;

const NAMESPACE_V1 = 'wporg-groups/v1';

/**
 * Hook the REST routes into rest_api_init.
 */
function bootstrap(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
}

/**
 * Register all routes for this namespace.
 */
function register_routes(): void {
	register_rest_route(
		NAMESPACE_V1,
		'/event-form-data',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\get_event_form_data',
			'permission_callback' => __NAMESPACE__ . '\event_form_data_permissions_check',
			'args'                => array(
				'event_id' => array(
					'type'              => 'integer',
					'required'          => false,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/event',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\create_event',
			'permission_callback' => __NAMESPACE__ . '\create_event_permissions_check',
			'args'                => event_args_schema(),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/event/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\update_event',
			'permission_callback' => __NAMESPACE__ . '\publish_existing_event_permissions_check',
			'args'                => array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => static function ( $param ) {
						return Event::POST_TYPE === get_post_type( (int) $param );
					},
				),
			) + event_args_schema(),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/event/(?P<id>\d+)/rsvp',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\save_rsvp',
			'permission_callback' => __NAMESPACE__ . '\rsvp_permissions_check',
			'args'                => array(
				'id'      => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'status'  => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => static function ( $param ) {
						return in_array( $param, array( 'attending', 'not_attending' ), true );
					},
				),
				'answers' => array(
					'type'     => 'object',
					'required' => false,
					'default'  => array(),
				),
			),
		)
	);

	// ----- Drafts ---------------------------------------------------------
	//
	// Drafts are gatherpress_event posts with post_status='draft'. They're
	// group-scoped (any organizer on this site can see them) and use the
	// same payload schema as the main /event endpoint, except that
	// validation is permissive — autosave needs to be able to save a
	// half-filled form without rejecting it.

	register_rest_route(
		NAMESPACE_V1,
		'/drafts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\list_drafts',
			'permission_callback' => __NAMESPACE__ . '\manage_events_permissions_check',
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/draft',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\save_draft',
			'permission_callback' => __NAMESPACE__ . '\save_draft_permissions_check',
			'args'                => draft_args_schema(),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/draft/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\save_draft',
			'permission_callback' => __NAMESPACE__ . '\save_draft_permissions_check',
			'args'                => array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => static function ( $param ) {
						$post = get_post( (int) $param );
						return $post && Event::POST_TYPE === $post->post_type && 'draft' === $post->post_status;
					},
				),
			) + draft_args_schema(),
		)
	);

	register_rest_route(
		NAMESPACE_V1,
		'/draft/(?P<id>\d+)/publish',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\publish_draft',
			'permission_callback' => __NAMESPACE__ . '\publish_existing_event_permissions_check',
			'args'                => array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => static function ( $param ) {
						$post = get_post( (int) $param );
						return $post && Event::POST_TYPE === $post->post_type && 'draft' === $post->post_status;
					},
				),
			) + event_args_schema(),
		)
	);

	// ----- Group info -----------------------------------------------------
	//
	// The Settings > About tab edits `blogname` and `blogdescription`. Core's
	// /wp/v2/settings gates both behind `manage_options`, which only network
	// administrators have, so Organisers got a 403 on every read and write.
	// These routes expose just those two fields, behind the same capability
	// check that gates the settings UI itself.

	register_rest_route(
		NAMESPACE_V1,
		'/group-info',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\get_group_info',
				'permission_callback' => __NAMESPACE__ . '\manage_group_settings_permissions_check',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\update_group_info',
				'permission_callback' => __NAMESPACE__ . '\manage_group_settings_permissions_check',
				'args'                => array(
					'title'       => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		)
	);
}

/**
 * Capability check for the group-info routes.
 */
function manage_group_settings_permissions_check(): bool {
	return current_user_can_manage_group_settings();
}

/**
 * Return the group's name and description.
 */
function get_group_info(): WP_REST_Response {
	return new WP_REST_Response(
		array(
			'title'       => get_option( 'blogname', '' ),
			'description' => get_option( 'blogdescription', '' ),
		)
	);
}

/**
 * Update the group's name and description.
 *
 * Only the fields present in the request are written, so a client can send
 * one without clobbering the other.
 *
 * @return WP_Error|WP_REST_Response
 */
function update_group_info( WP_REST_Request $request ) {
	$title       = $request->get_param( 'title' );
	$description = $request->get_param( 'description' );

	// An empty group name is not recoverable from this UI: the next load
	// returns the blank value, so there is nothing left to restore it from.
	// Checked after sanitization, because `sanitize_text_field()` can empty
	// an input that was not empty when it was sent.
	if ( null !== $title && '' === trim( $title ) ) {
		return new WP_Error( 'wporg_groups_empty_group_name', 'Group name is required.', array( 'status' => 400 ) );
	}

	if ( null !== $title ) {
		update_option( 'blogname', $title );
	}

	if ( null !== $description ) {
		update_option( 'blogdescription', $description );
	}

	return get_group_info();
}

/**
 * Capability check for routes that only need the site-level organizer gate.
 *
 * The REST request is authenticated via cookies + nonce (the JS app sends
 * the standard `X-WP-Nonce` header through `wp.apiFetch`), so by the time
 * this callback fires WordPress already knows who the user is.
 */
function manage_events_permissions_check(): bool {
	return current_user_can_manage_events();
}

/**
 * Capability check for reading form defaults or an existing event.
 */
function event_form_data_permissions_check( WP_REST_Request $request ): bool {
	if ( ! current_user_can_manage_events() ) {
		return false;
	}

	$event_id = (int) $request->get_param( 'event_id' );
	if ( $event_id > 0 ) {
		return current_user_can_edit_event( $event_id );
	}

	return current_user_can_create_event();
}

/**
 * Capability check for creating and immediately publishing an event.
 */
function create_event_permissions_check(): bool {
	return current_user_can_manage_events()
		&& current_user_can_create_event()
		&& current_user_can_publish_event();
}

/**
 * Capability check for saving an event draft.
 */
function save_draft_permissions_check( WP_REST_Request $request ): bool {
	if ( ! current_user_can_manage_events() ) {
		return false;
	}

	$draft_id = (int) $request->get_param( 'id' );
	if ( $draft_id > 0 ) {
		return current_user_can_edit_event( $draft_id );
	}

	return current_user_can_create_event();
}

/**
 * Capability check for updating an existing event into a published state.
 */
function publish_existing_event_permissions_check( WP_REST_Request $request ): bool {
	$event_id = (int) $request->get_param( 'id' );

	return current_user_can_manage_events()
		&& $event_id > 0
		&& current_user_can_edit_event( $event_id )
		&& current_user_can_publish_event( $event_id );
}

/**
 * Capability check for the RSVP route — any logged-in visitor may RSVP to a
 * published event, exactly as with GatherPress's own RSVP endpoint.
 */
function rsvp_permissions_check(): bool {
	return is_user_logged_in();
}

/**
 * POST /event/{id}/rsvp
 *
 * RSVP the current user, storing their answers to the event's custom
 * registration questions on the same request.
 *
 * This exists rather than posting answers to GatherPress's RSVP endpoint and
 * then storing them in a follow-up call, because a required question has to be
 * able to *block* the RSVP. Splitting it in two would leave an RSVP recorded
 * with the answers rejected — exactly the "the RSVP data is wrong" failure the
 * feature is meant to prevent.
 *
 * @return WP_Error|WP_REST_Response
 */
function save_rsvp( WP_REST_Request $request ) {
	$event_id = (int) $request->get_param( 'id' );
	$status   = (string) $request->get_param( 'status' );
	$post     = get_post( $event_id );

	if ( ! $post || Event::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'wporg_groups_invalid_event', 'Invalid event ID', array( 'status' => 404 ) );
	}

	$event = new Event( $event_id );

	if ( ! $event->rsvp ) {
		return new WP_Error( 'wporg_groups_rsvp_unavailable', 'RSVP is not available for this event.', array( 'status' => 400 ) );
	}

	if ( $event->has_event_past() ) {
		return new WP_Error( 'wporg_groups_event_past', 'This event has already happened.', array( 'status' => 400 ) );
	}

	$questions = get_questions( $event_id );
	$answers   = sanitize_answers( $questions, $request->get_param( 'answers' ) );

	if ( 'attending' === $status ) {
		$missing = get_missing_required( $questions, $answers );

		if ( $missing ) {
			return new WP_Error(
				'wporg_groups_missing_answers',
				sprintf(
					/* translators: %s: comma-separated list of question labels. */
					__( 'Please answer: %s', 'wporg-groups-frontend' ),
					implode( ', ', $missing )
				),
				array( 'status' => 400 )
			);
		}
	}

	// Group sites are open to join, and RSVPing is the point at which someone
	// becomes a member — same as GatherPress's own endpoint and our
	// /members/join route.
	$user_id = get_current_user_id();
	if ( ! is_user_member_of_blog( $user_id ) ) {
		add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
	}

	$record     = $event->rsvp->save( $user_id, $status );
	$comment_id = (int) ( $record['comment_id'] ?? 0 );

	if ( $comment_id ) {
		// Answers belong to an attendance, not to a declined invitation — a
		// cancelled RSVP drops them.
		save_answers( $comment_id, 'attending' === $record['status'] ? $answers : array() );
	}

	return new WP_REST_Response(
		array(
			'success'   => (bool) $comment_id,
			'status'    => $record['status'] ?? 'no_status',
			'responses' => $event->rsvp->responses(),
		)
	);
}

/**
 * Whether the current user can create a GatherPress event on this site.
 */
function current_user_can_create_event(): bool {
	$post_type_object = get_post_type_object( Event::POST_TYPE );
	$capability       = $post_type_object->cap->create_posts ?? 'edit_posts';

	return current_user_can( $capability );
}

/**
 * Whether the current user can edit a specific GatherPress event.
 */
function current_user_can_edit_event( int $event_id ): bool {
	$post = get_post( $event_id );

	return $post
		&& Event::POST_TYPE === $post->post_type
		&& current_user_can( 'edit_post', $event_id );
}

/**
 * Whether the current user can publish GatherPress events.
 */
function current_user_can_publish_event( int $event_id = 0 ): bool {
	if ( $event_id > 0 ) {
		return current_user_can( 'publish_post', $event_id );
	}

	$post_type_object = get_post_type_object( Event::POST_TYPE );
	$capability       = $post_type_object->cap->publish_posts ?? 'publish_posts';

	return current_user_can( $capability );
}

/**
 * Whether the current user may use an attachment as an event's featured
 * image. Mirrors core's own visibility rules for attachments (public/
 * inherited attachments readable by anyone, private ones only by their
 * owner or users with `read_private_posts`) so a group organiser can't
 * point their event at another user's private/unattached media just by
 * guessing its ID.
 */
function current_user_can_use_attachment( int $attachment_id ): bool {
	return 'attachment' === get_post_type( $attachment_id )
		&& current_user_can( 'read_post', $attachment_id );
}

/**
 * Permissive arg schema for draft save — title is optional, time formats
 * are checked-but-not-required so the JS can autosave a half-filled form.
 */
function draft_args_schema(): array {
	$schema                           = event_args_schema();
	$schema['title']['required']      = false;
	$schema['date']['required']       = false;
	$schema['time_start']['required'] = false;
	$schema['time_end']['required']   = false;
	return $schema;
}

/**
 * Argument schema shared between POST /event and POST /event/{id}.
 */
function event_args_schema(): array {
	return array(
		'title'             => array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		),
		'description'       => array(
			// Serialised block markup. Allowed-block enforcement happens
			// when we run `wp_kses_post()` before saving.
			'type'     => 'string',
			'required' => false,
			'default'  => '',
		),
		'date'              => array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ( $param ) {
				return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $param );
			},
		),
		'time_start'        => array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ( $param ) {
				return (bool) preg_match( '/^\d{2}:\d{2}$/', (string) $param );
			},
		),
		'time_end'          => array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ( $param ) {
				return (bool) preg_match( '/^\d{2}:\d{2}$/', (string) $param );
			},
		),
		'venue_id'          => array(
			'type'              => 'integer',
			'required'          => false,
			'default'           => 0,
			'sanitize_callback' => 'absint',
		),
		'is_online'         => array(
			'type'              => 'boolean',
			'required'          => false,
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		),
		'online_event_link' => array(
			'type'              => 'string',
			'required'          => false,
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		),
		'new_venue_name'    => array(
			'type'              => 'string',
			'required'          => false,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		),
		'new_venue_address' => array(
			'type'              => 'string',
			'required'          => false,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		),
		'featured_image_id' => array(
			'type'              => 'integer',
			'required'          => false,
			'default'           => 0,
			'sanitize_callback' => 'absint',
		),
		// Custom registration questions. Deliberately has no `default` — an
		// absent parameter means "leave the existing questions alone", which
		// an empty-array default would turn into "delete them all".
		'rsvp_questions'    => array(
			'type'     => 'array',
			'required' => false,
			'items'    => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'string' ),
					'label'    => array( 'type' => 'string' ),
					'required' => array( 'type' => 'boolean' ),
				),
			),
		),
	);
}

/**
 * Write the event's custom registration questions, if the request carried any.
 *
 * @param int             $event_id Saved event post ID.
 * @param WP_REST_Request $request  The create/update/draft request.
 */
function maybe_save_rsvp_questions( int $event_id, WP_REST_Request $request ): void {
	$questions = $request->get_param( 'rsvp_questions' );

	if ( is_array( $questions ) ) {
		save_questions( $event_id, $questions );
	}
}

/**
 * GET /event-form-data
 *
 * Returns one combined payload so the modal only needs a single fetch on
 * open: defaults / existing values for the form, plus the venue list for
 * the dropdown.
 */
function get_event_form_data( WP_REST_Request $request ): WP_REST_Response {
	$event_id = (int) $request->get_param( 'event_id' );

	$fields = get_default_event_data();

	$is_editing = $event_id > 0 && Event::POST_TYPE === get_post_type( $event_id );

	if ( $is_editing ) {
		$fields['title'] = (string) get_post_field( 'post_title', $event_id );
		// Hand the editor only the description-prose blocks so it doesn't
		// trip on the GatherPress metadata blocks (event-date, venue, RSVP,
		// etc.) it has no way to render. The save path puts the metadata
		// blocks back in `build_post_content()`.
		$fields['description'] = extract_description_blocks( $event_id );

		$start = (string) get_post_meta( $event_id, 'gatherpress_datetime_start', true );
		$end   = (string) get_post_meta( $event_id, 'gatherpress_datetime_end', true );

		if ( preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/', $start, $m ) ) {
			$fields['date']       = $m[1];
			$fields['time_start'] = $m[2];
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} (\d{2}:\d{2})/', $end, $m ) ) {
			$fields['time_end'] = $m[1];
		}

		$fields['venue_id'] = get_event_venue_post_id( $event_id );

		$venue_taxonomy = Venue_Setup::get_instance()->taxonomy_for_event_post_type( Event::POST_TYPE );
		$venue_terms    = get_the_terms( $event_id, $venue_taxonomy );

		$fields['is_online']         = is_array( $venue_terms )
			&& in_array( 'online-event', wp_list_pluck( $venue_terms, 'slug' ), true );
		$fields['online_event_link'] = (string) get_post_meta(
			$event_id,
			'gatherpress_online_event_link',
			true
		);

		$thumb_id = (int) get_post_thumbnail_id( $event_id );
		if ( $thumb_id ) {
			$fields['featured_image_id']  = $thumb_id;
			$fields['featured_image_url'] = (string) wp_get_attachment_image_url( $thumb_id, 'medium' );
		}
	} else {
		// On create, prefill the description with an empty paragraph block so
		// the inline editor opens with a usable starting point rather than a
		// completely blank canvas.
		$fields['description'] = "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
	}

	// Always include the keys so the JS code can read them without
	// `undefined` checks.
	$fields['featured_image_id']  = $fields['featured_image_id'] ?? 0;
	$fields['featured_image_url'] = $fields['featured_image_url'] ?? '';
	$fields['rsvp_questions']     = $is_editing ? get_questions( $event_id ) : array();

	$venues = array_map(
		static function ( $post ) {
			return array(
				'id'   => (int) $post->ID,
				'name' => html_entity_decode( get_the_title( $post ) ),
			);
		},
		get_posts(
			array(
				'post_type'     => Venue::POST_TYPE,
				'post_status'   => 'publish',
				'numberposts'   => 200,
				'orderby'       => 'title',
				'order'         => 'ASC',
				'no_found_rows' => true,
			)
		)
	);

	return new WP_REST_Response(
		array(
			'is_editing' => $is_editing,
			'event_id'   => $is_editing ? $event_id : 0,
			'fields'     => $fields,
			'venues'     => $venues,
		)
	);
}

/**
 * GET /drafts — list every gatherpress_event currently in draft status.
 *
 * Returns lightweight summaries (id, title, last-modified, scheduled date)
 * so the modal's draft picker can render a list without one fetch per draft.
 */
function list_drafts(): WP_REST_Response {
	$drafts = get_posts(
		array(
			'post_type'     => Event::POST_TYPE,
			'post_status'   => 'draft',
			'numberposts'   => 50,
			'orderby'       => 'modified',
			'order'         => 'DESC',
			'no_found_rows' => true,
		)
	);

	$drafts = array_values(
		array_filter(
			$drafts,
			static function ( $post ) {
				return current_user_can_edit_event( (int) $post->ID );
			}
		)
	);

	$out = array_map(
		static function ( $post ) {
			return array(
				'id'           => (int) $post->ID,
				'title'        => $post->post_title ? html_entity_decode( $post->post_title ) : '',
				'modified_gmt' => $post->post_modified_gmt,
				'event_date'   => (string) get_post_meta( $post->ID, 'gatherpress_datetime_start', true ),
			);
		},
		$drafts
	);

	return new WP_REST_Response( $out );
}

/**
 * POST /draft           — create a new draft (autosave)
 * POST /draft/{id}      — update an existing draft (autosave)
 *
 * Both routes share this callback. Validation is intentionally loose so a
 * partially-filled form (e.g. just a title) can still be saved.
 */
function save_draft( WP_REST_Request $request ): WP_REST_Response {
	$draft_id = (int) $request->get_param( 'id' );

	$title       = trim( (string) $request->get_param( 'title' ) );
	$description = (string) $request->get_param( 'description' );
	$date        = (string) $request->get_param( 'date' );
	$time_start  = (string) $request->get_param( 'time_start' );
	$time_end    = (string) $request->get_param( 'time_end' );

	$post_args = array(
		'post_type'    => Event::POST_TYPE,
		'post_status'  => 'draft',
		'post_title'   => '' === $title ? __( '(Untitled draft)', 'wporg-groups-frontend' ) : $title,
		'post_content' => wp_kses_post( wp_unslash( $description ) ),
	);

	if ( $draft_id > 0 ) {
		$post_args['ID'] = $draft_id;
		$saved_id        = wp_update_post( $post_args, true );
	} else {
		$saved_id = wp_insert_post( $post_args, true );
	}

	if ( is_wp_error( $saved_id ) || ! $saved_id ) {
		return new WP_REST_Response(
			array( 'error' => 'Could not save draft.' ),
			500
		);
	}
	$saved_id = (int) $saved_id;

	// Datetimes — only persist if both ends are set; otherwise leave the
	// custom table row alone (it'll be created when the draft is published).
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date )
		&& preg_match( '/^\d{2}:\d{2}$/', $time_start )
		&& preg_match( '/^\d{2}:\d{2}$/', $time_end )
	) {
		$event = new Event( $saved_id );
		$event->save_datetimes(
			array(
				'post_id'        => $saved_id,
				'datetime_start' => sprintf( '%s %s:00', $date, $time_start ),
				'datetime_end'   => sprintf( '%s %s:00', resolve_event_end_date( $date, $time_start, $time_end ), $time_end ),
				'timezone'       => wp_timezone_string(),
			)
		);
	}

	// Physical and online venues — same path as the publish flow.
	$venue_id = resolve_venue_id(
		array(
			'venue_id'          => (int) $request->get_param( 'venue_id' ),
			'new_venue_name'    => (string) $request->get_param( 'new_venue_name' ),
			'new_venue_address' => (string) $request->get_param( 'new_venue_address' ),
		)
	);
	sync_event_venue_terms(
		$saved_id,
		$venue_id,
		(bool) $request->get_param( 'is_online' )
	);
	sync_online_event_link(
		$saved_id,
		(bool) $request->get_param( 'is_online' ),
		(string) $request->get_param( 'online_event_link' )
	);

	// Featured image.
	$featured_image_id = (int) $request->get_param( 'featured_image_id' );
	if ( $featured_image_id > 0 && current_user_can_use_attachment( $featured_image_id ) ) {
		set_post_thumbnail( $saved_id, $featured_image_id );
	}

	maybe_save_rsvp_questions( $saved_id, $request );

	return new WP_REST_Response(
		array(
			'id'           => $saved_id,
			'title'        => get_the_title( $saved_id ),
			'saved_at_gmt' => current_time( 'mysql', true ),
		)
	);
}

/**
 * POST /draft/{id}/publish — promote a draft to a published event.
 *
 * The body has the same shape as POST /event so the user can edit any
 * field one last time before publishing without having to re-save the
 * draft first.
 */
function publish_draft( WP_REST_Request $request ) {
	$draft_id = (int) $request->get_param( 'id' );
	if ( $draft_id <= 0 ) {
		return new WP_Error( 'wporg_groups_invalid_draft', 'Invalid draft ID', array( 'status' => 404 ) );
	}
	return persist_event( $draft_id, $request );
}

/**
 * POST /event — create a new gatherpress_event.
 */
function create_event( WP_REST_Request $request ) {
	return persist_event( 0, $request );
}

/**
 * POST /event/{id} — update an existing gatherpress_event.
 */
function update_event( WP_REST_Request $request ) {
	$event_id = (int) $request->get_param( 'id' );
	if ( $event_id <= 0 ) {
		return new WP_Error( 'wporg_groups_invalid_event', 'Invalid event ID', array( 'status' => 404 ) );
	}
	return persist_event( $event_id, $request );
}

/**
 * Resolve the calendar date an event's end time falls on.
 *
 * The form only collects one date plus separate start/end times, so an
 * end time earlier than the start time means the event crosses midnight
 * — e.g. 22:00 to 01:00 ends the day after `$date`. `persist_event()`
 * rejects `time_start === time_end` as a zero-length event before
 * calling this; `save_draft()` allows it through (drafts are saved
 * permissively), in which case it is treated as a full 24-hour rollover
 * rather than a same-day, zero-length span.
 */
function resolve_event_end_date( string $date, string $time_start, string $time_end ): string {
	if ( $time_end > $time_start ) {
		return $date;
	}

	return gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) );
}

/**
 * Shared create/update path. Persists post + datetimes + venue assignment
 * and returns the saved event's id and permalink so the JS app can
 * navigate to it.
 */
function persist_event( int $event_id, WP_REST_Request $request ) {
	$fields = array(
		'title'             => (string) $request->get_param( 'title' ),
		'description'       => (string) $request->get_param( 'description' ),
		'date'              => (string) $request->get_param( 'date' ),
		'time_start'        => (string) $request->get_param( 'time_start' ),
		'time_end'          => (string) $request->get_param( 'time_end' ),
		'venue_id'          => (int) $request->get_param( 'venue_id' ),
		'is_online'         => (bool) $request->get_param( 'is_online' ),
		'online_event_link' => (string) $request->get_param( 'online_event_link' ),
		'new_venue_name'    => (string) $request->get_param( 'new_venue_name' ),
		'new_venue_address' => (string) $request->get_param( 'new_venue_address' ),
		'featured_image_id' => (int) $request->get_param( 'featured_image_id' ),
	);

	if ( '' === trim( $fields['title'] ) ) {
		return new WP_Error( 'wporg_groups_missing_title', 'Title is required.', array( 'status' => 400 ) );
	}
	if ( $fields['time_start'] === $fields['time_end'] ) {
		return new WP_Error( 'wporg_groups_bad_time_range', 'End time must be after start time.', array( 'status' => 400 ) );
	}
	if ( $fields['is_online'] && '' === $fields['online_event_link'] ) {
		return new WP_Error(
			'wporg_groups_missing_online_event_link',
			'Online event link is required for online events.',
			array( 'status' => 400 )
		);
	}

	$post_args = array(
		'post_type'    => Event::POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => $fields['title'],
		'post_content' => build_post_content( $event_id, $fields['description'] ),
	);

	if ( $event_id > 0 ) {
		$post_args['ID'] = $event_id;
		$saved_id        = wp_update_post( $post_args, true );
	} else {
		$saved_id = wp_insert_post( $post_args, true );
	}

	if ( is_wp_error( $saved_id ) ) {
		return $saved_id;
	}
	$saved_id = (int) $saved_id;

	// Datetimes — pass through GatherPress's own writer.
	$timezone = wp_timezone_string();
	$start    = sprintf( '%s %s:00', $fields['date'], $fields['time_start'] );
	$end      = sprintf( '%s %s:00', resolve_event_end_date( $fields['date'], $fields['time_start'], $fields['time_end'] ), $fields['time_end'] );

	$event = new Event( $saved_id );
	$event->save_datetimes(
		array(
			'post_id'        => $saved_id,
			'datetime_start' => $start,
			'datetime_end'   => $end,
			'timezone'       => $timezone,
		)
	);

	// Physical and online venues.
	$venue_id = resolve_venue_id( $fields );
	sync_event_venue_terms( $saved_id, $venue_id, $fields['is_online'] );
	sync_online_event_link( $saved_id, $fields['is_online'], $fields['online_event_link'] );

	// Featured image — only if the current user is actually allowed to see
	// it (public/inherited attachments, or their own private uploads).
	if ( $fields['featured_image_id'] > 0 && current_user_can_use_attachment( $fields['featured_image_id'] ) ) {
		set_post_thumbnail( $saved_id, $fields['featured_image_id'] );
	} elseif ( 0 === $fields['featured_image_id'] && $event_id > 0 ) {
		// Explicit clear on edit.
		delete_post_thumbnail( $saved_id );
	}

	maybe_save_rsvp_questions( $saved_id, $request );

	return new WP_REST_Response(
		array(
			'id'        => $saved_id,
			'permalink' => get_permalink( $saved_id ),
			'title'     => get_the_title( $saved_id ),
		)
	);
}

/**
 * Build the new `post_content` for an event.
 *
 * On **create**, we trust the JS editor's serialised block markup verbatim.
 * On **edit**, we replace only the leading description blocks (paragraphs,
 * headings, lists, images) and leave any GatherPress metadata blocks
 * (event-date, venue, RSVP, etc.) intact so we don't clobber the seeded
 * event-rendering blocks the user might have customised in wp-admin.
 */
function build_post_content( int $event_id, string $description ): string {
	$description = wp_kses_post( wp_unslash( $description ) );

	if ( $event_id <= 0 ) {
		return $description;
	}

	$existing = (string) get_post_field( 'post_content', $event_id );
	$blocks   = parse_blocks( $existing );

	$kept = array_filter(
		$blocks,
		static function ( $block ) {
			return ! in_array( $block['blockName'], DESCRIPTION_BLOCK_NAMES, true );
		}
	);

	return $description . "\n\n" . serialize_blocks( array_values( $kept ) );
}

/**
 * Find a venue post ID for the submission, creating one inline if needed.
 *
 * The address is stored via the `gatherpress_address` post meta (not
 * `post_content`) so GatherPress's own async geocode handler — hooked on
 * `added_post_meta`/`updated_post_meta` for that key — picks it up and
 * populates `gatherpress_latitude`/`gatherpress_longitude`. Writing the
 * address straight into `post_content` bypasses that pipeline entirely and
 * leaves the venue with no coordinates, breaking map rendering.
 */
function resolve_venue_id( array $fields ): int {
	if ( $fields['venue_id'] > 0 && Venue::POST_TYPE === get_post_type( $fields['venue_id'] ) ) {
		return $fields['venue_id'];
	}

	if ( '' === $fields['new_venue_name'] ) {
		return 0;
	}

	$venue_post_id = wp_insert_post(
		array(
			'post_type'   => Venue::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $fields['new_venue_name'],
		),
		true
	);

	if ( is_wp_error( $venue_post_id ) || ! $venue_post_id ) {
		return 0;
	}

	if ( '' !== $fields['new_venue_address'] ) {
		update_post_meta( $venue_post_id, 'gatherpress_address', $fields['new_venue_address'] );
	}

	return (int) $venue_post_id;
}

/**
 * Synchronize an event's physical venue and online-event terms.
 *
 * GatherPress models hybrid events by assigning both a physical venue's
 * shadow term and the `online-event` sentinel term. Replacing the full term
 * set here also clears a previously selected physical venue when an event is
 * changed to online-only.
 *
 * @param int  $event_id     Event post ID.
 * @param int  $venue_post_id Physical venue post ID, or zero for none.
 * @param bool $is_online    Whether to assign the online-event term.
 */
function sync_event_venue_terms( int $event_id, int $venue_post_id, bool $is_online ): void {
	$taxonomy = Venue_Setup::get_instance()->taxonomy_for_event_post_type( Event::POST_TYPE );
	$term_ids = array();

	if ( $venue_post_id > 0 ) {
		$venue = new Venue( $venue_post_id );
		$term  = $venue->get_term();

		if ( $term ) {
			$term_ids[] = (int) $term->term_id;
		}
	}

	if ( $is_online ) {
		$online_term = term_exists( 'online-event', $taxonomy );
		if ( is_array( $online_term ) ) {
			$term_ids[] = (int) $online_term['term_id'];
		} elseif ( is_numeric( $online_term ) ) {
			$term_ids[] = (int) $online_term;
		}
	}

	wp_set_object_terms( $event_id, $term_ids, $taxonomy, false );
}

/**
 * Synchronize the online meeting link with the event's online state.
 *
 * @param int    $event_id         Event post ID.
 * @param bool   $is_online        Whether the event is online.
 * @param string $online_event_link Online meeting URL.
 */
function sync_online_event_link( int $event_id, bool $is_online, string $online_event_link ): void {
	if ( $is_online && '' !== $online_event_link ) {
		update_post_meta( $event_id, 'gatherpress_online_event_link', esc_url_raw( $online_event_link ) );
		return;
	}

	delete_post_meta( $event_id, 'gatherpress_online_event_link' );
}
