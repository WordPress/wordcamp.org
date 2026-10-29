<?php
/**
 * What organizers see on their camp site: where each sponsor's vetting stands, and a way to request it.
 *
 * Read-only: organizers see a status, never the findings, notes, or contact details on Central.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Gets where each of the current camp's sponsors stands, as organizers may see it.
 *
 * Switches to Central once for all of them. A sponsorship only counts if it belongs to this camp's sponsor, so a
 * pointer copied along with a sponsor to another site reads as not requested.
 *
 * @param int[] $sponsor_ids The camp's `wcb_sponsor` posts.
 * @return array[] Keyed by sponsor ID: `label`, and `requested`, whether the sponsor has a sponsorship.
 */
function get_organizer_statuses( array $sponsor_ids ): array {
	$blog_id     = get_current_blog_id();
	$sponsor_ids = array_map( 'intval', $sponsor_ids );
	$pointers    = array();
	$mes_ids     = array();

	update_meta_cache( 'post', $sponsor_ids );
	register_types();

	foreach ( $sponsor_ids as $sponsor_id ) {
		$pointers[ $sponsor_id ] = (int) get_post_meta( $sponsor_id, '_sv_sponsorship_id', true );
		$mes_ids[ $sponsor_id ]  = (int) get_post_meta( $sponsor_id, '_mes_id', true );
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	$sponsorships = array();
	if ( array_filter( $pointers ) ) {
		foreach (
			get_posts(
				array(
					'post_type'      => SPONSORSHIP_POST_TYPE,
					'post_status'    => array_keys( get_sponsorship_statuses() ),
					'post__in'       => array_values( array_filter( $pointers ) ),
					'posts_per_page' => count( array_filter( $pointers ) ),
					'no_found_rows'  => true,
				)
			) as $sponsorship
		) {
			$sponsorships[ $sponsorship->ID ] = $sponsorship;
		}

		_prime_post_caches( array_filter( wp_list_pluck( $sponsorships, 'post_parent' ) ), false, true );
	}

	$statuses = array();
	foreach ( $sponsor_ids as $sponsor_id ) {
		$sponsorship = $sponsorships[ $pointers[ $sponsor_id ] ] ?? null;
		$company_id  = get_mes_company( $mes_ids[ $sponsor_id ] );
		$label       = __( 'Not requested', 'wordcamporg' );

		if ( $sponsorship && get_sponsorship_key( $blog_id, $sponsor_id ) === get_post_meta( $sponsorship->ID, '_sv_key', true ) ) {
			$label = get_sponsorship_label( $sponsorship );
		} elseif ( $company_id ) {
			/* translators: %s: Vetting status. */
			$label       = sprintf( __( 'Multi-event sponsor: %s', 'wordcamporg' ), get_company_label( $company_id ) );
			$sponsorship = null;
		} else {
			$sponsorship = null;
		}

		$statuses[ $sponsor_id ] = array(
			'label'     => $label,
			'requested' => null !== $sponsorship,
		);
	}

	restore_current_blog();

	return $statuses;
}

/**
 * Words a sponsorship's status for organizers. Runs on Central.
 *
 * @param \WP_Post $sponsorship The sponsorship.
 * @return string
 */
function get_sponsorship_label( \WP_Post $sponsorship ): string {
	return match ( $sponsorship->post_status ) {
		'svs-approved'     => __( 'Approved', 'wordcamporg' ),
		'svs-not-approved' => __( 'Not approved', 'wordcamporg' ),
		'svs-canceled'     => __( 'Canceled', 'wordcamporg' ),
		default            => $sponsorship->post_parent ? get_company_label( (int) $sponsorship->post_parent ) : __( 'In review', 'wordcamporg' ),
	};
}

/**
 * Words a company's vetting for organizers, without its details. Runs on Central.
 *
 * @param int $company_id The company.
 * @return string
 */
function get_company_label( int $company_id ): string {
	$state = get_vetting_state( $company_id );

	if ( $state['approvable'] ) {
		return __( 'Vetted', 'wordcamporg' );
	}

	return 'sv-compliance-req' === $state['status'] ? __( 'Waiting on the sponsor', 'wordcamporg' ) : __( 'In review', 'wordcamporg' );
}

/**
 * Adds the vetting box to a camp's sponsor.
 */
function add_organizer_meta_box(): void {
	if ( ! is_central() ) {
		add_meta_box( 'sv-vetting', __( 'Sponsor Vetting', 'wordcamporg' ), __NAMESPACE__ . '\render_organizer_meta_box', 'wcb_sponsor', 'side' );
	}
}
add_action( 'add_meta_boxes_wcb_sponsor', __NAMESPACE__ . '\add_organizer_meta_box' );

/**
 * Renders where the sponsor's vetting stands, with a way to request it.
 *
 * @param \WP_Post $post The camp's sponsor.
 */
function render_organizer_meta_box( \WP_Post $post ): void {
	if ( 'auto-draft' === $post->post_status ) {
		echo '<p>' . esc_html__( 'Save the sponsor first, then request vetting.', 'wordcamporg' ) . '</p>';
		return;
	}

	$status = get_organizer_statuses( array( $post->ID ) )[ $post->ID ];

	echo '<p><strong>' . esc_html( $status['label'] ) . '</strong></p>';

	if ( $status['requested'] ) {
		return;
	}

	if ( ! normalize_domain( (string) get_post_meta( $post->ID, '_wcpt_sponsor_website', true ) ) ) {
		echo '<p class="description">' . esc_html__( 'Add the sponsor’s website, so the vetting team can find their company.', 'wordcamporg' ) . '</p>';
	}

	$url = wp_nonce_url( admin_url( 'admin-post.php?action=sv_request_vetting&sponsor_id=' . $post->ID ), 'sv_request_vetting_' . $post->ID );
	?>
	<p><a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Request vetting', 'wordcamporg' ); ?></a></p>
	<p class="description"><?php esc_html_e( 'The Community Team checks every sponsor follows the WordPress trademark policy and the GPL. You don’t need an invoice to ask.', 'wordcamporg' ); ?></p>
	<?php
}

/**
 * Adds the vetting column to a camp's sponsor list.
 *
 * @param string[] $columns The list's columns.
 * @return string[]
 */
function add_organizer_column( array $columns ): array {
	if ( ! is_central() ) {
		$columns['sv_vetting'] = __( 'Vetting', 'wordcamporg' );
	}

	return $columns;
}
add_filter( 'manage_wcb_sponsor_posts_columns', __NAMESPACE__ . '\add_organizer_column', 20 );

/**
 * Renders the vetting column, looking up every sponsor on the page at once.
 *
 * @param string $column     The column.
 * @param int    $sponsor_id The camp's sponsor.
 */
function render_organizer_column( string $column, int $sponsor_id ): void {
	static $statuses = null;

	if ( 'sv_vetting' !== $column ) {
		return;
	}

	if ( null === $statuses ) {
		$statuses = get_organizer_statuses( wp_list_pluck( $GLOBALS['wp_query']->posts ?? array(), 'ID' ) );
	}

	echo esc_html( $statuses[ $sponsor_id ]['label'] ?? '' );
}
add_action( 'manage_wcb_sponsor_posts_custom_column', __NAMESPACE__ . '\render_organizer_column', 10, 2 );

/**
 * Requests vetting for a camp's sponsor, from its edit screen.
 */
function handle_vetting_request(): void {
	$sponsor_id = absint( $_GET['sponsor_id'] ?? 0 );

	check_admin_referer( 'sv_request_vetting_' . $sponsor_id );

	if ( is_central() || 'wcb_sponsor' !== get_post_type( $sponsor_id ) || ! current_user_can( 'edit_post', $sponsor_id ) ) {
		wp_die( esc_html__( 'You can’t request vetting for this sponsor.', 'wordcamporg' ), '', array( 'response' => 403 ) );
	}

	$result = request_vetting( get_current_blog_id(), $sponsor_id );

	if ( is_wp_error( $result ) ) {
		add_notice( 'error', $result->get_error_message() );
	} else {
		add_notice( 'success', __( 'Vetting requested. The Community Team will be in touch with the sponsor if anything needs changing.', 'wordcamporg' ) );
	}

	wp_safe_redirect( admin_url( 'post.php?post=' . $sponsor_id . '&action=edit' ) );
	exit;
}
add_action( 'admin_post_sv_request_vetting', __NAMESPACE__ . '\handle_vetting_request' );
