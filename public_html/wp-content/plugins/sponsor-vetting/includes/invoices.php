<?php
/**
 * The sponsor invoices dashboard: the vetting it shows, and whether an invoice may be approved.
 *
 * An approval is for the website that was vetted, so a camp changing its sponsor's website afterwards holds up the
 * invoice until a vetter confirms the new one.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Gets the website a sponsorship's camp sponsor has now, as a normalized domain. Runs on Central.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return string|null The domain, or null if the camp sponsor is gone or trashed.
 */
function get_current_domain( int $sponsorship_id ): ?string {
	$sponsor = get_camp_sponsor( (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true ), (int) get_post_meta( $sponsorship_id, '_sv_sponsor_id', true ) );

	return $sponsor ? normalize_domain( $sponsor['website'] ) : null;
}

/**
 * Records the website a sponsorship is approved for. Runs on Central.
 *
 * @param int         $sponsorship_id The sponsorship.
 * @param string|null $domain         The domain, if already read; otherwise the camp sponsor's current one.
 */
function snapshot_approved_domain( int $sponsorship_id, ?string $domain = null ): void {
	update_post_meta( $sponsorship_id, '_sv_approved_domain', $domain ?? (string) get_current_domain( $sponsorship_id ) );
}

/**
 * Whether a sponsorship's camp sponsor has a different website from the one approved. Runs on Central.
 *
 * Compared when read, so every way a website can change is caught. Approvals from before snapshots were taken
 * have nothing to compare with, and a sponsor the camp deleted has no website left to vet.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return bool
 */
function website_changed( int $sponsorship_id ): bool {
	if ( ! metadata_exists( 'post', $sponsorship_id, '_sv_approved_domain' ) ) {
		return false;
	}

	$current = get_current_domain( $sponsorship_id );

	return null !== $current && (string) get_post_meta( $sponsorship_id, '_sv_approved_domain', true ) !== $current;
}

/**
 * Accepts the website a camp changed its approved sponsor to. Runs on Central.
 *
 * Only the website the vetter was shown is accepted: if the camp changed it again since, the vetter has to look
 * at the new one first.
 *
 * Callers check capabilities first.
 *
 * @param int    $sponsorship_id The sponsorship.
 * @param string $shown          The website the vetter confirmed, as shown to them.
 * @return true|\WP_Error
 */
function confirm_website( int $sponsorship_id, string $shown ): true|\WP_Error {
	if ( 'svs-approved' !== get_post_status( $sponsorship_id ) || ! website_changed( $sponsorship_id ) ) {
		return new \WP_Error( 'sv_website_not_changed', __( 'There’s no website change to confirm.', 'wordcamporg' ) );
	}

	$current = (string) get_current_domain( $sponsorship_id );

	if ( normalize_domain( $shown ) !== $current ) {
		/* translators: %s: Website domain. */
		return new \WP_Error( 'sv_website_changed_again', sprintf( __( 'The camp changed the website again, to %s. Check it, then confirm again.', 'wordcamporg' ), $current ?: __( 'none', 'wordcamporg' ) ) );
	}

	$approved = (string) get_post_meta( $sponsorship_id, '_sv_approved_domain', true );

	snapshot_approved_domain( $sponsorship_id, $current );
	add_note(
		$sponsorship_id,
		/* translators: 1: New website domain, 2: The website domain approved before. */
		sprintf( __( 'Confirmed the new website, %1$s, in place of %2$s.', 'wordcamporg' ), $current ?: __( 'none', 'wordcamporg' ), $approved ?: __( 'none', 'wordcamporg' ) )
	);

	return true;
}

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
		return $sponsorship->post_parent
			/* translators: %s: The company's vetting, like "In review". */
			? sprintf( __( 'Pending (%s)', 'wordcamporg' ), get_company_label( (int) $sponsorship->post_parent ) )
			: __( 'Pending (no company linked yet)', 'wordcamporg' );
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
	} elseif ( null === get_current_domain( $sponsorship->ID ) ) {
		$allowed = new \WP_Error( 'sv_invoice_sponsor_gone', __( 'The invoice’s sponsor was trashed or deleted on the camp’s site, so its website can’t be checked.', 'wordcamporg' ) );
	} elseif ( website_changed( $sponsorship->ID ) ) {
		$allowed = new \WP_Error( 'sv_invoice_website_changed', __( 'The sponsor’s website changed after it was approved. A vetter needs to confirm the new website on Central first.', 'wordcamporg' ) );
	}

	restore_current_blog();

	return $allowed;
}
add_filter( 'wcbdsi_pre_approve_invoice', __NAMESPACE__ . '\check_invoice_approval', 10, 2 );
