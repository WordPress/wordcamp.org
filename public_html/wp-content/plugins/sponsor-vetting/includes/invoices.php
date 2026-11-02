<?php
/**
 * The sponsor invoices dashboard: the vetting it shows, and whether an invoice may be approved.
 *
 * An approval is for the website that was vetted, so a camp changing its sponsor's website afterwards flags the
 * approval until a vetter confirms the new one.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Gets a camp sponsor's website, as a normalized domain.
 *
 * @param int $blog_id    The camp site.
 * @param int $sponsor_id The camp's `wcb_sponsor` post.
 * @return string
 */
function get_sponsor_domain( int $blog_id, int $sponsor_id ): string {
	switch_to_blog( $blog_id );
	$website = (string) get_post_meta( $sponsor_id, '_wcpt_sponsor_website', true );
	restore_current_blog();

	return normalize_domain( $website );
}

/**
 * Records the website a sponsorship is approved for, and clears any change flagged before. Runs on Central.
 *
 * @param int $sponsorship_id The sponsorship.
 */
function snapshot_approved_domain( int $sponsorship_id ): void {
	$domain = get_sponsor_domain( (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true ), (int) get_post_meta( $sponsorship_id, '_sv_sponsor_id', true ) );

	update_post_meta( $sponsorship_id, '_sv_approved_domain', $domain );
	delete_post_meta( $sponsorship_id, '_sv_website_changed' );
}

/**
 * Whether a sponsorship's camp sponsor has a different website from the one approved. Runs on Central.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return bool
 */
function website_changed( int $sponsorship_id ): bool {
	return metadata_exists( 'post', $sponsorship_id, '_sv_website_changed' );
}

/**
 * Flags an approved sponsorship when its camp sponsor's website changes, and clears it when it changes back.
 *
 * Hooked to the meta writes rather than the sponsor's save, so the editor, Multi-Event propagation and any other
 * writer are all caught. Approvals from before snapshots were taken have nothing to compare with, so they're left
 * alone.
 *
 * @param int|int[] $meta_id    The meta ID, or IDs when deleted.
 * @param int       $sponsor_id The post.
 * @param string    $meta_key   The meta key.
 */
function flag_changed_website( int|array $meta_id, int $sponsor_id, string $meta_key ): void {
	if ( '_wcpt_sponsor_website' !== $meta_key || 'wcb_sponsor' !== get_post_type( $sponsor_id ) ) {
		return;
	}

	$blog_id        = get_current_blog_id();
	$sponsorship_id = (int) get_post_meta( $sponsor_id, '_sv_sponsorship_id', true );
	$domain         = normalize_domain( (string) get_post_meta( $sponsor_id, '_wcpt_sponsor_website', true ) );

	if ( ! $sponsorship_id || ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
		return;
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	if (
		get_sponsorship_key( $blog_id, $sponsor_id ) === get_post_meta( $sponsorship_id, '_sv_key', true )
		&& 'svs-approved' === get_post_status( $sponsorship_id )
		&& metadata_exists( 'post', $sponsorship_id, '_sv_approved_domain' )
	) {
		$approved = (string) get_post_meta( $sponsorship_id, '_sv_approved_domain', true );

		if ( $domain === $approved ) {
			if ( website_changed( $sponsorship_id ) ) {
				delete_post_meta( $sponsorship_id, '_sv_website_changed' );
				/* translators: %s: Website domain. */
				add_note( $sponsorship_id, sprintf( __( 'The camp changed the sponsor’s website back to %s, the one approved.', 'wordcamporg' ), $approved ) );
			}
		} elseif ( get_post_meta( $sponsorship_id, '_sv_website_changed', true ) !== $domain || ! website_changed( $sponsorship_id ) ) {
			update_post_meta( $sponsorship_id, '_sv_website_changed', $domain );
			add_note(
				$sponsorship_id,
				/* translators: 1: Approved website domain, 2: New website domain. */
				sprintf( __( 'The camp changed the sponsor’s website from %1$s to %2$s after approval.', 'wordcamporg' ), $approved ?: __( 'none', 'wordcamporg' ), $domain ?: __( 'none', 'wordcamporg' ) )
			);
		}
	}

	restore_current_blog();
}
add_action( 'added_post_meta', __NAMESPACE__ . '\flag_changed_website', 10, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\flag_changed_website', 10, 3 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\flag_changed_website', 10, 3 );

/**
 * Words a sponsorship's vetting for the people approving invoices. Runs on Central.
 *
 * @param \WP_Post $sponsorship The sponsorship.
 * @return string
 */
function get_invoice_vetting_label( \WP_Post $sponsorship ): string {
	if ( 'svs-approved' === $sponsorship->post_status ) {
		if ( website_changed( $sponsorship->ID ) ) {
			return __( 'Website changed since approval', 'wordcamporg' );
		}

		return get_post_meta( $sponsorship->ID, '_sv_good_faith', true ) ? __( 'Approved in good faith', 'wordcamporg' ) : __( 'Approved', 'wordcamporg' );
	}

	if ( 'svs-pending' === $sponsorship->post_status ) {
		$company_status = $sponsorship->post_parent ? ( get_company_statuses()[ get_post_status( $sponsorship->post_parent ) ] ?? '' ) : '';

		return '' === $company_status
			? __( 'Pending, no company linked yet', 'wordcamporg' )
			/* translators: %s: Company status. */
			: sprintf( __( 'Pending, company %s', 'wordcamporg' ), $company_status );
	}

	return get_sponsorship_statuses()[ $sponsorship->post_status ] ?? $sponsorship->post_status;
}

/**
 * Gets the vetting of the invoices on a page of the dashboard, from one query on Central.
 *
 * @param string[] $statuses HTML keyed by "{blog_id}:{invoice_id}".
 * @param object[] $rows     The dashboard's index rows, with `blog_id`, `invoice_id` and `sponsor_id`.
 * @return string[]
 */
function get_invoice_statuses( array $statuses, array $rows ): array {
	$keys = array();
	foreach ( $rows as $row ) {
		if ( (int) $row->sponsor_id ) {
			$keys[ $row->blog_id . ':' . $row->invoice_id ] = get_sponsorship_key( (int) $row->blog_id, (int) $row->sponsor_id );
		}
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
	register_types();

	$sponsorships = array();
	if ( $keys ) {
		$posts = get_posts(
			array(
				'post_type'      => SPONSORSHIP_POST_TYPE,
				'post_status'    => array_keys( get_sponsorship_statuses() ),
				'posts_per_page' => count( $keys ),
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One indexed key, for one page of invoices.
					array(
						'key'     => '_sv_key',
						'value'   => array_values( array_unique( $keys ) ),
						'compare' => 'IN',
					),
				),
			)
		);

		_prime_post_caches( array_filter( wp_list_pluck( $posts, 'post_parent' ) ), false, false );

		foreach ( $posts as $sponsorship ) {
			$sponsorships[ (string) get_post_meta( $sponsorship->ID, '_sv_key', true ) ] = $sponsorship;
		}
	}

	foreach ( $rows as $row ) {
		$row_key     = $row->blog_id . ':' . $row->invoice_id;
		$sponsorship = $sponsorships[ $keys[ $row_key ] ?? '' ] ?? null;

		$statuses[ $row_key ] = $sponsorship
			? sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'post.php?post=' . $sponsorship->ID . '&action=edit' ) ), esc_html( get_invoice_vetting_label( $sponsorship ) ) )
			: esc_html__( 'Not requested', 'wordcamporg' );
	}

	restore_current_blog();

	return $statuses;
}
add_filter( 'wcbdsi_vetting_statuses', __NAMESPACE__ . '\get_invoice_statuses', 10, 2 );

/**
 * Refuses to approve an invoice until its sponsor's sponsorship is approved, for the website it has now.
 *
 * Runs on the invoice's site.
 *
 * @param true|\WP_Error $allowed    True, or why the invoice can't be approved yet.
 * @param int            $invoice_id The invoice.
 * @return true|\WP_Error
 */
function check_invoice_approval( true|\WP_Error $allowed, int $invoice_id ): true|\WP_Error {
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	$blog_id    = get_current_blog_id();
	$sponsor_id = (int) get_post_meta( $invoice_id, '_wcbsi_sponsor_id', true );

	if ( ! $sponsor_id ) {
		return new \WP_Error( 'sv_invoice_no_sponsor', __( 'The invoice has no sponsor, so its vetting can’t be checked.', 'wordcamporg' ) );
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
	register_types();

	$sponsorship = get_post( find_sponsorship( $blog_id, $sponsor_id ) );

	if ( ! $sponsorship ) {
		$allowed = new \WP_Error( 'sv_invoice_not_requested', __( 'The sponsor hasn’t been sent for vetting. Request vetting from the sponsor on the camp’s site.', 'wordcamporg' ) );
	} elseif ( 'svs-approved' !== $sponsorship->post_status ) {
		/* translators: %s: The sponsorship's vetting, like "Pending, company In Progress". */
		$allowed = new \WP_Error( 'sv_invoice_not_approved', sprintf( __( 'The sponsor isn’t approved yet: %s.', 'wordcamporg' ), get_invoice_vetting_label( $sponsorship ) ) );
	} elseif ( website_changed( $sponsorship->ID ) ) {
		$allowed = new \WP_Error( 'sv_invoice_website_changed', __( 'The sponsor’s website changed after it was approved. A vetter needs to confirm the new website on Central first.', 'wordcamporg' ) );
	}

	restore_current_blog();

	return $allowed;
}
add_filter( 'wcbdsi_pre_approve_invoice', __NAMESPACE__ . '\check_invoice_approval', 10, 2 );
