<?php
/**
 * What the built-in REST endpoints of companies and sponsorships add for the vetting screen on Central.
 *
 * The endpoints themselves are core's, at `wp/v2/sv-companies` and `wp/v2/sv-sponsorships`, and the screen reads
 * and edits records through them. Only vetters can use them: every capability of both post types is the vetters'.
 * This file adds the company's meta, the fields worked out for vetters, the fields that do more than store a value
 * when they're written, the rules a status change has to keep, and the two emails, which aren't edits.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * The company values the screen reads and writes as they're stored.
 *
 * Each is a field of its own, so a request sends only the ones its vetter changed. As one `meta` object they'd all
 * travel together, as the screen loaded them, and undo what someone else saved since.
 *
 * @return array[] Each value's meta key and the callback that cleans it, keyed by the field's name.
 */
function get_company_values(): array {
	// Markdown, kept as written.
	$text = static fn( mixed $value ): string => (string) $value;
	$day  = __NAMESPACE__ . '\sanitize_day';

	return array(
		'contact_first_name' => array( '_sv_contact_first_name', 'sanitize_text_field' ),
		'contact_last_name'  => array( '_sv_contact_last_name', 'sanitize_text_field' ),
		'contact_email'      => array( '_sv_contact_email', static fn( mixed $value ): string => strtolower( sanitize_email( (string) $value ) ) ),
		'gpl_findings'       => array( '_sv_gpl_findings', $text ),
		'trademark_findings' => array( '_sv_trademark_findings', $text ),
		'not_eligible_until' => array( '_sv_not_eligible_until', $day ),
		'good_faith_due'     => array( '_sv_good_faith_due', $day ),
	);
}

/**
 * Keeps a day only as `Y-m-d`, which is how the plugin stores and compares its dates.
 *
 * @param mixed $value The value a request sent.
 * @return string The day, or an empty string for anything else.
 */
function sanitize_day( mixed $value ): string {
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : '';
}

/**
 * Stores a company value, or removes it when it's empty: the daily checks ask whether a date exists, not what it
 * holds.
 *
 * @param int    $company_id The company.
 * @param string $key        The value's meta key.
 * @param string $value      The value, cleaned.
 */
function set_company_value( int $company_id, string $key, string $value ): void {
	if ( '' === $value ) {
		delete_post_meta( $company_id, $key );
	} else {
		update_post_meta( $company_id, $key, wp_slash( $value ) );
	}
}

/**
 * Clears a Compliant company's Good Faith date, even when the request that made it Compliant sent one along.
 *
 * @param \WP_Post $company The company.
 */
function clear_good_faith_due( \WP_Post $company ): void {
	if ( 'sv-compliant' === $company->post_status ) {
		delete_post_meta( $company->ID, '_sv_good_faith_due' );
	}
}
add_action( 'rest_after_insert_' . COMPANY_POST_TYPE, __NAMESPACE__ . '\clear_good_faith_due' );

/**
 * Keeps real-time collaboration off for companies and sponsorships.
 *
 * It's made for documents edited together. Here it would show a vetter's unsaved edits to the others, and leave a
 * saved company looking unsaved: the copy of the document it stores in the company's meta changes with every save.
 *
 * @param bool   $is_disabled Whether collaboration is off for the post type.
 * @param string $post_type   The post type.
 * @return bool Whether it's off.
 */
function disable_collaboration( bool $is_disabled, string $post_type ): bool {
	return $is_disabled || in_array( $post_type, array( COMPANY_POST_TYPE, SPONSORSHIP_POST_TYPE ), true );
}
add_filter( 'wp_is_post_type_collaboration_disabled', __NAMESPACE__ . '\disable_collaboration', 10, 2 );

/**
 * Adds the fields of companies and sponsorships, and the routes for the two emails.
 */
function register_rest_additions(): void {
	if ( ! is_central() ) {
		return;
	}

	require_once __DIR__ . '/records.php';

	foreach ( get_rest_fields() as $post_type => $fields ) {
		foreach ( $fields as $name => $field ) {
			$update = $field['update'] ?? null;

			register_rest_field(
				$post_type,
				$name,
				array(
					// The controller sets up the post it's preparing, whichever fields the request named.
					'get_callback'    => static fn( array $data, string $attribute, \WP_REST_Request $request ): mixed => $field['get']( (int) get_the_ID(), $request ),
					'update_callback' => $update ? static fn( mixed $value, \WP_Post $post ): mixed => as_bad_request( $update( $post->ID, $value ) ) : null,
					'schema'          => $field['schema'] + array(
						'context'  => array( 'view', 'edit' ),
						'readonly' => ! $update && empty( $field['writable'] ),
					),
				)
			);
		}
	}

	$routes = array(
		'/companies/(?P<id>\d+)/compliance-request' => array( COMPANY_POST_TYPE, __NAMESPACE__ . '\send_rest_compliance_request' ),
		'/sponsorships/(?P<id>\d+)/approval-email'  => array( SPONSORSHIP_POST_TYPE, __NAMESPACE__ . '\send_rest_approval_email' ),
	);

	foreach ( $routes as $route => list( $post_type, $callback ) ) {
		register_rest_route(
			'sponsor-vetting/v1',
			$route,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => $callback,
				'permission_callback' => static fn( \WP_REST_Request $request ): bool => get_post_type( (int) $request['id'] ) === $post_type && current_user_can( 'edit_post', (int) $request['id'] ),
			)
		);
	}
}
add_action( 'rest_api_init', __NAMESPACE__ . '\register_rest_additions' );

/**
 * Makes a refusal an answer to the request that asked, not a failure of the server.
 *
 * @param mixed $result What writing a field came to.
 * @return mixed
 */
function as_bad_request( mixed $result ): mixed {
	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 400 ) );
	}

	return $result;
}

/**
 * The fields of companies and sponsorships beyond core's.
 *
 * A field is worked out only when a request asks for it, so a list that names its fields skips the rest. Most are
 * read-only. One with an `update` callback is written through it, and `writable` marks a value a request sends
 * for prepare_rest_update() to act on.
 *
 * @return array[] For each post type, each field's `get` callback, which takes the post's ID and the request, its
 *                 `update` callback, if any, which takes the post's ID and the value, and its `schema`, keyed by
 *                 the field's name.
 */
function get_rest_fields(): array {
	$string  = array( 'type' => 'string' );
	$strings = array(
		'type'  => 'array',
		'items' => $string,
	);
	$emails  = array(
		'type'  => 'array',
		'items' => array(
			'type'       => 'object',
			'properties' => array(
				'type' => $string,
				'url'  => $string,
				'time' => $string,
			),
		),
	);
	$shared  = array(
		'allowed_statuses' => array(
			'get'    => __NAMESPACE__ . '\get_rest_allowed_statuses',
			'schema' => $strings,
		),
		'trashed_status'   => array(
			'get'    => static fn( int $post_id ): string => (string) get_post_meta( $post_id, '_wp_trash_meta_status', true ),
			'schema' => $string,
		),
		'conversations'    => array(
			'get'    => __NAMESPACE__ . '\get_rest_conversations',
			'schema' => $emails,
		),
		'log'              => array(
			'get'    => __NAMESPACE__ . '\get_rest_log',
			'schema' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'time' => $string,
						'html' => $string,
					),
				),
			),
		),
		// Written to add a note; there's nothing to read back but the log.
		'note'             => array(
			'get'    => '__return_empty_string',
			'update' => __NAMESPACE__ . '\add_rest_note',
			'schema' => $string,
		),
	);
	$stored = array();

	foreach ( get_company_values() as $name => list( $key, $sanitize ) ) {
		$stored[ $name ] = array(
			'get'    => static fn( int $company_id ): string => (string) get_post_meta( $company_id, $key, true ),
			'update' => static function ( int $company_id, mixed $value ) use ( $key, $sanitize ): void {
				set_company_value( $company_id, $key, $sanitize( $value ) );
			},
			'schema' => $string,
		);
	}

	return array(
		COMPANY_POST_TYPE     => $shared + $stored + array(
			'domains'        => array(
				'get'    => __NAMESPACE__ . '\get_company_domains',
				'update' => static function ( int $company_id, mixed $domains ): void {
					set_company_domains( $company_id, (array) $domains );
				},
				'schema' => $strings,
			),
			'flags'          => array(
				'get'    => __NAMESPACE__ . '\get_company_flag_items',
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'type'    => $string,
							'text'    => $string,
							'url'     => $string,
							'date'    => $string,
							'overdue' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
			'next_event'     => array(
				'get'    => __NAMESPACE__ . '\get_rest_next_event',
				'schema' => $string,
			),
			'sponsorships'   => array(
				'get'    => __NAMESPACE__ . '\get_rest_sponsorship_count',
				'schema' => array( 'type' => 'integer' ),
			),
			'last_vetted'    => array(
				'get'    => __NAMESPACE__ . '\get_rest_last_vetted',
				'schema' => $string,
			),
			'mes'            => array(
				'get'    => __NAMESPACE__ . '\get_rest_mes',
				'update' => static fn( int $company_id, mixed $mes_id ): true|\WP_Error => link_mes( $company_id, (int) $mes_id ),
				'schema' => array( 'type' => 'integer' ),
			),
			'mes_options'    => array(
				'get'    => __NAMESPACE__ . '\get_rest_mes_options',
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'   => array( 'type' => 'integer' ),
							'name' => $string,
						),
					),
				),
			),
		),
		SPONSORSHIP_POST_TYPE => $shared + array(
			'company'              => array(
				'get'    => static fn( int $sponsorship_id ): int => (int) get_post_field( 'post_parent', $sponsorship_id ),
				'update' => __NAMESPACE__ . '\link_rest_sponsorship',
				'schema' => array( 'type' => 'integer' ),
			),
			'suggestion'           => array(
				'get'    => __NAMESPACE__ . '\get_rest_suggestion',
				'schema' => array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'reason' => $string,
					),
				),
			),
			'event'                => array(
				'get'    => __NAMESPACE__ . '\get_rest_event',
				'schema' => array(
					'type'       => 'object',
					'properties' => array(
						'name' => $string,
						'date' => $string,
						'end'  => $string,
					),
				),
			),
			'sponsor'              => array(
				'get'    => __NAMESPACE__ . '\get_rest_sponsor',
				'schema' => array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'name'        => $string,
						'website'     => $string,
						'website_url' => $string,
						'first_name'  => $string,
						'last_name'   => $string,
						'email'       => $string,
						'edit_url'    => $string,
					),
				),
			),
			'approved'             => array(
				'get'    => __NAMESPACE__ . '\get_rest_approval',
				'schema' => $string,
			),
			'approvable'           => array(
				'get'    => __NAMESPACE__ . '\get_rest_approvable',
				'schema' => $string,
			),
			// Sent with a status of Approved to approve in good faith.
			'good_faith'           => array(
				'get'      => static fn( int $sponsorship_id ): bool => (bool) get_post_meta( $sponsorship_id, '_sv_good_faith', true ),
				'writable' => true,
				'schema'   => array( 'type' => 'boolean' ),
			),
			'domain'               => array(
				'get'    => static fn( int $sponsorship_id ): string => (string) get_current_domain( $sponsorship_id ),
				'schema' => $string,
			),
			// Written with the camp sponsor's current website to confirm a change.
			'approved_domain'      => array(
				'get'    => static fn( int $sponsorship_id ): string => (string) get_post_meta( $sponsorship_id, '_sv_approved_domain', true ),
				'update' => static fn( int $sponsorship_id, mixed $domain ): true|\WP_Error => confirm_website( $sponsorship_id, (string) $domain ),
				'schema' => $string,
			),
			'website_changed'      => array(
				'get'    => static fn( int $sponsorship_id ): bool => 'svs-approved' === get_post_status( $sponsorship_id ) && website_changed( $sponsorship_id ),
				'schema' => array( 'type' => 'boolean' ),
			),
			'needs_approval_email' => array(
				'get'    => __NAMESPACE__ . '\needs_approval_email',
				'schema' => array( 'type' => 'boolean' ),
			),
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
 * Gets the statuses a record's status picker offers: the ones it may change to by hand.
 *
 * Approval has its own buttons, which check the company and say whose approval it is.
 *
 * @param int $post_id The company or sponsorship.
 * @return string[]
 */
function get_rest_allowed_statuses( int $post_id ): array {
	$allowed = get_allowed_transitions( (string) get_post_type( $post_id ) )[ get_post_status( $post_id ) ] ?? array();

	return array_values( array_diff( $allowed, array( 'svs-approved' ) ) );
}

/**
 * Gets when a company's next event starts, which is in the past for one that's under way.
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
 * Gets the Multi-Event Sponsor a company is linked to.
 *
 * @param int $company_id The company.
 * @return int The `mes` post's ID, or 0 for none.
 */
function get_rest_mes( int $company_id ): int {
	return get_company_mes( $company_id )[0] ?? 0;
}

/**
 * Lists the Multi-Event Sponsors a company can be linked to: the ones no other company is linked to.
 *
 * @param int $company_id The company.
 * @return array[] Each one's `id` and `name`, by name.
 */
function get_rest_mes_options( int $company_id ): array {
	if ( ! post_type_exists( 'mes' ) ) {
		return array();
	}

	$options = array();
	$mes     = get_posts(
		array(
			'post_type'      => 'mes',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);

	foreach ( $mes as $post ) {
		if ( in_array( get_mes_company( $post->ID ), array( 0, $company_id ), true ) ) {
			$options[] = array(
				'id'   => $post->ID,
				'name' => $post->post_title,
			);
		}
	}

	return $options;
}

/**
 * Gets the emails sent about a record, newest first.
 *
 * @param int $post_id The company or sponsorship.
 * @return array[] Each conversation's `type`, `url` and `time`.
 */
function get_rest_conversations( int $post_id ): array {
	return array_map(
		static fn( array $conversation ): array => array(
			'type' => (string) $conversation['type'],
			'url'  => esc_url_raw( (string) $conversation['url'], array( 'http', 'https' ) ),
			'time' => gmdate( 'c', (int) $conversation['time'] ),
		),
		array_reverse( get_conversations( $post_id ) )
	);
}

/**
 * Gets a record's notes and status changes, newest first, as vetters read them.
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
 * Adds a private note to a record.
 *
 * @param int   $post_id The company or sponsorship.
 * @param mixed $note    The note.
 */
function add_rest_note( int $post_id, mixed $note ): void {
	$note = sanitize_textarea_field( (string) $note );

	if ( '' !== $note ) {
		add_note( $post_id, $note );
	}
}

/**
 * Gets the event a sponsorship is for.
 *
 * @param int              $sponsorship_id The sponsorship.
 * @param \WP_REST_Request $request        The request.
 * @return string[] The event's `name`, the day it starts as `date` and its last day as `end`, each as `Y-m-d`, or
 *                  as an empty string for an event without a date.
 */
function get_rest_event( int $sponsorship_id, \WP_REST_Request $request ): array {
	$blog_id = (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true );

	return remember(
		$request,
		'event_' . $blog_id,
		static function () use ( $blog_id ): array {
			$dates = get_event_dates( array( $blog_id ) )[ $blog_id ] ?? null;

			return array(
				'name' => decode_name( get_event_name( $blog_id ) ),
				'date' => $dates ? gmdate( 'Y-m-d', $dates['start'] ) : '',
				'end'  => $dates ? gmdate( 'Y-m-d', $dates['end'] ) : '',
			);
		}
	);
}

/**
 * Turns a name as it's stored, with its `&` and the like encoded, into the text the screen shows.
 *
 * @param string $name The stored name.
 * @return string
 */
function decode_name( string $name ): string {
	return html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Gets what the camp entered for a sponsorship's sponsor.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return string[]|null The sponsor's `name`, `website`, `first_name`, `last_name` and `email`, where it's edited
 *                       on the event's site, as `edit_url`, and its website as an address that's safe to link to,
 *                       as `website_url`, or null if the camp deleted it.
 */
function get_rest_sponsor( int $sponsorship_id ): ?array {
	$blog_id    = (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true );
	$sponsor_id = (int) get_post_meta( $sponsorship_id, '_sv_sponsor_id', true );
	$sponsor    = get_camp_sponsor( $blog_id, $sponsor_id );

	if ( ! $sponsor ) {
		return null;
	}

	// Organizers enter the website, so it's only linked when it's a web address.
	$url = esc_url_raw( $sponsor['website'], array( 'http', 'https' ) );

	return array(
		'name'        => decode_name( $sponsor['name'] ),
		'website'     => $sponsor['website'],
		'website_url' => '' === normalize_domain( $url ) ? '' : $url,
		'first_name'  => $sponsor['first_name'],
		'last_name'   => $sponsor['last_name'],
		'email'       => $sponsor['email'],
		'edit_url'    => get_admin_url( $blog_id, 'post.php?post=' . $sponsor_id . '&action=edit' ),
	);
}

/**
 * Gets the company a sponsorship is likely to belong to, for one a vetter still has to link.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return array|null The company's `id` and the `reason` it's suggested, or null for none.
 */
function get_rest_suggestion( int $sponsorship_id ): ?array {
	$company_id = (int) get_post_meta( $sponsorship_id, '_sv_suggested_company', true );

	if ( get_post_field( 'post_parent', $sponsorship_id ) || ! $company_id || COMPANY_POST_TYPE !== get_post_type( $company_id ) ) {
		return null;
	}

	$sponsor = get_camp_sponsor( (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true ), (int) get_post_meta( $sponsorship_id, '_sv_sponsor_id', true ) );

	if ( $sponsor && in_array( normalize_domain( $sponsor['website'] ), get_company_domains( $company_id ), true ) ) {
		$reason = __( 'The website matches this company.', 'wordcamporg' );
	} elseif ( $sponsor && $sponsor['mes_id'] ) {
		$reason = __( 'This is a copy of the company’s Multi-Event Sponsor, but its website isn’t one of the company’s domains.', 'wordcamporg' );
	} else {
		$reason = __( 'The website doesn’t match the company’s domains.', 'wordcamporg' );
	}

	return array(
		'id'     => $company_id,
		'reason' => $reason,
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
 * Gets how a pending sponsorship can be approved now.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return string `approve` when its company is vetted, `good_faith` when its company has been asked for changes, or
 *                an empty string when it can't be.
 */
function get_rest_approvable( int $sponsorship_id ): string {
	$company_id = (int) get_post_field( 'post_parent', $sponsorship_id );

	if ( ! $company_id || 'svs-pending' !== get_post_status( $sponsorship_id ) ) {
		return '';
	}

	$state = get_vetting_state( $company_id );

	if ( $state['approvable'] ) {
		return 'approve';
	}

	return 'sv-compliance-req' === $state['status'] ? 'good_faith' : '';
}

/**
 * Links a sponsorship to a company, or moves it to another, and puts that company in the queue if it needs it.
 *
 * @param int   $sponsorship_id The sponsorship.
 * @param mixed $company_id     The company.
 * @return true|\WP_Error
 */
function link_rest_sponsorship( int $sponsorship_id, mixed $company_id ): true|\WP_Error {
	$company_id = (int) $company_id;

	if ( (int) get_post_field( 'post_parent', $sponsorship_id ) === $company_id ) {
		return true;
	}

	if ( COMPANY_POST_TYPE !== get_post_type( $company_id ) || ! isset( get_company_statuses()[ get_post_status( $company_id ) ] ) ) {
		return new \WP_Error( 'sv_no_company', __( 'That company doesn’t exist.', 'wordcamporg' ) );
	}

	$linked = link_sponsorship( $sponsorship_id, $company_id );

	if ( is_wp_error( $linked ) ) {
		return $linked;
	}

	queue_company( $sponsorship_id );

	return true;
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
 * Lists companies and sponsorships of any status when a request asks for `any`, so the screen doesn't have to know
 * the statuses to list them.
 *
 * Core's `any` leaves out the statuses that are excluded from search, which these all are. It's still only for
 * those who can edit the records: core refuses the parameter to everyone else.
 *
 * @param array $args The arguments of the list's query.
 * @return array
 */
function list_any_status( array $args ): array {
	$statuses = (array) ( $args['post_status'] ?? array() );

	if ( in_array( 'any', $statuses, true ) ) {
		$args['post_status'] = array_merge(
			array_diff( $statuses, array( 'any' ) ),
			array_keys( get_statuses( $args['post_type'] ) )
		);
	}

	return $args;
}
add_filter( 'rest_' . COMPANY_POST_TYPE . '_query', __NAMESPACE__ . '\list_any_status' );
add_filter( 'rest_' . SPONSORSHIP_POST_TYPE . '_query', __NAMESPACE__ . '\list_any_status' );

/**
 * Adds the statuses of companies and sponsorships to what REST says about their post types, in their own order
 * and with their labels: the one place the screen learns them from.
 *
 * @param \WP_REST_Response $response  The response.
 * @param \WP_Post_Type     $post_type The post type.
 * @param \WP_REST_Request  $request   The request.
 * @return \WP_REST_Response
 */
function add_rest_statuses( \WP_REST_Response $response, \WP_Post_Type $post_type, \WP_REST_Request $request ): \WP_REST_Response {
	$statuses = get_statuses( $post_type->name );

	// Read for editing, which core allows only those who can edit the records.
	if ( $statuses && 'edit' === $request['context'] ) {
		$response->data['statuses'] = array_map(
			static fn( string $slug, string $name ): array => array(
				'slug' => $slug,
				'name' => $name,
			),
			array_keys( $statuses ),
			$statuses
		);
	}

	return $response;
}
add_filter( 'rest_prepare_post_type', __NAMESPACE__ . '\add_rest_statuses', 10, 3 );

/**
 * Makes a status change over REST keep the workflow's rules, and say so when it can't.
 *
 * - A change from a status the record no longer has is refused: the screen names the status it was showing as
 *   `original_status`, and one loaded before someone else's change would otherwise undo it.
 * - A record leaving the trash is restored the way wp-admin restores it, to the status it had.
 * - Approving a sponsorship goes through approve_sponsorship(), which checks the company and the website the
 *   vetter was shown, and is followed by the approval email. A failed email is noted, and can be sent again.
 * - Any other change the workflow doesn't allow is refused. Otherwise enforce_status_transition() quietly keeps
 *   the old status, and the request looks like it worked.
 *
 * @param \stdClass|\WP_Error $prepared The post about to be saved.
 * @param \WP_REST_Request    $request  The request.
 * @return \stdClass|\WP_Error
 */
function prepare_rest_update( \stdClass|\WP_Error $prepared, \WP_REST_Request $request ): \stdClass|\WP_Error {
	$post = is_wp_error( $prepared ) || empty( $prepared->ID ) ? null : get_post( (int) $prepared->ID );

	if ( ! $post || ! isset( $prepared->post_status ) ) {
		return $prepared;
	}

	$to   = (string) $prepared->post_status;
	$seen = $request['original_status'];

	if ( is_string( $seen ) && $seen !== $post->post_status ) {
		$statuses = get_statuses( $post->post_type );

		return new \WP_Error(
			'sv_status_changed',
			sprintf(
				/* translators: %s: Status. */
				__( 'Someone changed the status to %s after this was loaded. It’s up to date now: check it, then try again.', 'wordcamporg' ),
				$statuses[ $post->post_status ] ?? $post->post_status
			),
			array( 'status' => 409 )
		);
	}

	if ( 'trash' === $post->post_status && 'trash' !== $to ) {
		wp_untrash_post( $post->ID );
		$post = get_post( $post->ID );
	}

	if ( $to === $post->post_status ) {
		unset( $prepared->post_status );

		return $prepared;
	}

	if ( 'svs-approved' === $to ) {
		$approved = approve_sponsorship( $post->ID, ! empty( $request['good_faith'] ), isset( $request['shown_domain'] ) ? (string) $request['shown_domain'] : null );

		if ( is_wp_error( $approved ) ) {
			return as_bad_request( $approved );
		}

		$sent = send_approval_email( $post->ID );

		if ( is_wp_error( $sent ) ) {
			add_note( $post->ID, get_send_error_message( $sent ) );
		}

		unset( $prepared->post_status );

		return $prepared;
	}

	if ( ! is_allowed_transition( $post->post_type, $post->post_status, $to ) ) {
		$statuses = get_statuses( $post->post_type );

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

	return $prepared;
}
add_filter( 'rest_pre_insert_' . COMPANY_POST_TYPE, __NAMESPACE__ . '\prepare_rest_update', 10, 2 );
add_filter( 'rest_pre_insert_' . SPONSORSHIP_POST_TYPE, __NAMESPACE__ . '\prepare_rest_update', 10, 2 );

/**
 * Sends a company's compliance request for one of its pending sponsorships, which names the event and copies it in.
 *
 * @param \WP_REST_Request $request The request, naming the company and the `sponsorship`.
 * @return array|\WP_Error
 */
function send_rest_compliance_request( \WP_REST_Request $request ): array|\WP_Error {
	$sent = send_compliance_request( (int) $request['id'], (int) $request['sponsorship'] );

	if ( is_wp_error( $sent ) ) {
		return new \WP_Error( $sent->get_error_code(), get_send_error_message( $sent ), array( 'status' => 400 ) );
	}

	return array( 'message' => __( 'The compliance request was sent.', 'wordcamporg' ) );
}

/**
 * Sends a sponsorship's approval email, if it still needs one.
 *
 * @param \WP_REST_Request $request The request, naming the sponsorship.
 * @return array|\WP_Error
 */
function send_rest_approval_email( \WP_REST_Request $request ): array|\WP_Error {
	$sponsorship_id = (int) $request['id'];
	$sent           = send_approval_email( $sponsorship_id );

	if ( is_wp_error( $sent ) ) {
		return new \WP_Error( $sent->get_error_code(), get_send_error_message( $sent ), array( 'status' => 400 ) );
	}

	if ( $sent ) {
		$message = __( 'The approval email was sent to the event.', 'wordcamporg' );
	} elseif ( 'svs-approved' !== get_post_status( $sponsorship_id ) ) {
		$message = __( 'No approval email was sent: the sponsorship isn’t approved.', 'wordcamporg' );
	} elseif ( get_conversations( $sponsorship_id, 'approval' ) ) {
		$message = __( 'No approval email was sent: the event already has one.', 'wordcamporg' );
	} elseif ( get_post_meta( $sponsorship_id, '_sv_approval_notified', true ) ) {
		$message = __( 'No approval email was sent: the event has been told already.', 'wordcamporg' );
	} elseif ( event_has_ended( (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true ) ) ) {
		$message = __( 'No approval email was sent: the event is over.', 'wordcamporg' );
	} else {
		$message = __( 'No approval email was needed: the event has invoiced the sponsor, and approving the invoice tells them.', 'wordcamporg' );
	}

	return array( 'message' => $message );
}
