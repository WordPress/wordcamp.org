<?php
/**
 * What the built-in REST endpoints of companies and sponsorships add for the vetting screen on Central: the meta
 * vetters read as it's stored, the values worked out for them, and a company's sponsorships.
 *
 * The endpoints themselves are core's, at `wp/v2/sv-companies` and `wp/v2/sv-sponsorships`. Only vetters can use
 * them: every capability of both post types is the vetters'.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Registers the company meta vetters read as it's stored.
 *
 * Read-only for vetters for now: the keys are protected, so without an `auth_callback` only super admins can write
 * them over REST.
 */
function register_company_meta(): void {
	if ( ! is_central() ) {
		return;
	}

	$keys = array(
		'_sv_contact_first_name',
		'_sv_contact_last_name',
		'_sv_contact_email',
		'_sv_gpl_findings',
		'_sv_trademark_findings',
		'_sv_not_eligible_until',
		'_sv_good_faith_due',
	);

	foreach ( $keys as $key ) {
		register_post_meta(
			COMPANY_POST_TYPE,
			$key,
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\register_company_meta' );

/**
 * Adds the values worked out for vetters to companies and sponsorships, as read-only fields.
 *
 * Each is only worked out when a request asks for it, so a list that names its fields skips the rest.
 */
function register_rest_fields(): void {
	if ( ! is_central() ) {
		return;
	}

	// The fields describe the records the way the screens do, with the screens' own helpers.
	require_once __DIR__ . '/admin.php';
	require_once __DIR__ . '/admin-companies.php';
	require_once __DIR__ . '/admin-sponsorships.php';

	foreach ( get_rest_fields() as $post_type => $fields ) {
		foreach ( $fields as $name => $field ) {
			register_rest_field(
				$post_type,
				$name,
				array(
					// The controller sets up the post it's preparing, whichever fields the request named.
					'get_callback' => static fn( array $data, string $attribute, \WP_REST_Request $request ): mixed => $field['get']( (int) get_the_ID(), $request ),
					'schema'       => $field['schema'] + array(
						'context'  => array( 'view', 'edit' ),
						'readonly' => true,
					),
				)
			);
		}
	}
}
add_action( 'rest_api_init', __NAMESPACE__ . '\register_rest_fields' );

/**
 * The read-only fields of companies and sponsorships.
 *
 * @return array[] For each post type, each field's `get` callback, which takes the post's ID and the request, and
 *                 its `schema`, keyed by the field's name.
 */
function get_rest_fields(): array {
	$log = array(
		'get'    => __NAMESPACE__ . '\get_rest_log',
		'schema' => array(
			'description' => __( 'Notes and status changes, newest first.', 'wordcamporg' ),
			'type'        => 'array',
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'time' => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'html' => array( 'type' => 'string' ),
				),
			),
		),
	);

	return array(
		COMPANY_POST_TYPE     => array(
			'domains'       => array(
				'get'    => __NAMESPACE__ . '\get_company_domains',
				'schema' => array(
					'description' => __( 'The company’s website domains.', 'wordcamporg' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
				),
			),
			'flags'         => array(
				'get'    => __NAMESPACE__ . '\get_company_flag_items',
				'schema' => array(
					'description' => __( 'What a reviewer should know beyond the status.', 'wordcamporg' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'type'    => array( 'type' => 'string' ),
							'text'    => array( 'type' => 'string' ),
							'url'     => array( 'type' => 'string' ),
							'date'    => array( 'type' => 'string' ),
							'overdue' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
			'next_event'    => array(
				'get'    => __NAMESPACE__ . '\get_rest_next_event',
				'schema' => array(
					'description' => __( 'When the soonest event among the pending sponsorships starts, as Y-m-d.', 'wordcamporg' ),
					'type'        => 'string',
				),
			),
			'sponsorships'  => array(
				'get'    => __NAMESPACE__ . '\get_rest_sponsorship_count',
				'schema' => array(
					'description' => __( 'How many sponsorships the company has.', 'wordcamporg' ),
					'type'        => 'integer',
				),
			),
			'last_vetted'   => array(
				'get'    => __NAMESPACE__ . '\get_rest_last_vetted',
				'schema' => array(
					'description' => __( 'When the company was last vetted, as Y-m-d.', 'wordcamporg' ),
					'type'        => 'string',
				),
			),
			'mes'           => array(
				'get'    => __NAMESPACE__ . '\get_rest_mes',
				'schema' => array(
					'description' => __( 'The name of the company’s Multi-Event Sponsor.', 'wordcamporg' ),
					'type'        => 'string',
				),
			),
			'conversations' => array(
				'get'    => __NAMESPACE__ . '\get_rest_conversations',
				'schema' => array(
					'description' => __( 'The emails sent about the company, newest first.', 'wordcamporg' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'type' => array( 'type' => 'string' ),
							'url'  => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'time' => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
					),
				),
			),
			'log'           => $log,
		),
		SPONSORSHIP_POST_TYPE => array(
			'event'    => array(
				'get'    => __NAMESPACE__ . '\get_rest_event',
				'schema' => array(
					'description' => __( 'The event the sponsorship is for.', 'wordcamporg' ),
					'type'        => 'object',
					'properties'  => array(
						'name' => array( 'type' => 'string' ),
						'date' => array( 'type' => 'string' ),
					),
				),
			),
			'approved' => array(
				'get'    => __NAMESPACE__ . '\get_rest_approval',
				'schema' => array(
					'description' => __( 'Who approved the sponsorship, and when.', 'wordcamporg' ),
					'type'        => 'string',
				),
			),
			'log'      => $log,
		),
	);
}

/**
 * Works a value out once per request, however many records the request lists.
 *
 * @param \WP_REST_Request $request The request.
 * @param string           $key     What the value is.
 * @param callable         $compute Works the value out.
 * @return mixed
 */
function remember( \WP_REST_Request $request, string $key, callable $compute ): mixed {
	static $memo = null;

	$memo ??= new \WeakMap();
	$values = $memo[ $request ] ?? array();

	if ( ! array_key_exists( $key, $values ) ) {
		$values[ $key ]   = $compute();
		$memo[ $request ] = $values;
	}

	return $values[ $key ];
}

/**
 * Gets when a company's next event starts.
 *
 * @param int              $company_id The company.
 * @param \WP_REST_Request $request    The request.
 * @return string The date, as `Y-m-d`, or an empty string for none.
 */
function get_rest_next_event( int $company_id, \WP_REST_Request $request ): string {
	$next = remember( $request, 'next_events', __NAMESPACE__ . '\get_next_events' );

	return isset( $next[ $company_id ] ) ? gmdate( 'Y-m-d', $next[ $company_id ]['start'] ) : '';
}

/**
 * Gets how many sponsorships a company has.
 *
 * @param int              $company_id The company.
 * @param \WP_REST_Request $request    The request.
 * @return int
 */
function get_rest_sponsorship_count( int $company_id, \WP_REST_Request $request ): int {
	return remember( $request, 'sponsorship_counts', __NAMESPACE__ . '\get_sponsorship_counts' )[ $company_id ] ?? 0;
}

/**
 * Gets when a company was last vetted.
 *
 * @param int $company_id The company.
 * @return string The date, as `Y-m-d`, or an empty string for never.
 */
function get_rest_last_vetted( int $company_id ): string {
	$last_vetted = (int) get_post_meta( $company_id, '_sv_last_vetted', true );

	return $last_vetted ? (string) wp_date( 'Y-m-d', $last_vetted ) : '';
}

/**
 * Gets the name of the Multi-Event Sponsor a company is linked to.
 *
 * @param int $company_id The company.
 * @return string The name, or an empty string for none.
 */
function get_rest_mes( int $company_id ): string {
	$mes = get_posts(
		array(
			'post_type'      => 'mes',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_key'       => '_sv_company_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One company's link.
			'meta_value'     => $company_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One company's link.
		)
	);

	return $mes ? wp_specialchars_decode( $mes[0]->post_title, ENT_QUOTES ) : '';
}

/**
 * Gets the emails sent about a company, newest first.
 *
 * @param int $company_id The company.
 * @return array[] Each conversation's `type`, `url` and `time`.
 */
function get_rest_conversations( int $company_id ): array {
	return array_map(
		static fn( array $conversation ): array => array(
			'type' => (string) $conversation['type'],
			'url'  => (string) $conversation['url'],
			'time' => gmdate( 'c', (int) $conversation['time'] ),
		),
		array_reverse( get_conversations( $company_id ) )
	);
}

/**
 * Gets a record's notes and status changes, newest first, as the screens word them.
 *
 * @param int $post_id The company or sponsorship.
 * @return array[] Each entry's `time` and what it says, as `html`.
 */
function get_rest_log( int $post_id ): array {
	$post_type = (string) get_post_type( $post_id );

	return array_map(
		static fn( array $entry ): array => array(
			'time' => gmdate( 'c', (int) $entry['time'] ),
			'html' => wp_kses_post( get_log_entry_html( $entry, $post_type ) ),
		),
		get_log( $post_id )
	);
}

/**
 * Gets the event a sponsorship is for.
 *
 * @param int              $sponsorship_id The sponsorship.
 * @param \WP_REST_Request $request        The request.
 * @return string[] The event's `name`, and its `date` as `Y-m-d` or an empty string.
 */
function get_rest_event( int $sponsorship_id, \WP_REST_Request $request ): array {
	$blog_id = (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true );

	return remember(
		$request,
		'event_' . $blog_id,
		static function () use ( $blog_id ): array {
			$start = get_event_dates( array( $blog_id ) )[ $blog_id ] ?? 0;

			return array(
				'name' => get_event_name( $blog_id ),
				'date' => $start ? gmdate( 'Y-m-d', $start ) : '',
			);
		}
	);
}

/**
 * Describes who approved a sponsorship and when.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return string The summary, or an empty string if it isn't approved.
 */
function get_rest_approval( int $sponsorship_id ): string {
	return get_post_meta( $sponsorship_id, '_sv_approved_at', true ) ? get_approval_summary( $sponsorship_id ) : '';
}

/**
 * Lets sponsorships be listed by company.
 *
 * Core only offers `parent` for hierarchical post types, but maps it to the query wherever it's registered.
 *
 * @param array $params The collection's parameters.
 * @return array
 */
function add_parent_param( array $params ): array {
	$params['parent'] = array(
		'description' => __( 'Limit the sponsorships to those of these companies.', 'wordcamporg' ),
		'type'        => 'array',
		'items'       => array( 'type' => 'integer' ),
		'default'     => array(),
	);

	return $params;
}
add_filter( 'rest_' . SPONSORSHIP_POST_TYPE . '_collection_params', __NAMESPACE__ . '\add_parent_param' );

/**
 * Refuses a status change over REST that the workflow doesn't allow.
 *
 * Otherwise enforce_status_transition() quietly keeps the old status, and the request looks like it worked.
 *
 * @param \stdClass|\WP_Error $prepared The post about to be saved.
 * @return \stdClass|\WP_Error
 */
function check_rest_status( \stdClass|\WP_Error $prepared ): \stdClass|\WP_Error {
	$post = is_wp_error( $prepared ) || empty( $prepared->ID ) ? null : get_post( (int) $prepared->ID );

	if ( ! $post || ! isset( $prepared->post_status ) || $prepared->post_status === $post->post_status ) {
		return $prepared;
	}

	$statuses = get_statuses( $post->post_type );
	$to       = (string) $prepared->post_status;

	if ( ! is_allowed_transition( $post->post_type, $post->post_status, $to ) ) {
		return new \WP_Error(
			'sv_status_not_allowed',
			sprintf(
				/* translators: 1: Current status. 2: Requested status. */
				__( 'The status can’t change from %1$s to %2$s.', 'wordcamporg' ),
				$statuses[ $post->post_status ] ?? $post->post_status,
				$statuses[ $to ] ?? $to
			),
			array( 'status' => 400 )
		);
	}

	if ( 'svs-approved' === $to && ! may_approve( $post->ID, (int) $post->post_parent ) ) {
		return new \WP_Error(
			'sv_not_approvable',
			__( 'The company must be Compliant, and vetted within the re-vetting period, before its sponsorships can be approved.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	return $prepared;
}
add_filter( 'rest_pre_insert_' . COMPANY_POST_TYPE, __NAMESPACE__ . '\check_rest_status' );
add_filter( 'rest_pre_insert_' . SPONSORSHIP_POST_TYPE, __NAMESPACE__ . '\check_rest_status' );
