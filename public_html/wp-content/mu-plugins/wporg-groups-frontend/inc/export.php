<?php
/**
 * Event + RSVP history export for group organisers.
 *
 * Namespace: `wporg-groups/v1`
 *
 *   GET /export?format={csv|json}
 *       Returns every published event on this group site with its full RSVP
 *       history, either as a flat CSV download or as nested JSON. Organiser
 *       tier only (`current_user_can_manage_group_settings()`).
 *
 * Anonymous RSVPs are exported as a salted, non-reversible token instead of
 * the member's name — the attendee asked not to be identified, and an export
 * travels further than the site does.
 *
 * This covers the per-group half of the export feature; the network-wide,
 * aggregate-only export is a separate follow-up.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Export;

defined( 'WPINC' ) || die();

use GatherPress\Core\Event\Event;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WordCamp\Groups\Frontend\REST\NAMESPACE_V1;

use function WordCamp\Groups\Frontend\Capabilities\current_user_can_manage_group_settings;

/**
 * Column order for the CSV export. One row per RSVP, with the event columns
 * repeated; events with no RSVPs still emit one row so they stay visible.
 */
const CSV_COLUMNS = array(
	'event_id',
	'event_title',
	'event_start_gmt',
	'event_end_gmt',
	'venue',
	'organiser',
	'attending_count',
	'waiting_list_count',
	'not_attending_count',
	'occurrence_start_gmt',
	'occurrence_end_gmt',
	'attendee_name',
	'attendee_login',
	'rsvp_status',
	'rsvp_timestamp_gmt',
	'rsvp_guests',
);

/**
 * Hook the export route into rest_api_init.
 */
function bootstrap(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
	add_filter( 'rest_pre_serve_request', __NAMESPACE__ . '\serve_raw_csv', 10, 3 );
}

/**
 * Register the export route.
 */
function register_routes(): void {
	register_rest_route(
		NAMESPACE_V1,
		'/export',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\get_export',
			'permission_callback' => __NAMESPACE__ . '\export_permissions_check',
			'args'                => array(
				'format'  => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => 'csv',
					'enum'              => array( 'csv', 'json' ),
					'sanitize_callback' => 'sanitize_key',
					// `enum` isn't enforced unless a validate_callback runs it.
					'validate_callback' => 'rest_validate_request_arg',
				),
				'columns' => array(
					'type'              => 'array',
					'required'          => false,
					'default'           => array(),
					'items'             => array(
						'type' => 'string',
						'enum' => CSV_COLUMNS,
					),
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'events'  => array(
					'type'              => 'array',
					'required'          => false,
					'default'           => array(),
					'items'             => array(
						'type' => 'integer',
					),
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'range'   => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => 'all',
					'enum'              => array( 'all', 'upcoming', 'past', 'custom' ),
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'after'   => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => __NAMESPACE__ . '\validate_date_param',
				),
				'before'  => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => __NAMESPACE__ . '\validate_date_param',
				),
			),
		)
	);
}

/**
 * Whether a date filter param is empty or a real `Y-m-d` calendar date.
 *
 * A shape-only regex would accept impossible dates like `2026-99-99`, which
 * PHP would silently roll over into a different date; the format round-trip
 * rejects them with the route's 400 instead.
 *
 * @param mixed $param The raw param value.
 */
function validate_date_param( $param ): bool {
	if ( '' === $param ) {
		return true;
	}

	$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $param );

	return $parsed && $parsed->format( 'Y-m-d' ) === (string) $param;
}

/**
 * Capability check for the export route.
 *
 * Distinguishes "not logged in" from "not an Organiser" so clients get the
 * right status code, matching `Members_Controller`'s permission style.
 *
 * @return true|WP_Error
 */
function export_permissions_check() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'You must be logged in.', 'wporg-groups-frontend' ),
			array( 'status' => 401 )
		);
	}

	if ( ! current_user_can_manage_group_settings() ) {
		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to export this group\'s data.', 'wporg-groups-frontend' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	return true;
}

/**
 * Route callback: build the export in the requested format.
 *
 * @param WP_REST_Request $request The request.
 */
function get_export( WP_REST_Request $request ): WP_REST_Response {
	$data = collect_export_data(
		array(
			'events' => array_map( 'absint', (array) $request->get_param( 'events' ) ),
			'range'  => (string) $request->get_param( 'range' ),
			'after'  => (string) $request->get_param( 'after' ),
			'before' => (string) $request->get_param( 'before' ),
		)
	);

	// An empty selection means "all columns"; a subset is reordered into the
	// canonical column order so the output is stable regardless of the order
	// the client sent them in.
	$columns = (array) $request->get_param( 'columns' );
	$columns = $columns ? array_values( array_intersect( CSV_COLUMNS, $columns ) ) : CSV_COLUMNS;

	$format   = $request->get_param( 'format' );
	$filename = sprintf(
		'%s-events-%s.%s',
		sanitize_title( get_bloginfo( 'name' ) ) ?: 'group',
		gmdate( 'Y-m-d' ),
		$format
	);

	if ( 'json' === $format ) {
		$response = new WP_REST_Response( filter_json_fields( $data, $columns ) );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );

		return $response;
	}

	$response = new WP_REST_Response( build_csv( $data, $columns ) );
	$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
	$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );

	return $response;
}

/**
 * Serve the CSV body raw instead of JSON-encoded.
 *
 * The REST server JSON-encodes every response body; for the CSV format the
 * body is already the finished file, so echo it as-is. Scoped to this route
 * only — everything else keeps the default serving.
 *
 * @param bool             $served  Whether the request has already been served.
 * @param WP_REST_Response $result  The response object.
 * @param WP_REST_Request  $request The request.
 */
function serve_raw_csv( bool $served, $result, $request ): bool {
	if ( $served || '/' . NAMESPACE_V1 . '/export' !== $request->get_route() ) {
		return $served;
	}

	$content_type = $result->get_headers()['Content-Type'] ?? '';

	if ( ! str_starts_with( $content_type, 'text/csv' ) ) {
		return $served;
	}

	echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file body; cells are escaped by esc_csv_cell().

	return true;
}

/**
 * Assemble the full export: every published event with dates, venue,
 * organiser, per-status RSVP counts, and the individual RSVP records.
 *
 * Everything is fetched in a fixed number of bulk queries — one per data
 * source — so the cost doesn't grow per event or per RSVP.
 *
 * @param array $filters {
 *     Optional. Narrows which events are exported.
 *
 *     @type int[]  $events Event post IDs to export. Empty means all.
 *     @type string $range  One of 'all', 'upcoming', 'past', 'custom'.
 *     @type string $after  `Y-m-d`; with range 'custom', keep events starting on/after this day.
 *     @type string $before `Y-m-d`; with range 'custom', keep events starting on/before this day.
 * }
 *
 * @return array{generated_gmt: string, group: array{name: string, url: string}, events: array[]}
 */
function collect_export_data( array $filters = array() ): array {
	$filters += array(
		'events' => array(),
		'range'  => 'all',
		'after'  => '',
		'before' => '',
	);

	$query = array(
		'post_type'      => Event::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	);

	if ( ! empty( $filters['events'] ) ) {
		$query['post__in'] = array_map( 'intval', $filters['events'] );
	}

	$events = get_posts( $query );

	// The date filter needs the dates table, so it runs after that one query;
	// everything else is only fetched for the events that survive it.
	$dates  = get_event_dates( wp_list_pluck( $events, 'ID' ) );
	$events = filter_events_by_range( $events, $dates, $filters['range'], $filters['after'], $filters['before'] );

	$event_ids  = wp_list_pluck( $events, 'ID' );
	$venues     = get_event_venue_names( $event_ids );
	$rsvps      = get_event_rsvps( $event_ids );
	$occurrence = get_occurrence_data( $event_ids );

	// Resolve organiser and attendee names in one user query.
	$user_ids = array_merge(
		array_map( 'intval', wp_list_pluck( $events, 'post_author' ) ),
		wp_list_pluck( $rsvps, 'user_id' )
	);
	$users    = get_users_by_id( array_filter( array_unique( $user_ids ) ) );

	$rsvps_by_event = array();
	foreach ( $rsvps as $rsvp ) {
		$rsvps_by_event[ $rsvp['event_id'] ][] = $rsvp;
	}

	$export_events = array();
	foreach ( $events as $event ) {
		$event_id    = (int) $event->ID;
		$event_rsvps = $rsvps_by_event[ $event_id ] ?? array();
		$counts      = array(
			'attending'     => 0,
			'waiting_list'  => 0,
			'not_attending' => 0,
		);

		$export_rsvps = array();
		foreach ( $event_rsvps as $rsvp ) {
			if ( isset( $counts[ $rsvp['status'] ] ) ) {
				++$counts[ $rsvp['status'] ];
			}

			$occurrence_key  = $occurrence['map'][ $rsvp['comment_id'] ] ?? '';
			$rsvp_occurrence = $occurrence['occurrences'][ $occurrence_key ] ?? null;
			$attendee        = $users[ $rsvp['user_id'] ] ?? null;

			if ( $rsvp['anonymous'] ) {
				$attendee_name  = anonymous_token( $rsvp['comment_id'] );
				$attendee_login = '';
			} else {
				$attendee_name  = $attendee->display_name ?? '';
				$attendee_login = $attendee->user_login ?? '';
			}

			$export_rsvps[] = array(
				'attendee_name'        => $attendee_name,
				'attendee_login'       => $attendee_login,
				'anonymous'            => $rsvp['anonymous'],
				'status'               => $rsvp['status'],
				'timestamp_gmt'        => $rsvp['timestamp_gmt'],
				'guests'               => $rsvp['guests'],
				'occurrence_start_gmt' => $rsvp_occurrence['start_gmt'] ?? null,
				'occurrence_end_gmt'   => $rsvp_occurrence['end_gmt'] ?? null,
			);
		}

		$event_occurrences = array_values(
			array_filter(
				$occurrence['occurrences'],
				static function ( $key ) use ( $event_id ) {
					return 0 === strpos( $key, $event_id . '|' );
				},
				ARRAY_FILTER_USE_KEY
			)
		);

		$export_events[] = array(
			'id'          => $event_id,
			// Raw title, not get_the_title(): texturizing and entity-encoding
			// belong to HTML output, not to a data export.
			'title'       => $event->post_title,
			'start_gmt'   => $dates[ $event_id ]['start_gmt'] ?? '',
			'end_gmt'     => $dates[ $event_id ]['end_gmt'] ?? '',
			'venue'       => $venues[ $event_id ] ?? '',
			'organiser'   => $users[ (int) $event->post_author ]->display_name ?? '',
			'is_recurring' => ! empty( $event_occurrences ),
			'counts'      => $counts,
			'occurrences' => $event_occurrences,
			'rsvps'       => $export_rsvps,
		);
	}

	// Soonest event first; events without a date row sort last.
	usort(
		$export_events,
		static function ( $a, $b ) {
			return strcmp( $a['start_gmt'] ?: '9999', $b['start_gmt'] ?: '9999' );
		}
	);

	return array(
		'generated_gmt' => gmdate( 'Y-m-d H:i:s' ),
		'group'         => array(
			'name' => get_bloginfo( 'name' ),
			'url'  => home_url(),
		),
		'events'        => $export_events,
	);
}

/**
 * Reduce events to the requested date range.
 *
 * Compares against the GatherPress dates table rows (GMT). Events without a
 * dates row can't be placed in time, so any range other than 'all' drops
 * them. Recurring series are judged by their series dates row, the same way
 * the events archive treats them.
 *
 * @param \WP_Post[] $events Candidate events.
 * @param array      $dates  Start/end rows from `get_event_dates()`, keyed by event ID.
 * @param string     $range  One of 'all', 'upcoming', 'past', 'custom'.
 * @param string     $after  `Y-m-d` lower bound for 'custom', or empty.
 * @param string     $before `Y-m-d` upper bound for 'custom', or empty.
 *
 * @return \WP_Post[]
 */
function filter_events_by_range( array $events, array $dates, string $range, string $after, string $before ): array {
	if ( 'all' === $range ) {
		return $events;
	}

	$now = current_time( 'mysql', true );

	return array_values(
		array_filter(
			$events,
			static function ( $event ) use ( $dates, $range, $after, $before, $now ) {
				$row = $dates[ (int) $event->ID ] ?? null;

				if ( ! $row ) {
					return false;
				}

				if ( 'upcoming' === $range ) {
					return $row['end_gmt'] >= $now;
				}

				if ( 'past' === $range ) {
					return $row['end_gmt'] < $now;
				}

				// 'custom': either bound may be open.
				if ( '' !== $after && $row['start_gmt'] < $after . ' 00:00:00' ) {
					return false;
				}

				if ( '' !== $before && $row['start_gmt'] > $before . ' 23:59:59' ) {
					return false;
				}

				return true;
			}
		)
	);
}

/**
 * Drop unselected fields from the JSON export.
 *
 * Column selection is defined in CSV terms (the names the UI shows); this
 * maps each CSV column to the JSON fields it covers so both formats honour
 * the same selection. The `anonymous` flag travels with `attendee_name`,
 * and the series occurrence list with either occurrence column. With no
 * RSVP-level column selected the `rsvps` list is dropped entirely.
 *
 * @param array    $data    Export data from `collect_export_data()`.
 * @param string[] $columns Selected CSV column names, in canonical order.
 */
function filter_json_fields( array $data, array $columns ): array {
	if ( count( $columns ) === count( CSV_COLUMNS ) ) {
		return $data;
	}

	$selected = array_flip( $columns );

	$event_fields = array(
		'id'        => 'event_id',
		'title'     => 'event_title',
		'start_gmt' => 'event_start_gmt',
		'end_gmt'   => 'event_end_gmt',
		'venue'     => 'venue',
		'organiser' => 'organiser',
	);

	$count_fields = array(
		'attending'     => 'attending_count',
		'waiting_list'  => 'waiting_list_count',
		'not_attending' => 'not_attending_count',
	);

	$rsvp_fields = array(
		'attendee_name'        => 'attendee_name',
		'anonymous'            => 'attendee_name',
		'attendee_login'       => 'attendee_login',
		'status'               => 'rsvp_status',
		'timestamp_gmt'        => 'rsvp_timestamp_gmt',
		'guests'               => 'rsvp_guests',
		'occurrence_start_gmt' => 'occurrence_start_gmt',
		'occurrence_end_gmt'   => 'occurrence_end_gmt',
	);

	$keep_rsvps = (bool) array_intersect_key( $selected, array_flip( $rsvp_fields ) );

	foreach ( $data['events'] as &$event ) {
		foreach ( $event_fields as $field => $column ) {
			if ( ! isset( $selected[ $column ] ) ) {
				unset( $event[ $field ] );
			}
		}

		foreach ( $count_fields as $field => $column ) {
			if ( ! isset( $selected[ $column ] ) ) {
				unset( $event['counts'][ $field ] );
			}
		}

		if ( empty( $event['counts'] ) ) {
			unset( $event['counts'] );
		}

		// The series occurrence list stays when either occurrence column is
		// selected, trimmed to the selected value(s).
		if ( ! isset( $selected['occurrence_start_gmt'] ) && ! isset( $selected['occurrence_end_gmt'] ) ) {
			unset( $event['is_recurring'], $event['occurrences'] );
		} elseif ( isset( $event['occurrences'] ) ) {
			foreach ( $event['occurrences'] as &$series_occurrence ) {
				if ( ! isset( $selected['occurrence_start_gmt'] ) ) {
					unset( $series_occurrence['start_gmt'] );
				}
				if ( ! isset( $selected['occurrence_end_gmt'] ) ) {
					unset( $series_occurrence['end_gmt'] );
				}
			}
			unset( $series_occurrence );
		}

		if ( ! $keep_rsvps ) {
			unset( $event['rsvps'] );
			continue;
		}

		foreach ( $event['rsvps'] as &$rsvp ) {
			foreach ( $rsvp_fields as $field => $column ) {
				if ( ! isset( $selected[ $column ] ) ) {
					unset( $rsvp[ $field ] );
				}
			}
		}
		unset( $rsvp );
	}
	unset( $event );

	return $data;
}

/**
 * Event start/end times from GatherPress's dates table, keyed by event ID.
 *
 * The dates live in a custom table rather than post meta; one IN query beats
 * instantiating a GatherPress event object per post (same reasoning as
 * `My_Events\filter_to_upcoming()`).
 *
 * @param int[] $event_ids Event post IDs.
 *
 * @return array<int, array{start_gmt: string, end_gmt: string}>
 */
function get_event_dates( array $event_ids ): array {
	global $wpdb;

	if ( empty( $event_ids ) ) {
		return array();
	}

	$table        = sprintf( Event::TABLE_FORMAT, $wpdb->prefix );
	$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are generated locally.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- As above; the placeholder list is generated locally.
			"SELECT post_id, datetime_start_gmt, datetime_end_gmt FROM {$table} WHERE post_id IN ( {$placeholders} )",
			$event_ids
		)
	);

	$dates = array();
	foreach ( (array) $rows as $row ) {
		$dates[ (int) $row->post_id ] = array(
			'start_gmt' => $row->datetime_start_gmt,
			'end_gmt'   => $row->datetime_end_gmt,
		);
	}

	return $dates;
}

/**
 * Venue display names keyed by event ID.
 *
 * Events carry a shadow term in `_gatherpress_venue`: the slug is the venue
 * post's `post_name` with a leading underscore, except the `online-event`
 * sentinel, which has no backing post. Venue titles are resolved in one
 * batched post query; hybrid events (physical + online) list both.
 *
 * @param int[] $event_ids Event post IDs.
 *
 * @return array<int, string>
 */
function get_event_venue_names( array $event_ids ): array {
	if ( empty( $event_ids ) ) {
		return array();
	}

	$terms = wp_get_object_terms(
		$event_ids,
		'_gatherpress_venue',
		array( 'fields' => 'all_with_object_id' )
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	// Collect the venue post slugs behind the shadow terms.
	$post_names = array();
	foreach ( $terms as $term ) {
		if ( 'online-event' !== $term->slug ) {
			$post_names[] = ltrim( $term->slug, '_' );
		}
	}

	$titles_by_name = array();
	if ( $post_names ) {
		$venue_posts = get_posts(
			array(
				'post_type'      => 'gatherpress_venue',
				'post_status'    => 'any',
				'post_name__in'  => array_unique( $post_names ),
				'posts_per_page' => -1,
			)
		);

		foreach ( $venue_posts as $venue_post ) {
			$titles_by_name[ $venue_post->post_name ] = $venue_post->post_title;
		}
	}

	$venues = array();
	foreach ( $terms as $term ) {
		$event_id = (int) $term->object_id;

		if ( 'online-event' === $term->slug ) {
			$name = __( 'Online', 'wporg-groups-frontend' );
		} else {
			$name = $titles_by_name[ ltrim( $term->slug, '_' ) ] ?? $term->name;
		}

		$venues[ $event_id ] = isset( $venues[ $event_id ] )
			? $venues[ $event_id ] . ', ' . $name
			: $name;
	}

	return $venues;
}

/**
 * All approved RSVPs for the given events, as flat records.
 *
 * One comment query, one bulk status-term fetch, and one meta-cache prime —
 * the same shape as `My_Events\get_attending_event_ids()`, without the
 * per-status filtering.
 *
 * @param int[] $event_ids Event post IDs.
 *
 * @return array<int, array{comment_id: int, event_id: int, user_id: int, status: string, timestamp_gmt: string, guests: int, anonymous: bool}>
 */
function get_event_rsvps( array $event_ids ): array {
	if ( empty( $event_ids ) ) {
		return array();
	}

	$comments = get_comments(
		array(
			'post__in' => $event_ids,
			'type'     => 'gatherpress_rsvp',
			'status'   => 'approve',
			'number'   => 0,
			'orderby'  => 'comment_ID',
			'order'    => 'ASC',
		)
	);

	if ( empty( $comments ) ) {
		return array();
	}

	$comment_ids = array_map( 'intval', wp_list_pluck( $comments, 'comment_ID' ) );
	update_meta_cache( 'comment', $comment_ids );

	$status_terms = wp_get_object_terms(
		$comment_ids,
		'_gatherpress_rsvp_status',
		array( 'fields' => 'all_with_object_id' )
	);

	$status_by_comment = array();
	if ( ! is_wp_error( $status_terms ) ) {
		foreach ( $status_terms as $term ) {
			$status_by_comment[ (int) $term->object_id ] = $term->slug;
		}
	}

	$rsvps = array();
	foreach ( $comments as $comment ) {
		$comment_id = (int) $comment->comment_ID;

		$rsvps[] = array(
			'comment_id'    => $comment_id,
			'event_id'      => (int) $comment->comment_post_ID,
			'user_id'       => (int) $comment->user_id,
			'status'        => $status_by_comment[ $comment_id ] ?? 'no_status',
			'timestamp_gmt' => $comment->comment_date_gmt,
			'guests'        => (int) get_comment_meta( $comment_id, 'gatherpress_rsvp_guests', true ),
			'anonymous'     => (bool) get_comment_meta( $comment_id, 'gatherpress_rsvp_anonymous', true ),
		);
	}

	return $rsvps;
}

/**
 * Occurrence dates and RSVP→occurrence mapping from the recurring-events
 * plugin, when it's active.
 *
 * Queries the two tables directly with IN lists — `Occurrences::all()` works
 * per series, which would be one query per event. RSVPs without a mapping row
 * (non-recurring events, or RSVPs predating the plugin) simply get no
 * occurrence columns.
 *
 * @param int[] $event_ids Event post IDs.
 *
 * @return array{map: array<int, string>, occurrences: array<string, array{recurrence_id: string, start_gmt: string, end_gmt: string}>}
 */
function get_occurrence_data( array $event_ids ): array {
	global $wpdb;

	$empty = array(
		'map'         => array(),
		'occurrences' => array(),
	);

	if ( empty( $event_ids ) || ! class_exists( '\WordPressdotorg\GatherPress_Recurring_Events\Database' ) ) {
		return $empty;
	}

	$occurrences_table = \WordPressdotorg\GatherPress_Recurring_Events\Database::occurrences_table();
	$comments_table    = \WordPressdotorg\GatherPress_Recurring_Events\Database::comments_table();
	$placeholders      = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are generated locally.
	$occurrence_rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- As above.
			"SELECT series_post_id, recurrence_id, datetime_start_gmt, datetime_end_gmt FROM {$occurrences_table} WHERE series_post_id IN ( {$placeholders} ) ORDER BY datetime_start_gmt ASC",
			$event_ids
		)
	);

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
	$mapping_rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- As above.
			"SELECT comment_id, series_post_id, recurrence_id FROM {$comments_table} WHERE series_post_id IN ( {$placeholders} )",
			$event_ids
		)
	);

	$occurrences = array();
	foreach ( (array) $occurrence_rows as $row ) {
		$occurrences[ $row->series_post_id . '|' . $row->recurrence_id ] = array(
			'recurrence_id' => $row->recurrence_id,
			'start_gmt'     => $row->datetime_start_gmt,
			'end_gmt'       => $row->datetime_end_gmt,
		);
	}

	$map = array();
	foreach ( (array) $mapping_rows as $row ) {
		$map[ (int) $row->comment_id ] = $row->series_post_id . '|' . $row->recurrence_id;
	}

	return array(
		'map'         => $map,
		'occurrences' => $occurrences,
	);
}

/**
 * Users keyed by ID, fetched in one query.
 *
 * @param int[] $user_ids User IDs.
 *
 * @return array<int, \WP_User>
 */
function get_users_by_id( array $user_ids ): array {
	if ( empty( $user_ids ) ) {
		return array();
	}

	$users = array();

	// `blog_id => 0` looks the IDs up network-wide: someone can have RSVP'd
	// to this group's event without holding a role on the site, and their
	// RSVP row should still carry their name.
	foreach ( get_users( array(
		'include' => $user_ids, 'blog_id' => 0,
	) ) as $user ) {
		$users[ (int) $user->ID ] = $user;
	}

	return $users;
}

/**
 * Stable, non-identifying label for an anonymous RSVP.
 *
 * Salted so the token can't be reversed to a comment ID, but deterministic so
 * the same RSVP gets the same token on every export.
 *
 * @param int $comment_id The RSVP comment ID.
 */
function anonymous_token( int $comment_id ): string {
	return 'anonymous-' . substr( hash( 'sha256', $comment_id . wp_salt() ), 0, 12 );
}

/**
 * Flatten the export data into a CSV file body.
 *
 * @param array         $data    Export data from `collect_export_data()`.
 * @param string[]|null $columns Column names to emit, in canonical order.
 *                               Null means all of CSV_COLUMNS.
 */
function build_csv( array $data, ?array $columns = null ): string {
	$columns = $columns ?? CSV_COLUMNS;

	// phpcs:disable WordPress.WP.AlternativeFunctions -- php://temp is an in-memory stream for fputcsv(), not a filesystem write; WP_Filesystem doesn't apply.
	$handle = fopen( 'php://temp', 'r+' );

	// UTF-8 BOM so Excel detects the encoding.
	fwrite( $handle, "\xEF\xBB\xBF" );
	fputcsv( $handle, $columns, ',', '"', '\\' );

	foreach ( $data['events'] as $event ) {
		foreach ( empty( $event['rsvps'] ) ? array( null ) : $event['rsvps'] as $rsvp ) {
			$cells = csv_row_cells( $event, $rsvp );
			$line  = array();

			foreach ( $columns as $column ) {
				$line[] = $cells[ $column ];
			}

			fputcsv( $handle, $line, ',', '"', '\\' );
		}
	}

	rewind( $handle );
	$csv = stream_get_contents( $handle );
	fclose( $handle );
	// phpcs:enable WordPress.WP.AlternativeFunctions

	return $csv;
}

/**
 * The full set of CSV cells for one event/RSVP pair, keyed by column name.
 *
 * Zero-RSVP events pass a null RSVP and get blank RSVP cells, so they stay
 * visible in the file.
 *
 * @param array      $event One event from `collect_export_data()`.
 * @param array|null $rsvp  One of its RSVPs, or null for the event-only row.
 *
 * @return array<string, string|int>
 */
function csv_row_cells( array $event, ?array $rsvp ): array {
	return array(
		'event_id'             => $event['id'],
		'event_title'          => esc_csv_cell( $event['title'] ),
		'event_start_gmt'      => $event['start_gmt'],
		'event_end_gmt'        => $event['end_gmt'],
		'venue'                => esc_csv_cell( $event['venue'] ),
		'organiser'            => esc_csv_cell( $event['organiser'] ),
		'attending_count'      => $event['counts']['attending'],
		'waiting_list_count'   => $event['counts']['waiting_list'],
		'not_attending_count'  => $event['counts']['not_attending'],
		'occurrence_start_gmt' => $rsvp['occurrence_start_gmt'] ?? '',
		'occurrence_end_gmt'   => $rsvp['occurrence_end_gmt'] ?? '',
		'attendee_name'        => esc_csv_cell( $rsvp['attendee_name'] ?? '' ),
		'attendee_login'       => esc_csv_cell( $rsvp['attendee_login'] ?? '' ),
		'rsvp_status'          => $rsvp['status'] ?? '',
		'rsvp_timestamp_gmt'   => $rsvp['timestamp_gmt'] ?? '',
		'rsvp_guests'          => $rsvp['guests'] ?? '',
	);
}

/**
 * Neutralise spreadsheet formula injection in a CSV cell.
 *
 * A formula trigger (`=`, `+`, `-`, `@`) gets a leading apostrophe when it
 * starts the cell — but also when it follows any common delimiter, because
 * the reader chooses the delimiter on import (`;` is the default in some
 * locales), which can split one of our comma-quoted cells into several.
 * Same policy as CampTix's `esc_csv()`, implemented locally because CampTix
 * isn't loaded on group sites.
 *
 * @param string $value Cell value.
 */
function esc_csv_cell( string $value ): string {
	if ( '' === $value || is_numeric( $value ) ) {
		return $value;
	}

	$triggers   = array( '=', '+', '-', '@' );
	$delimiters = array( ',', ';', ':', '|', '^', "\n", "\r", "\t", ' ' );

	$escaped = '';

	// A leading delimiter itself gets escaped, so the cell can't smuggle an
	// extra empty column past a re-split.
	if ( in_array( mb_substr( $value, 0, 1 ), $delimiters, true ) ) {
		$escaped .= "'";
	}

	$length            = mb_strlen( $value );
	$prev_is_delimiter = true; // Start-of-cell counts as a boundary.

	for ( $i = 0; $i < $length; $i++ ) {
		$char = mb_substr( $value, $i, 1 );

		if ( $prev_is_delimiter && in_array( $char, $triggers, true ) ) {
			$escaped .= "'";
		}

		$escaped          .= $char;
		$prev_is_delimiter = in_array( $char, $delimiters, true );
	}

	return $escaped;
}
