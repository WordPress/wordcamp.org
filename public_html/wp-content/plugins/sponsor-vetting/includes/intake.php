<?php
/**
 * Intake: turning a camp's sponsor into a sponsorship on Central, and queuing its company for vetting.
 *
 * Organizers, invoice submissions and CSV imports all go through request_vetting().
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

add_action( 'wp_after_insert_post', __NAMESPACE__ . '\request_vetting_on_invoice', 10, 4 );

/**
 * Requests vetting for a camp's sponsor.
 *
 * Finds or creates the sponsorship, links it to a company where that's certain, queues the company if its vetting
 * has lapsed, and points the camp's sponsor at the sponsorship. Safe to call again: each camp sponsor has one
 * sponsorship.
 *
 * Callers check capabilities first.
 *
 * @param int $blog_id    The camp site.
 * @param int $sponsor_id The camp's `wcb_sponsor` post.
 * @return int|\WP_Error The sponsorship's ID.
 */
function request_vetting( int $blog_id, int $sponsor_id ): int|\WP_Error {
	$sponsor = get_camp_sponsor( $blog_id, $sponsor_id );

	register_types();

	if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
		return new \WP_Error( 'sv_no_central', __( 'Central isn’t available.', 'wordcamporg' ) );
	}

	if ( ! $sponsor ) {
		return new \WP_Error( 'sv_no_sponsor', __( 'That sponsor doesn’t exist.', 'wordcamporg' ) );
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	$sponsorship_id = find_sponsorship( $blog_id, $sponsor_id );

	if ( ! $sponsorship_id ) {
		$sponsorship_id = wp_insert_post(
			array(
				'post_type'   => SPONSORSHIP_POST_TYPE,
				'post_status' => 'svs-pending',
				'post_title'  => sprintf( '%s — %s', $sponsor['name'], get_event_name( $blog_id ) ),
				'meta_input'  => array(
					'_sv_key'        => get_sponsorship_key( $blog_id, $sponsor_id ),
					'_sv_blog_id'    => $blog_id,
					'_sv_sponsor_id' => $sponsor_id,
				),
			),
			true
		);
	}

	if ( ! is_wp_error( $sponsorship_id ) ) {
		if ( ! get_post_field( 'post_parent', $sponsorship_id ) ) {
			resolve_company( $sponsorship_id, $sponsor );
		}

		queue_company( $sponsorship_id );
	}

	restore_current_blog();

	if ( is_wp_error( $sponsorship_id ) ) {
		return $sponsorship_id;
	}

	switch_to_blog( $blog_id );
	update_post_meta( $sponsor_id, '_sv_sponsorship_id', $sponsorship_id );
	restore_current_blog();

	return $sponsorship_id;
}

/**
 * Requests vetting when a sponsor invoice is submitted.
 *
 * Runs after the invoice's meta is saved: `transition_post_status` fires before `_wcbsi_sponsor_id` is.
 *
 * @param int           $post_id     The post ID.
 * @param \WP_Post      $post        The post.
 * @param bool          $update      Whether this is an update.
 * @param \WP_Post|null $post_before The post before the save, if it existed.
 */
function request_vetting_on_invoice( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
	if (
		'wcb_sponsor_invoice' !== $post->post_type
		|| 'wcbsi_submitted' !== $post->post_status
		|| ( $post_before && 'wcbsi_submitted' === $post_before->post_status )
	) {
		return;
	}

	$sponsor_id = (int) get_post_meta( $post_id, '_wcbsi_sponsor_id', true );
	$result     = $sponsor_id ? request_vetting( get_current_blog_id(), $sponsor_id ) : 0;

	if ( is_wp_error( $result ) && function_exists( '\WordCamp\Logger\log' ) ) {
		\WordCamp\Logger\log(
			'sponsor_vetting_request_failed',
			array(
				'invoice_id' => $post_id,
				'error'      => $result->get_error_message(),
			)
		);
	}
}

/**
 * Reads what intake needs from a camp's sponsor.
 *
 * @param int $blog_id    The camp site.
 * @param int $sponsor_id The camp's `wcb_sponsor` post.
 * @return array|null `name`, `website`, `first_name`, `last_name`, `email` and `mes_id`, or null if there's no
 *                    such sponsor.
 */
function get_camp_sponsor( int $blog_id, int $sponsor_id ): ?array {
	if ( ! $blog_id || ! $sponsor_id || ! get_site( $blog_id ) ) {
		return null;
	}

	switch_to_blog( $blog_id );

	$post    = get_post( $sponsor_id );
	$sponsor = null;

	if ( $post && 'wcb_sponsor' === $post->post_type && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
		$company_name = trim( (string) get_post_meta( $sponsor_id, '_wcpt_sponsor_company_name', true ) );

		$sponsor = array(
			'name'       => '' !== $company_name ? $company_name : $post->post_title,
			'website'    => (string) get_post_meta( $sponsor_id, '_wcpt_sponsor_website', true ),
			'first_name' => (string) get_post_meta( $sponsor_id, '_wcpt_sponsor_first_name', true ),
			'last_name'  => (string) get_post_meta( $sponsor_id, '_wcpt_sponsor_last_name', true ),
			'email'      => (string) get_post_meta( $sponsor_id, '_wcpt_sponsor_email_address', true ),
			'mes_id'     => (int) get_post_meta( $sponsor_id, '_mes_id', true ),
		);
	}

	restore_current_blog();

	return $sponsor;
}

/**
 * The key a sponsorship is found by: its camp and the camp's sponsor, in one meta value.
 *
 * @param int $blog_id    The camp site.
 * @param int $sponsor_id The camp's `wcb_sponsor` post.
 * @return string
 */
function get_sponsorship_key( int $blog_id, int $sponsor_id ): string {
	return $blog_id . ':' . $sponsor_id;
}

/**
 * Finds a camp sponsor's sponsorship. Runs on Central.
 *
 * @param int $blog_id    The camp site.
 * @param int $sponsor_id The camp's `wcb_sponsor` post.
 * @return int The sponsorship's ID, or 0.
 */
function find_sponsorship( int $blog_id, int $sponsor_id ): int {
	$ids = get_posts(
		array(
			'post_type'      => SPONSORSHIP_POST_TYPE,
			// A trashed sponsorship was removed on purpose, so a new request starts afresh.
			'post_status'    => array_keys( get_sponsorship_statuses() ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_sv_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- An exact match on one key.
			'meta_value'     => get_sponsorship_key( $blog_id, $sponsor_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- An exact match on one key.
		)
	);

	return (int) ( $ids[0] ?? 0 );
}

/**
 * Gets an event's name for a camp site.
 *
 * @param int $blog_id The camp site.
 * @return string
 */
function get_event_name( int $blog_id ): string {
	return function_exists( 'get_wordcamp_name' ) ? (string) get_wordcamp_name( $blog_id ) : (string) get_blog_option( $blog_id, 'blogname' );
}

/**
 * Gets the company a Multi-Event Sponsor is linked to. Runs on Central.
 *
 * @param int $mes_id The `mes` post.
 * @return int The company's ID, or 0.
 */
function get_mes_company( int $mes_id ): int {
	$company_id = $mes_id ? (int) get_post_meta( $mes_id, '_sv_company_id', true ) : 0;

	return $company_id && COMPANY_POST_TYPE === get_post_type( $company_id ) && 'trash' !== get_post_status( $company_id ) ? $company_id : 0;
}

/**
 * Links a new sponsorship to its company where that's certain, and otherwise leaves it for a reviewer.
 *
 * - A Multi-Event Sponsor's copy resolves to the company its `mes` post is linked to, read now rather than stamped
 *   on the copy, so copies organizers have edited (which the propagator no longer updates) resolve too. It links
 *   only while the copy's website is one of the company's domains; a copy pointed at another site is only
 *   suggested, since organizers can repurpose a copy as a different sponsor.
 * - A domain an existing company uses is only suggested: a reviewer confirms it.
 * - A domain no company uses gets a new company.
 * - Without a domain, a reviewer links it.
 *
 * Runs on Central.
 *
 * @param int   $sponsorship_id The sponsorship.
 * @param array $sponsor        The camp sponsor, as get_camp_sponsor() returns it.
 */
function resolve_company( int $sponsorship_id, array $sponsor ): void {
	$company_id = get_mes_company( $sponsor['mes_id'] );
	$domain     = normalize_domain( $sponsor['website'] );

	if ( $company_id ) {
		if ( '' !== $domain && in_array( $domain, get_company_domains( $company_id ), true ) ) {
			link_sponsorship( $sponsorship_id, $company_id );
		} else {
			update_post_meta( $sponsorship_id, '_sv_suggested_company', $company_id );
		}

		return;
	}

	if ( '' === $domain ) {
		return;
	}

	$matches = find_companies_by_domain( $domain );

	if ( $matches ) {
		update_post_meta( $sponsorship_id, '_sv_suggested_company', $matches[0] );
		return;
	}

	$company_id = create_company( $sponsor );

	if ( ! is_wp_error( $company_id ) ) {
		link_sponsorship( $sponsorship_id, $company_id );
	}
}

/**
 * Creates a company from a camp sponsor. Runs on Central.
 *
 * @param array $sponsor The camp sponsor, as get_camp_sponsor() returns it.
 * @return int|\WP_Error The company's ID.
 */
function create_company( array $sponsor ): int|\WP_Error {
	$company_id = wp_insert_post(
		array(
			'post_type'   => COMPANY_POST_TYPE,
			'post_status' => 'sv-needs-vetting',
			'post_title'  => $sponsor['name'],
			'meta_input'  => array(
				'_sv_contact_first_name' => $sponsor['first_name'],
				'_sv_contact_last_name'  => $sponsor['last_name'],
				'_sv_contact_email'      => strtolower( $sponsor['email'] ),
			),
		),
		true
	);

	if ( ! is_wp_error( $company_id ) ) {
		set_company_domains( $company_id, array( $sponsor['website'] ) );
	}

	return $company_id;
}

/**
 * Links a sponsorship to its company. Runs on Central.
 *
 * @param int $sponsorship_id The sponsorship.
 * @param int $company_id     The company.
 */
function link_sponsorship( int $sponsorship_id, int $company_id ): void {
	wp_update_post(
		array(
			'ID'          => $sponsorship_id,
			'post_parent' => $company_id,
		)
	);

	delete_post_meta( $sponsorship_id, '_sv_suggested_company' );

	/* translators: %s: Company name. */
	add_note( $sponsorship_id, sprintf( __( 'Linked to “%s”.', 'wordcamporg' ), get_the_title( $company_id ) ) );
}

/**
 * Puts a pending sponsorship's company back in the queue when its vetting has lapsed.
 *
 * A company still in the pipeline, or Not Eligible until a date still to come, stays where it is. Runs on Central.
 *
 * @param int $sponsorship_id The sponsorship.
 */
function queue_company( int $sponsorship_id ): void {
	$company_id = (int) get_post_field( 'post_parent', $sponsorship_id );

	if ( ! $company_id || 'svs-pending' !== get_post_status( $sponsorship_id ) || ! get_vetting_state( $company_id )['needs_requeue'] ) {
		return;
	}

	wp_update_post(
		array(
			'ID'          => $company_id,
			'post_status' => 'sv-needs-vetting',
		)
	);

	/* translators: %s: Sponsorship title. */
	add_note( $company_id, sprintf( __( 'Back in the queue: “%s” needs vetting, and the last vetting has lapsed.', 'wordcamporg' ), get_the_title( $sponsorship_id ) ) );
}
