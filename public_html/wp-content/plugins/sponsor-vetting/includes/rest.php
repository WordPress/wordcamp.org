<?php
/**
 * The REST routes the vetting screen on Central reads: the companies, and one company with its events.
 *
 * Only vetters can read them, and only on Central.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Registers the routes, on Central.
 */
function register_app_routes(): void {
	if ( ! is_central() ) {
		return;
	}

	// The routes describe companies the way the screens do, with the screens' own helpers.
	require_once __DIR__ . '/admin.php';
	require_once __DIR__ . '/admin-companies.php';
	require_once __DIR__ . '/admin-sponsorships.php';

	$can_vet = static fn(): bool => current_user_can( CAPABILITY );

	register_rest_route(
		'sponsor-vetting/v1',
		'/companies',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\get_company_summaries',
			'permission_callback' => $can_vet,
		)
	);

	register_rest_route(
		'sponsor-vetting/v1',
		'/companies/(?P<id>\d+)',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\get_company_details',
			'permission_callback' => $can_vet,
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\register_app_routes' );

/**
 * Lists every company with what the list shows of it.
 *
 * All of them at once, so the screen can search, filter and sort without asking again.
 *
 * @return array[] Companies, as get_company_summary() describes them.
 */
function get_company_summaries(): array {
	$companies = get_posts(
		array(
			'post_type'      => COMPANY_POST_TYPE,
			'post_status'    => array_keys( get_company_statuses() ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);
	$next      = get_next_events();
	$counts    = get_sponsorship_counts( wp_list_pluck( $companies, 'ID' ) );

	return array_map(
		static fn( \WP_Post $company ): array => get_company_summary( $company, $next[ $company->ID ]['start'] ?? 0, $counts[ $company->ID ] ?? 0 ),
		$companies
	);
}

/**
 * Describes a company for the list.
 *
 * @param \WP_Post $company      The company.
 * @param int      $next_event   When its next event starts, as get_next_events() returns it, or 0 for none.
 * @param int      $sponsorships How many sponsorships it has.
 * @return array {
 *     @type int      $id           The company's ID.
 *     @type string   $name         Its name.
 *     @type string[] $domains      Its website domains.
 *     @type string   $status       Its status.
 *     @type array[]  $flags        What else a reviewer should know, as get_company_flag_items() returns it.
 *     @type string   $next_event   When its next event starts, as `Y-m-d`, or an empty string.
 *     @type string   $last_vetted  When it was last vetted, as `Y-m-d`, or an empty string.
 *     @type int      $sponsorships How many sponsorships it has.
 * }
 */
function get_company_summary( \WP_Post $company, int $next_event, int $sponsorships ): array {
	$last_vetted = (int) get_post_meta( $company->ID, '_sv_last_vetted', true );

	return array(
		'id'           => $company->ID,
		// Stored escaped; the screen escapes what it prints.
		'name'         => wp_specialchars_decode( $company->post_title, ENT_QUOTES ),
		'domains'      => get_company_domains( $company->ID ),
		'status'       => $company->post_status,
		'flags'        => get_company_flag_items( $company->ID ),
		'next_event'   => $next_event ? gmdate( 'Y-m-d', $next_event ) : '',
		'last_vetted'  => $last_vetted ? (string) wp_date( 'Y-m-d', $last_vetted ) : '',
		'sponsorships' => $sponsorships,
	);
}

/**
 * Describes one company in full: what the list shows, and everything its own screens do.
 *
 * @param \WP_REST_Request $request The request, naming the company.
 * @return array|\WP_Error {
 *     The company as get_company_summary() describes it, and:
 *
 *     @type string[] $contact            The contact's `first_name`, `last_name` and `email`.
 *     @type string   $gpl_findings       The GPL findings, as Markdown.
 *     @type string   $trademark_findings The trademark findings, as Markdown.
 *     @type string   $not_eligible_until When Not Eligible ends, as `Y-m-d`, or an empty string.
 *     @type string   $good_faith_due     When Good Faith changes are due, as `Y-m-d`, or an empty string.
 *     @type string   $mes                The name of its Multi-Event Sponsor, or an empty string.
 *     @type array[]  $events             Its sponsorships, as get_company_events() returns them.
 *     @type array[]  $conversations      Its compliance requests: each one's `url` and `time`.
 *     @type array[]  $log                Its notes and history, as get_company_log() returns them.
 *     @type string   $edit_url           Where it's edited.
 * }
 */
function get_company_details( \WP_REST_Request $request ): array|\WP_Error {
	$company = get_post( (int) $request['id'] );

	if ( ! $company || COMPANY_POST_TYPE !== $company->post_type || ! isset( get_company_statuses()[ $company->post_status ] ) ) {
		return new \WP_Error( 'sv_no_company', __( 'That company doesn’t exist.', 'wordcamporg' ), array( 'status' => 404 ) );
	}

	$sponsorships = get_company_sponsorships( $company->ID );
	$events       = get_company_events( $sponsorships );
	$next         = get_next_events( array( $company->ID ) )[ $company->ID ]['start'] ?? 0;
	$mes          = get_posts(
		array(
			'post_type'      => 'mes',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_key'       => '_sv_company_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One company's link.
			'meta_value'     => $company->ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One company's link.
		)
	);

	return array_merge(
		get_company_summary( $company, $next, count( $sponsorships ) ),
		array(
			'contact'            => array(
				'first_name' => (string) get_post_meta( $company->ID, '_sv_contact_first_name', true ),
				'last_name'  => (string) get_post_meta( $company->ID, '_sv_contact_last_name', true ),
				'email'      => (string) get_post_meta( $company->ID, '_sv_contact_email', true ),
			),
			'gpl_findings'       => (string) get_post_meta( $company->ID, '_sv_gpl_findings', true ),
			'trademark_findings' => (string) get_post_meta( $company->ID, '_sv_trademark_findings', true ),
			'not_eligible_until' => (string) get_post_meta( $company->ID, '_sv_not_eligible_until', true ),
			'good_faith_due'     => (string) get_post_meta( $company->ID, '_sv_good_faith_due', true ),
			'mes'                => $mes ? wp_specialchars_decode( $mes[0]->post_title, ENT_QUOTES ) : '',
			'events'             => $events,
			'conversations'      => array_map(
				static fn( array $conversation ): array => array(
					'url'  => (string) $conversation['url'],
					'time' => gmdate( 'c', (int) $conversation['time'] ),
				),
				array_reverse( get_conversations( $company->ID, 'compliance' ) )
			),
			'log'                => get_company_log( $company->ID, $events ),
			'edit_url'           => (string) get_edit_post_link( $company->ID, 'raw' ),
		)
	);
}

/**
 * Describes a company's sponsorships as the events they're for, the next one first and past ones last.
 *
 * @param \WP_Post[] $sponsorships The sponsorships.
 * @return array[] Each sponsorship's `id`, its event's `name` and `date` (as `Y-m-d`, or an empty string), its
 *                 `status`, who `approved` it and when (or an empty string), and its `edit_url`.
 */
function get_company_events( array $sponsorships ): array {
	$blog_ids = get_sponsorship_blog_ids( wp_list_pluck( $sponsorships, 'ID' ) );
	$dates    = get_event_dates( $blog_ids );

	$events = array_map(
		static function ( \WP_Post $sponsorship ) use ( $blog_ids, $dates ): array {
			$start = $dates[ $blog_ids[ $sponsorship->ID ] ] ?? 0;

			return array(
				'id'       => $sponsorship->ID,
				'name'     => get_event_name( $blog_ids[ $sponsorship->ID ] ),
				'date'     => $start ? gmdate( 'Y-m-d', $start ) : '',
				'status'   => $sponsorship->post_status,
				'approved' => get_post_meta( $sponsorship->ID, '_sv_approved_at', true ) ? get_approval_summary( $sponsorship->ID ) : '',
				'edit_url' => (string) get_edit_post_link( $sponsorship->ID, 'raw' ),
			);
		},
		$sponsorships
	);

	// Events to come soonest first, then undated ones, then past ones, latest first.
	$today = gmdate( 'Y-m-d' );
	$order = static function ( array $event ) use ( $today ): array {
		if ( '' === $event['date'] ) {
			return array( 1, 0 );
		}

		return $event['date'] >= $today ? array( 0, strtotime( $event['date'] ) ) : array( 2, -strtotime( $event['date'] ) );
	};

	usort( $events, static fn( array $a, array $b ): int => $order( $a ) <=> $order( $b ) );

	return $events;
}

/**
 * Gets the notes and history of a company and its sponsorships as one timeline, newest first.
 *
 * @param int     $company_id The company.
 * @param array[] $events     Its sponsorships, as get_company_events() returns them.
 * @return array[] Each entry's `time`, the `event` it's about (or an empty string for the company), and what it says,
 *                 as `html`.
 */
function get_company_log( int $company_id, array $events ): array {
	$records = array( $company_id => '' ) + array_column( $events, 'name', 'id' );
	$log     = array();

	foreach ( $records as $post_id => $event ) {
		$post_type = $company_id === $post_id ? COMPANY_POST_TYPE : SPONSORSHIP_POST_TYPE;

		foreach ( get_log( $post_id ) as $entry ) {
			$log[] = array(
				'time'  => (int) $entry['time'],
				'event' => $event,
				'html'  => wp_kses_post( get_log_entry_html( $entry, $post_type ) ),
			);
		}
	}

	usort( $log, static fn( array $a, array $b ): int => $b['time'] <=> $a['time'] );

	return array_map(
		static fn( array $entry ): array => array_merge( $entry, array( 'time' => gmdate( 'c', $entry['time'] ) ) ),
		$log
	);
}
