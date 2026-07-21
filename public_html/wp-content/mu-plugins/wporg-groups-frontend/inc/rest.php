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
 * All routes require the `current_user_can_manage_events()` capability,
 * plus post-specific capabilities when operating on an existing event.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\REST;

defined( 'WPINC' ) || die();

use GatherPress\Core\Event\Event;
use GatherPress\Core\Venue\Venue;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WordCamp\Groups\Frontend\Defaults\DESCRIPTION_BLOCK_NAMES;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_events;
use function WordCamp\Groups\Frontend\Defaults\extract_description_blocks;
use function WordCamp\Groups\Frontend\Defaults\get_default_event_data;
use function WordCamp\Groups\Frontend\Defaults\get_event_venue_post_id;

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
						return Event::POST_TYPE === get_post_type( (int) $param );
					},
				),
			) + event_args_schema(),
		)
	);
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
	);
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

	// Venue — same path as the publish flow.
	$venue_id = resolve_venue_id(
		array(
			'venue_id'          => (int) $request->get_param( 'venue_id' ),
			'new_venue_name'    => (string) $request->get_param( 'new_venue_name' ),
			'new_venue_address' => (string) $request->get_param( 'new_venue_address' ),
		)
	);
	if ( $venue_id > 0 ) {
		assign_venue_to_event( $saved_id, $venue_id );
	}

	// Featured image.
	$featured_image_id = (int) $request->get_param( 'featured_image_id' );
	if ( $featured_image_id > 0 && 'attachment' === get_post_type( $featured_image_id ) ) {
		set_post_thumbnail( $saved_id, $featured_image_id );
	}

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

	// Venue.
	$venue_id = resolve_venue_id( $fields );
	if ( $venue_id > 0 ) {
		assign_venue_to_event( $saved_id, $venue_id );
	}

	// Featured image — accept any attachment that exists. The JS picker
	// returns ids it just pulled from the same media library, so any
	// extra ownership/capability check would just block the happy path.
	if ( $fields['featured_image_id'] > 0 && 'attachment' === get_post_type( $fields['featured_image_id'] ) ) {
		set_post_thumbnail( $saved_id, $fields['featured_image_id'] );
	} elseif ( 0 === $fields['featured_image_id'] && $event_id > 0 ) {
		// Explicit clear on edit.
		delete_post_thumbnail( $saved_id );
	}

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
			'post_type'    => Venue::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $fields['new_venue_name'],
			'post_content' => $fields['new_venue_address'],
		),
		true
	);

	return ( is_wp_error( $venue_post_id ) || ! $venue_post_id ) ? 0 : (int) $venue_post_id;
}

/**
 * Assign a venue to an event by setting the `_gatherpress_venue` term whose
 * slug matches the venue post.
 */
function assign_venue_to_event( int $event_id, int $venue_post_id ): void {
	$venue_post = get_post( $venue_post_id );
	if ( ! $venue_post ) {
		return;
	}

	$term_slug = '_' . $venue_post->post_name;
	$term      = get_term_by( 'slug', $term_slug, Venue::TAXONOMY );

	if ( ! $term ) {
		$inserted = wp_insert_term(
			$venue_post->post_title,
			Venue::TAXONOMY,
			array( 'slug' => $term_slug )
		);
		if ( is_wp_error( $inserted ) ) {
			return;
		}
		$term = get_term( (int) $inserted['term_id'], Venue::TAXONOMY );
	}

	if ( ! $term || is_wp_error( $term ) ) {
		return;
	}

	wp_set_object_terms( $event_id, array( (int) $term->term_id ), Venue::TAXONOMY, false );
}
