<?php
/**
 * The company screens on Central: the list, and vetting one company.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

add_filter( 'manage_' . COMPANY_POST_TYPE . '_posts_columns', __NAMESPACE__ . '\get_company_columns' );
add_action( 'manage_' . COMPANY_POST_TYPE . '_posts_custom_column', __NAMESPACE__ . '\render_company_column', 10, 2 );
add_action( 'add_meta_boxes_' . COMPANY_POST_TYPE, __NAMESPACE__ . '\add_company_meta_boxes' );
add_action( 'save_post_' . COMPANY_POST_TYPE, __NAMESPACE__ . '\save_company' );

/**
 * Sets the company list's columns.
 *
 * @param string[] $columns Default columns.
 * @return string[]
 */
function get_company_columns( array $columns ): array {
	return array(
		'cb'              => $columns['cb'],
		'title'           => __( 'Company', 'wordcamporg' ),
		'sv_domains'      => __( 'Domains', 'wordcamporg' ),
		'sv_status'       => __( 'Status', 'wordcamporg' ),
		'sv_last_vetted'  => __( 'Last vetted', 'wordcamporg' ),
		'sv_sponsorships' => __( 'Sponsorships', 'wordcamporg' ),
	);
}

/**
 * Renders a custom column of the company list.
 *
 * @param string $column     The column.
 * @param int    $company_id The company.
 */
function render_company_column( string $column, int $company_id ): void {
	switch ( $column ) {
		case 'sv_domains':
			foreach ( get_company_domains( $company_id ) as $index => $domain ) {
				echo $index ? ', ' : '';
				render_website( 'https://' . $domain, $domain );
			}
			break;

		case 'sv_status':
			echo esc_html( get_company_statuses()[ get_post_status( $company_id ) ] ?? '' );

			foreach ( get_company_flags( $company_id ) as $flag ) {
				echo '<br><em>' . esc_html( $flag ) . '</em>';
			}
			break;

		case 'sv_last_vetted':
			$last_vetted = (int) get_post_meta( $company_id, '_sv_last_vetted', true );
			echo esc_html( $last_vetted ? wp_date( 'Y-m-d', $last_vetted ) : '—' );
			break;

		case 'sv_sponsorships':
			$count = get_listed_sponsorship_counts()[ $company_id ] ?? 0;
			printf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'edit.php?post_type=' . SPONSORSHIP_POST_TYPE . '&sv_company=' . $company_id ) ),
				esc_html( number_format_i18n( $count ) )
			);
			break;
	}
}

/**
 * Counts the sponsorships of the companies on the list screen, in one query for the page.
 *
 * @return int[] Counts, keyed by company ID.
 */
function get_listed_sponsorship_counts(): array {
	global $wpdb;
	static $counts = null;

	if ( null !== $counts ) {
		return $counts;
	}

	$counts      = array();
	$company_ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts ?? array(), 'ID' ) );
	$statuses    = array_keys( get_sponsorship_statuses() );

	if ( ! $company_ids ) {
		return $counts;
	}

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One grouped count per list screen; nothing to cache across requests.
		$wpdb->prepare(
			sprintf(
				"SELECT post_parent, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = %%s AND post_parent IN (%s) AND post_status IN (%s) GROUP BY post_parent",
				implode( ',', array_fill( 0, count( $company_ids ), '%d' ) ),
				implode( ',', array_fill( 0, count( $statuses ), '%s' ) )
			),
			array_merge( array( SPONSORSHIP_POST_TYPE ), $company_ids, $statuses )
		)
	);

	foreach ( $rows as $row ) {
		$counts[ (int) $row->post_parent ] = (int) $row->total;
	}

	return $counts;
}

/**
 * Describes what a reviewer should know about a company beyond its status.
 *
 * @param int $company_id The company.
 * @return string[]
 */
function get_company_flags( int $company_id ): array {
	$state = get_vetting_state( $company_id );
	$flags = array();

	if ( $state['expired'] ) {
		$flags[] = __( 'Vetting has lapsed', 'wordcamporg' );
	}

	if ( $state['not_eligible_active'] ) {
		$until = (string) get_post_meta( $company_id, '_sv_not_eligible_until', true );

		/* translators: %s: Date. */
		$flags[] = '' === $until ? __( 'Indefinitely', 'wordcamporg' ) : sprintf( __( 'Until %s', 'wordcamporg' ), $until );
	} elseif ( 'sv-not-eligible' === $state['status'] ) {
		$flags[] = __( 'Not Eligible period has ended', 'wordcamporg' );
	}

	if ( $state['good_faith_overdue'] ) {
		/* translators: %s: Date. */
		$flags[] = sprintf( __( 'Good Faith overdue since %s', 'wordcamporg' ), get_post_meta( $company_id, '_sv_good_faith_due', true ) );
	} elseif ( get_post_meta( $company_id, '_sv_good_faith_due', true ) ) {
		/* translators: %s: Date. */
		$flags[] = sprintf( __( 'Good Faith due %s', 'wordcamporg' ), get_post_meta( $company_id, '_sv_good_faith_due', true ) );
	}

	if ( 'sv-compliance-req' === $state['status'] && get_post_meta( $company_id, '_sv_sponsor_replied', true ) ) {
		$flags[] = __( 'Sponsor replied', 'wordcamporg' );
	}

	return $flags;
}

/**
 * Gets a company's sponsorships, newest first.
 *
 * @param int $company_id The company.
 * @return \WP_Post[]
 */
function get_company_sponsorships( int $company_id ): array {
	return get_posts(
		array(
			'post_type'      => SPONSORSHIP_POST_TYPE,
			'post_status'    => array_keys( get_sponsorship_statuses() ),
			'post_parent'    => $company_id,
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		)
	);
}

/**
 * Replaces core's Publish box with the vetting boxes.
 */
function add_company_meta_boxes(): void {
	remove_meta_box( 'submitdiv', COMPANY_POST_TYPE, 'side' );

	add_meta_box( 'sv-status', __( 'Vetting', 'wordcamporg' ), __NAMESPACE__ . '\render_company_status_box', COMPANY_POST_TYPE, 'side', 'high' );
	add_meta_box( 'sv-details', __( 'Company', 'wordcamporg' ), __NAMESPACE__ . '\render_company_details_box', COMPANY_POST_TYPE, 'normal', 'high' );
	add_meta_box( 'sv-findings', __( 'Findings', 'wordcamporg' ), __NAMESPACE__ . '\render_findings_box', COMPANY_POST_TYPE, 'normal', 'high' );
	add_meta_box( 'sv-compliance', __( 'Compliance Request', 'wordcamporg' ), __NAMESPACE__ . '\render_compliance_box', COMPANY_POST_TYPE, 'normal' );
	add_meta_box( 'sv-sponsorships', __( 'Sponsorships', 'wordcamporg' ), __NAMESPACE__ . '\render_company_sponsorships_box', COMPANY_POST_TYPE, 'normal' );
	add_meta_box( 'sv-log', __( 'Notes and History', 'wordcamporg' ), __NAMESPACE__ . '\render_log_box', COMPANY_POST_TYPE, 'normal', 'low' );
}

/**
 * Renders the status, its dates and flags, and the Save button.
 *
 * @param \WP_Post $post The company.
 */
function render_company_status_box( \WP_Post $post ): void {
	$last_vetted = (int) get_post_meta( $post->ID, '_sv_last_vetted', true );

	render_status_select( $post );

	foreach ( get_company_flags( $post->ID ) as $flag ) {
		echo '<p><em>' . esc_html( $flag ) . '</em></p>';
	}
	?>
	<p>
		<strong><?php esc_html_e( 'Last vetted', 'wordcamporg' ); ?></strong><br>
		<?php echo esc_html( $last_vetted ? wp_date( 'Y-m-d', $last_vetted ) : '—' ); ?>
	</p>
	<p>
		<label for="sv-not-eligible-until"><strong><?php esc_html_e( 'Not Eligible until', 'wordcamporg' ); ?></strong></label><br>
		<input type="date" id="sv-not-eligible-until" name="sv_not_eligible_until" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, '_sv_not_eligible_until', true ) ); ?>" class="widefat">
		<span class="description"><?php esc_html_e( 'Leave empty for no end date.', 'wordcamporg' ); ?></span>
	</p>
	<p>
		<label for="sv-good-faith-due"><strong><?php esc_html_e( 'Good Faith due', 'wordcamporg' ); ?></strong></label><br>
		<input type="date" id="sv-good-faith-due" name="sv_good_faith_due" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, '_sv_good_faith_due', true ) ); ?>" class="widefat">
		<span class="description"><?php esc_html_e( 'Set when a sponsorship is approved in good faith.', 'wordcamporg' ); ?></span>
	</p>
	<?php
	render_save_button();
}

/**
 * Renders the company's domains, contact, and Multi-Event Sponsor link.
 *
 * @param \WP_Post $post The company.
 */
function render_company_details_box( \WP_Post $post ): void {
	$fields = array(
		'_sv_contact_first_name' => __( 'Contact first name', 'wordcamporg' ),
		'_sv_contact_last_name'  => __( 'Contact last name', 'wordcamporg' ),
		'_sv_contact_email'      => __( 'Contact email', 'wordcamporg' ),
	);
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="sv-domains"><?php esc_html_e( 'Website domains', 'wordcamporg' ); ?></label></th>
			<td>
				<textarea id="sv-domains" name="sv_domains" rows="3" class="large-text"><?php echo esc_textarea( implode( "\n", get_company_domains( $post->ID ) ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One per line. Addresses are reduced to their domain, without www.', 'wordcamporg' ); ?></p>
			</td>
		</tr>
		<?php foreach ( $fields as $meta_key => $label ) : ?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( 'sv' . str_replace( '_', '-', $meta_key ) ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td><input type="<?php echo '_sv_contact_email' === $meta_key ? 'email' : 'text'; ?>" id="<?php echo esc_attr( 'sv' . str_replace( '_', '-', $meta_key ) ); ?>" name="sv_company[<?php echo esc_attr( $meta_key ); ?>]" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, $meta_key, true ) ); ?>" class="regular-text"></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( post_type_exists( 'mes' ) ) : ?>
			<tr>
				<th scope="row"><label for="sv-mes"><?php esc_html_e( 'Multi-Event Sponsor', 'wordcamporg' ); ?></label></th>
				<td>
					<?php render_mes_select( $post->ID ); ?>
					<p class="description"><?php esc_html_e( 'Camp copies of this Multi-Event Sponsor are linked to this company.', 'wordcamporg' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
	</table>
	<?php
}

/**
 * Renders the Multi-Event Sponsor picker, leaving out ones linked to other companies.
 *
 * @param int $company_id The company.
 */
function render_mes_select( int $company_id ): void {
	$mes_posts = get_posts(
		array(
			'post_type'      => 'mes',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	?>
	<select id="sv-mes" name="sv_mes_id">
		<option value="0"><?php esc_html_e( 'None', 'wordcamporg' ); ?></option>
		<?php foreach ( $mes_posts as $mes_post ) : ?>
			<?php $linked = get_mes_company( $mes_post->ID ); ?>
			<?php if ( ! $linked || $linked === $company_id ) : ?>
				<option value="<?php echo esc_attr( (string) $mes_post->ID ); ?>" <?php selected( $linked, $company_id ); ?>><?php echo esc_html( $mes_post->post_title ); ?></option>
			<?php endif; ?>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Renders the GPL and trademark findings.
 *
 * @param \WP_Post $post The company.
 */
function render_findings_box( \WP_Post $post ): void {
	$fields = array(
		'_sv_gpl_findings'       => __( 'GPL', 'wordcamporg' ),
		'_sv_trademark_findings' => __( 'Trademark', 'wordcamporg' ),
	);

	foreach ( $fields as $meta_key => $label ) :
		$id = 'sv' . str_replace( '_', '-', $meta_key );
		?>
		<p>
			<label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
			<textarea id="<?php echo esc_attr( $id ); ?>" name="sv_company[<?php echo esc_attr( $meta_key ); ?>]" rows="6" class="widefat code"><?php echo esc_textarea( (string) get_post_meta( $post->ID, $meta_key, true ) ); ?></textarea>
		</p>
		<?php
	endforeach;
	?>
	<p class="description"><?php esc_html_e( 'Markdown. The compliance request sends these to the sponsor.', 'wordcamporg' ); ?></p>
	<?php
}

/**
 * Renders the compliance requests sent so far, and the form to send one.
 *
 * @param \WP_Post $post The company.
 */
function render_compliance_box( \WP_Post $post ): void {
	$pending = array_filter( get_company_sponsorships( $post->ID ), static fn( \WP_Post $sponsorship ): bool => 'svs-pending' === $sponsorship->post_status );

	render_conversations( get_conversations( $post->ID, 'compliance' ) );

	if ( ! freescout_available() ) {
		echo '<p>' . esc_html__( 'Emails can’t be sent: the FreeScout client isn’t available.', 'wordcamporg' ) . '</p>';
		return;
	}

	if ( ! $pending ) {
		echo '<p>' . esc_html__( 'A compliance request needs a pending sponsorship, which names the event and copies it in.', 'wordcamporg' ) . '</p>';
		return;
	}
	?>
	<p>
		<label for="sv-compliance-sponsorship"><strong><?php esc_html_e( 'Event', 'wordcamporg' ); ?></strong></label><br>
		<select id="sv-compliance-sponsorship" name="sv_compliance_sponsorship">
			<?php foreach ( $pending as $sponsorship ) : ?>
				<option value="<?php echo esc_attr( (string) $sponsorship->ID ); ?>"><?php echo esc_html( $sponsorship->post_title ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<?php submit_button( __( 'Save and send compliance request', 'wordcamporg' ), 'secondary', 'sv_send_compliance', false ); ?>
	</p>
	<p class="description"><?php esc_html_e( 'Emails the findings to the contact from the Community mailbox, copying the event, and moves the company to Compliance Requested.', 'wordcamporg' ); ?></p>
	<?php
}

/**
 * Renders the company's sponsorships.
 *
 * @param \WP_Post $post The company.
 */
function render_company_sponsorships_box( \WP_Post $post ): void {
	$sponsorships = get_company_sponsorships( $post->ID );

	if ( ! $sponsorships ) {
		echo '<p>' . esc_html__( 'No sponsorships yet.', 'wordcamporg' ) . '</p>';
		return;
	}
	?>
	<ul>
		<?php foreach ( $sponsorships as $sponsorship ) : ?>
			<li>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $sponsorship->ID ) ); ?>"><?php echo esc_html( $sponsorship->post_title ); ?></a>:
				<?php echo esc_html( get_sponsorship_statuses()[ $sponsorship->post_status ] ?? '' ); ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

/**
 * Saves the company's fields and a new note, and sends a compliance request when asked to.
 *
 * The status is saved by core, from the `post_status` field, and checked by enforce_status_transition().
 *
 * @param int $post_id The company.
 */
function save_company( int $post_id ): void {
	if ( ! is_valid_save( $post_id ) ) {
		return;
	}

	is_saving( true );

	$fields = wp_unslash( (array) ( $_POST['sv_company'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save(), sanitized field by field below.

	$values = array(
		'_sv_contact_first_name' => sanitize_text_field( (string) ( $fields['_sv_contact_first_name'] ?? '' ) ),
		'_sv_contact_last_name'  => sanitize_text_field( (string) ( $fields['_sv_contact_last_name'] ?? '' ) ),
		'_sv_contact_email'      => strtolower( sanitize_email( (string) ( $fields['_sv_contact_email'] ?? '' ) ) ),
		'_sv_gpl_findings'       => sanitize_textarea_field( (string) ( $fields['_sv_gpl_findings'] ?? '' ) ),
		'_sv_trademark_findings' => sanitize_textarea_field( (string) ( $fields['_sv_trademark_findings'] ?? '' ) ),
		'_sv_not_eligible_until' => get_posted_date( 'sv_not_eligible_until' ),
		'_sv_good_faith_due'     => get_posted_date( 'sv_good_faith_due' ),
	);

	foreach ( $values as $meta_key => $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	set_company_domains( $post_id, preg_split( '/\R/', sanitize_textarea_field( wp_unslash( (string) ( $_POST['sv_domains'] ?? '' ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().

	if ( isset( $_POST['sv_mes_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().
		$linked = link_mes( $post_id, absint( $_POST['sv_mes_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().

		if ( is_wp_error( $linked ) ) {
			add_notice( 'error', $linked->get_error_message() );
		}
	}

	save_note( $post_id );

	if ( isset( $_POST['sv_send_compliance'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().
		$sent = send_compliance_request( $post_id, absint( $_POST['sv_compliance_sponsorship'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().

		if ( is_wp_error( $sent ) ) {
			add_notice( 'error', get_send_error_message( $sent ) );
		} else {
			add_notice( 'success', __( 'The compliance request was sent.', 'wordcamporg' ) );
		}
	}

	is_saving( false );
}

/**
 * Words a sending error for the reviewer, warning them when FreeScout may have sent it anyway.
 *
 * @param \WP_Error $error The error.
 * @return string
 */
function get_send_error_message( \WP_Error $error ): string {
	if ( 'freescout_no_response' === $error->get_error_code() ) {
		return __( 'FreeScout didn’t answer, so the email may have been sent. Check FreeScout before sending it again.', 'wordcamporg' );
	}

	/* translators: %s: Error message. */
	return sprintf( __( 'The email wasn’t sent: %s', 'wordcamporg' ), $error->get_error_message() );
}

/**
 * Links a company to its Multi-Event Sponsor, replacing any earlier link. Each Multi-Event Sponsor has one company.
 *
 * @param int $company_id The company.
 * @param int $mes_id     The `mes` post, or 0 to unlink.
 * @return true|\WP_Error
 */
function link_mes( int $company_id, int $mes_id ): true|\WP_Error {
	if ( $mes_id && 'mes' !== get_post_type( $mes_id ) ) {
		return new \WP_Error( 'sv_no_mes', __( 'That Multi-Event Sponsor doesn’t exist.', 'wordcamporg' ) );
	}

	$linked = get_mes_company( $mes_id );

	if ( $linked && $linked !== $company_id ) {
		return new \WP_Error( 'sv_mes_taken', __( 'That Multi-Event Sponsor is linked to another company.', 'wordcamporg' ) );
	}

	$current = get_posts(
		array(
			'post_type'      => 'mes',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_sv_company_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One company's link.
			'meta_value'     => $company_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One company's link.
		)
	);

	foreach ( array_diff( array_map( 'intval', $current ), array( $mes_id ) ) as $old_mes_id ) {
		delete_post_meta( $old_mes_id, '_sv_company_id' );
	}

	if ( $mes_id ) {
		update_post_meta( $mes_id, '_sv_company_id', $company_id );
	}

	return true;
}
