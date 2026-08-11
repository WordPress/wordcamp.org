<?php
/**
 * Loads GatherPress Recurring Events and integrates it with the Groups frontend.
 *
 * The plugin itself lives at `mu-plugins/gatherpress-recurring-events/` so its
 * asset paths and folder structure stay self-contained. This file is picked up
 * by `wcorg_include_network_only_plugins()` because it sits in the `groups/`
 * network folder, which only loads when `SITE_ID_CURRENT_SITE === GROUPS_NETWORK_ID`.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordCamp\Groups;

use DateTimeImmutable;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WordPressdotorg\GatherPress_Recurring_Events\Plugin;
use WordPressdotorg\GatherPress_Recurring_Events\Rule;
use WP_Error;
use WP_REST_Request;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/gatherpress-recurring-events/plugin.php';

const RECURRING_EVENT_META_FIELDS = array(
	'interval',
	'weekdays',
	'monthly_mode',
	'monthly_day',
	'monthly_order',
	'monthly_weekday',
	'end_type',
	'until',
	'count',
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( Context::class ) ) {
			return;
		}

		add_filter( 'render_block_wporg/event-rsvp', array( Context::class, 'render_rsvp_block' ), 50 );
		add_filter( 'wporg_groups_frontend_event_args_schema', __NAMESPACE__ . '\\recurring_event_args_schema' );
		add_filter( 'wporg_groups_frontend_event_form_fields', __NAMESPACE__ . '\\recurring_event_form_fields', 10, 3 );
		add_filter( 'wporg_groups_frontend_validate_event_request', __NAMESPACE__ . '\\validate_recurring_event_request', 10, 3 );
		add_action( 'wporg_groups_frontend_event_draft_saved', __NAMESPACE__ . '\\save_recurring_event_draft', 10, 2 );
		add_action( 'wporg_groups_frontend_event_saved', __NAMESPACE__ . '\\save_recurring_event', 10, 3 );
		add_filter( 'wporg_groups_frontend_before_rsvp', __NAMESPACE__ . '\\set_rsvp_occurrence_context', 10, 3 );
	},
	30
);

/**
 * Adds recurrence data to the frontend event REST schema.
 *
 * @param array $args Existing REST argument schema.
 * @return array Filtered schema.
 */
function recurring_event_args_schema( array $args ): array {
	$args['recurrence'] = array(
		'description' => 'Optional recurrence rule for a new event or draft.',
		'type'        => 'object',
		'required'    => false,
		'properties'  => array(
			'frequency'       => array(
				'type' => 'string', 'enum' => array_merge( array( '' ), Rule::frequencies() ),
			),
			'interval'        => array(
				'type' => 'integer', 'minimum' => 1,
			),
			'weekdays'        => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string', 'enum' => Rule::weekdays(),
				),
			),
			'monthly_mode'    => array(
				'type' => 'string', 'enum' => array( 'day', 'weekday' ),
			),
			'monthly_day'     => array(
				'type' => 'integer', 'minimum' => 1, 'maximum' => 31,
			),
			'monthly_order'   => array(
				'type' => 'string', 'enum' => array( 'first', 'second', 'third', 'fourth', 'last' ),
			),
			'monthly_weekday' => array(
				'type' => 'string', 'enum' => Rule::weekdays(),
			),
			'end_type'        => array(
				'type' => 'string', 'enum' => array( 'never', 'until', 'count' ),
			),
			'until'           => array(
				'type' => 'string', 'pattern' => '^(?:|\\d{4}-\\d{2}-\\d{2})$',
			),
			'count'           => array(
				'type' => 'integer', 'minimum' => 1,
			),
		),
	);

	return $args;
}

/**
 * Adds recurrence defaults or saved values to the frontend event form.
 *
 * @param array $fields     Existing form fields.
 * @param int   $event_id   Existing event ID, or zero when creating.
 * @param bool  $is_editing Whether an existing event is being edited.
 * @return array Filtered form fields.
 */
function recurring_event_form_fields( array $fields, int $event_id, bool $is_editing ): array {
	$date = (string) ( $fields['date'] ?? '' );
	$rule = $event_id > 0 ? Rule::from_post( $event_id ) : normalize_recurring_event_rule( array(), $date );

	if ( $event_id > 0 && ! metadata_exists( 'post', $event_id, Rule::META_PREFIX . 'count' ) ) {
		$rule['count'] = 12;
	}

	$fields['recurrence'] = array_merge(
		$rule,
		array(
			'available' => true,
			'locked'    => $is_editing && 'publish' === get_post_status( $event_id ),
		)
	);

	return $fields;
}

/**
 * Validates recurrence fields before publishing an event.
 *
 * @param null|WP_Error   $error    Existing validation error.
 * @param WP_REST_Request $request  REST request.
 * @param int             $event_id Existing event ID, or zero when creating.
 * @return null|WP_Error Validation result.
 */
function validate_recurring_event_request( $error, WP_REST_Request $request, int $event_id ) {
	$input = $request->get_param( 'recurrence' );

	if ( is_wp_error( $error ) || ( $event_id > 0 && 'publish' === get_post_status( $event_id ) ) || null === $input ) {
		return $error;
	}

	if ( ! is_array( $input ) ) {
		return new WP_Error( 'wporg_groups_invalid_recurrence', 'Recurrence must be an object.', array( 'status' => 400 ) );
	}

	$frequency = sanitize_key( (string) ( $input['frequency'] ?? '' ) );
	if ( '' === $frequency ) {
		return $error;
	}

	if ( ! in_array( $frequency, Rule::frequencies(), true ) ) {
		return new WP_Error( 'wporg_groups_invalid_recurrence', 'Unsupported recurrence frequency.', array( 'status' => 400 ) );
	}

	$end_type = sanitize_key( (string) ( $input['end_type'] ?? 'never' ) );
	if ( ! in_array( $end_type, array( 'never', 'until', 'count' ), true ) ) {
		return new WP_Error( 'wporg_groups_invalid_recurrence', 'Unsupported recurrence end condition.', array( 'status' => 400 ) );
	}

	if ( 'until' === $end_type ) {
		$until = sanitize_text_field( (string) ( $input['until'] ?? '' ) );
		$date  = sanitize_text_field( (string) $request->get_param( 'date' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) || $until < $date ) {
			return new WP_Error( 'wporg_groups_invalid_recurrence_end', 'The recurrence end date must be on or after the event date.', array( 'status' => 400 ) );
		}
	}

	return $error;
}

/**
 * Saves recurrence metadata while the frontend modal autosaves a draft.
 *
 * @param int             $post_id Saved draft ID.
 * @param WP_REST_Request $request REST request.
 */
function save_recurring_event_draft( int $post_id, WP_REST_Request $request ): void {
	persist_recurring_event_rule( $post_id, $request, false );
}

/**
 * Saves and projects recurrence metadata after frontend publication.
 *
 * @param int             $post_id          Saved event ID.
 * @param WP_REST_Request $request           REST request.
 * @param bool            $schedule_editable Whether the schedule may still be initialized.
 */
function save_recurring_event( int $post_id, WP_REST_Request $request, bool $schedule_editable ): void {
	if ( ! $schedule_editable ) {
		return;
	}

	// The post is already `publish` by the time this fires, so the extension's
	// published-schedule lock would block the very write the caller was
	// entitled to make. `$schedule_editable` records that entitlement as of
	// before the transition, so honour it.
	Plugin::with_schedule_unlocked(
		$post_id,
		static function () use ( $post_id, $request ) {
			persist_recurring_event_rule( $post_id, $request, true );
		}
	);
}

/**
 * Persists a normalized recurrence rule and optionally projects occurrences.
 *
 * @param int             $post_id Saved event ID.
 * @param WP_REST_Request $request REST request.
 * @param bool            $project Whether to project occurrence rows.
 */
function persist_recurring_event_rule( int $post_id, WP_REST_Request $request, bool $project ): void {
	$input = $request->get_param( 'recurrence' );
	if ( ! is_array( $input ) ) {
		return;
	}

	$rule = normalize_recurring_event_rule( $input, (string) $request->get_param( 'date' ) );
	if ( '' === $rule['frequency'] ) {
		foreach ( array_merge( array( 'frequency', 'rrule' ), RECURRING_EVENT_META_FIELDS ) as $field ) {
			delete_post_meta( $post_id, Rule::META_PREFIX . $field );
		}
		Database::delete_series( $post_id );
		return;
	}

	foreach ( RECURRING_EVENT_META_FIELDS as $field ) {
		update_post_meta( $post_id, Rule::META_PREFIX . $field, $rule[ $field ] );
	}
	update_post_meta( $post_id, Rule::META_PREFIX . 'frequency', $rule['frequency'] );

	if ( $project ) {
		Database::maybe_install();
		Occurrences::project( $post_id );
	}
}

/**
 * Normalizes recurrence input using the event date for sensible defaults.
 *
 * @param array  $input Submitted recurrence fields.
 * @param string $date  Event start date.
 * @return array Normalized rule.
 */
function normalize_recurring_event_rule( array $input, string $date ): array {
	$start           = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
	$weekday         = $start ? strtoupper( substr( $start->format( 'D' ), 0, 2 ) ) : 'MO';
	$day             = $start ? (int) $start->format( 'j' ) : 1;
	$weekdays        = is_array( $input['weekdays'] ?? null ) ? $input['weekdays'] : array();
	$weekdays        = array_filter( $weekdays, 'is_scalar' );
	$weekdays        = array_values( array_intersect( Rule::weekdays(), array_map( static fn( $value ): string => strtoupper( sanitize_key( $value ) ), $weekdays ) ) );
	$frequency       = sanitize_key( (string) ( $input['frequency'] ?? '' ) );
	$monthly_day     = min( 31, max( 1, (int) ( $input['monthly_day'] ?? $day ) ) );
	$monthly_mode    = sanitize_key( (string) ( $input['monthly_mode'] ?? 'day' ) );
	$monthly_order   = sanitize_key( (string) ( $input['monthly_order'] ?? 'first' ) );
	$monthly_weekday = strtoupper( sanitize_key( (string) ( $input['monthly_weekday'] ?? $weekday ) ) );
	$end_type        = sanitize_key( (string) ( $input['end_type'] ?? 'never' ) );

	if ( 'weekly' === $frequency && empty( $weekdays ) ) {
		$weekdays = array( $weekday );
	}

	return array(
		'frequency'       => in_array( $frequency, Rule::frequencies(), true ) ? $frequency : '',
		'interval'        => max( 1, (int) ( $input['interval'] ?? 1 ) ),
		'weekdays'        => $weekdays,
		'monthly_mode'    => in_array( $monthly_mode, array( 'day', 'weekday' ), true ) ? $monthly_mode : 'day',
		'monthly_day'     => $monthly_day,
		'monthly_order'   => in_array( $monthly_order, array( 'first', 'second', 'third', 'fourth', 'last' ), true ) ? $monthly_order : 'first',
		'monthly_weekday' => in_array( $monthly_weekday, Rule::weekdays(), true ) ? $monthly_weekday : $weekday,
		'end_type'        => in_array( $end_type, array( 'never', 'until', 'count' ), true ) ? $end_type : 'never',
		'until'           => sanitize_text_field( (string) ( $input['until'] ?? '' ) ),
		'count'           => max( 1, (int) ( $input['count'] ?? 12 ) ),
	);
}

/**
 * Resolves and activates occurrence context for an RSVP request.
 *
 * REST requests never fire `template_redirect`, so `Context::resolve()`
 * never runs for them — without this, an RSVP submitted from an occurrence
 * page is saved as an unscoped comment that the occurrence-scoped attendee
 * list then filters out, making it look like the RSVP was never saved.
 *
 * @param null|WP_Error   $error    Existing validation error.
 * @param int             $event_id Series post ID.
 * @param WP_REST_Request $request  REST request.
 * @return null|WP_Error Validation result.
 */
function set_rsvp_occurrence_context( $error, int $event_id, WP_REST_Request $request ) {
	if ( is_wp_error( $error ) ) {
		return $error;
	}

	$recurrence_id = sanitize_text_field( (string) $request->get_param( 'recurrence_id' ) );
	if ( '' === $recurrence_id ) {
		return $error;
	}

	$occurrence = Occurrences::get( $event_id, $recurrence_id );
	if ( ! $occurrence || 'cancelled' === $occurrence->status ) {
		return new WP_Error( 'wporg_groups_invalid_recurrence', 'This occurrence is not available for RSVP.', array( 'status' => 400 ) );
	}

	Context::set( $occurrence );

	return $error;
}
