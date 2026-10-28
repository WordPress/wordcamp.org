<?php
/**
 * The sponsorship screens on Central: the list, linking a sponsorship to its company, and approving it.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Sets the sponsorship list's columns.
 *
 * @param string[] $columns Default columns.
 * @return string[]
 */
function get_sponsorship_columns( array $columns ): array {
	return array(
		'cb'          => $columns['cb'],
		'title'       => __( 'Sponsorship', 'wordcamporg' ),
		'sv_company'  => __( 'Company', 'wordcamporg' ),
		'sv_status'   => __( 'Status', 'wordcamporg' ),
		'sv_approved' => __( 'Approved', 'wordcamporg' ),
	);
}
add_filter( 'manage_' . SPONSORSHIP_POST_TYPE . '_posts_columns', __NAMESPACE__ . '\get_sponsorship_columns' );

/**
 * Renders a custom column of the sponsorship list.
 *
 * @param string $column         The column.
 * @param int    $sponsorship_id The sponsorship.
 */
function render_sponsorship_column( string $column, int $sponsorship_id ): void {
	prime_listed_sponsorships();

	$company_id = (int) get_post_field( 'post_parent', $sponsorship_id );

	switch ( $column ) {
		case 'sv_company':
			if ( $company_id ) {
				printf(
					'<a href="%s">%s</a> (%s)',
					esc_url( (string) get_edit_post_link( $company_id ) ),
					esc_html( get_the_title( $company_id ) ),
					esc_html( get_company_statuses()[ get_post_status( $company_id ) ] ?? '' )
				);
			} else {
				esc_html_e( 'Needs a company', 'wordcamporg' );
			}
			break;

		case 'sv_status':
			echo esc_html( get_sponsorship_statuses()[ get_post_status( $sponsorship_id ) ] ?? '' );
			break;

		case 'sv_approved':
			echo esc_html( get_approval_summary( $sponsorship_id ) );
			break;
	}
}
add_action( 'manage_' . SPONSORSHIP_POST_TYPE . '_posts_custom_column', __NAMESPACE__ . '\render_sponsorship_column', 10, 2 );

/**
 * Loads the companies and approvers of the sponsorships on the list screen at once, instead of one row at a time.
 */
function prime_listed_sponsorships(): void {
	static $primed = false;

	if ( $primed ) {
		return;
	}

	$primed       = true;
	$sponsorships = $GLOBALS['wp_query']->posts ?? array();

	_prime_post_caches( array_filter( array_map( 'intval', wp_list_pluck( $sponsorships, 'post_parent' ) ) ), false, false );
	cache_users( array_filter( array_map( static fn( \WP_Post $sponsorship ): int => (int) get_post_meta( $sponsorship->ID, '_sv_approved_by', true ), $sponsorships ) ) );
}

/**
 * Describes who approved a sponsorship and when.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return string
 */
function get_approval_summary( int $sponsorship_id ): string {
	$approved_at = (int) get_post_meta( $sponsorship_id, '_sv_approved_at', true );

	if ( ! $approved_at ) {
		return '—';
	}

	$approver = get_userdata( (int) get_post_meta( $sponsorship_id, '_sv_approved_by', true ) );
	$summary  = sprintf(
		/* translators: 1: Date. 2: Username. */
		__( '%1$s by %2$s', 'wordcamporg' ),
		wp_date( 'Y-m-d', $approved_at ),
		$approver ? $approver->user_login : __( 'System', 'wordcamporg' )
	);

	if ( get_post_meta( $sponsorship_id, '_sv_good_faith', true ) ) {
		$summary .= ' ' . __( '(in good faith)', 'wordcamporg' );
	}

	return $summary;
}

/**
 * Adds a view of the sponsorships still waiting for a reviewer to pick their company.
 *
 * @param string[] $views The list's views.
 * @return string[]
 */
function add_unlinked_view( array $views ): array {
	$count = ( new \WP_Query(
		array(
			'post_type'      => SPONSORSHIP_POST_TYPE,
			'post_status'    => array_keys( get_sponsorship_statuses() ),
			'post_parent'    => 0,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	) )->found_posts;

	$views['sv_unlinked'] = sprintf(
		'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
		esc_url( admin_url( 'edit.php?post_type=' . SPONSORSHIP_POST_TYPE . '&sv_unlinked=1' ) ),
		isset( $_GET['sv_unlinked'] ) ? ' class="current" aria-current="page"' : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter.
		esc_html__( 'Needs a company', 'wordcamporg' ),
		esc_html( number_format_i18n( $count ) )
	);

	return $views;
}
add_filter( 'views_edit-' . SPONSORSHIP_POST_TYPE, __NAMESPACE__ . '\add_unlinked_view' );

/**
 * Limits the sponsorship list to one company's, or to the ones without a company.
 *
 * @param \WP_Query $query The list's query.
 */
function filter_sponsorships( \WP_Query $query ): void {
	if ( ! is_admin() || ! $query->is_main_query() || SPONSORSHIP_POST_TYPE !== $query->get( 'post_type' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters.
	if ( isset( $_GET['sv_unlinked'] ) ) {
		$query->set( 'post_parent', 0 );
	} elseif ( isset( $_GET['sv_company'] ) ) {
		$query->set( 'post_parent', absint( $_GET['sv_company'] ) );
	}
	// phpcs:enable
}
add_action( 'pre_get_posts', __NAMESPACE__ . '\filter_sponsorships' );

/**
 * Replaces core's Publish box with the review boxes.
 */
function add_sponsorship_meta_boxes(): void {
	remove_meta_box( 'submitdiv', SPONSORSHIP_POST_TYPE, 'side' );

	add_meta_box( 'sv-status', __( 'Approval', 'wordcamporg' ), __NAMESPACE__ . '\render_sponsorship_status_box', SPONSORSHIP_POST_TYPE, 'side', 'high' );
	add_meta_box( 'sv-company', __( 'Company', 'wordcamporg' ), __NAMESPACE__ . '\render_sponsorship_company_box', SPONSORSHIP_POST_TYPE, 'normal', 'high' );
	add_meta_box( 'sv-sponsor', __( 'Event Sponsor', 'wordcamporg' ), __NAMESPACE__ . '\render_camp_sponsor_box', SPONSORSHIP_POST_TYPE, 'normal', 'high' );
	add_meta_box( 'sv-log', __( 'Notes and History', 'wordcamporg' ), __NAMESPACE__ . '\render_log_box', SPONSORSHIP_POST_TYPE, 'normal', 'low' );
}
add_action( 'add_meta_boxes_' . SPONSORSHIP_POST_TYPE, __NAMESPACE__ . '\add_sponsorship_meta_boxes' );

/**
 * Renders the status, the approval actions, and the Save button.
 *
 * @param \WP_Post $post The sponsorship.
 */
function render_sponsorship_status_box( \WP_Post $post ): void {
	$company_id = (int) $post->post_parent;
	$state      = $company_id ? get_vetting_state( $company_id ) : null;

	// Approval has its own buttons, which check the company and stamp who approved it.
	render_status_select( $post, array( 'svs-approved' ) );
	?>
	<p>
		<strong><?php esc_html_e( 'Approved', 'wordcamporg' ); ?></strong><br>
		<?php echo esc_html( get_approval_summary( $post->ID ) ); ?>
	</p>

	<?php if ( 'svs-pending' === $post->post_status ) : ?>
		<?php if ( $state && $state['approvable'] ) : ?>
			<p><button type="submit" name="sv_action" value="approve" class="button button-primary"><?php esc_html_e( 'Approve', 'wordcamporg' ); ?></button></p>
		<?php elseif ( $state && 'sv-compliance-req' === $state['status'] ) : ?>
			<p><button type="submit" name="sv_action" value="approve_good_faith" class="button"><?php esc_html_e( 'Approve in Good Faith', 'wordcamporg' ); ?></button></p>
			<p class="description">
				<?php
				/* translators: %d: Number of days. */
				printf( esc_html__( 'Only when the sponsor has committed to the changes and the event can’t wait. They then have %d days to finish.', 'wordcamporg' ), (int) GOOD_FAITH_DAYS );
				?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php
				/* translators: %d: Number of months. */
				printf( esc_html__( 'Can be approved once the company is Compliant, vetted within the last %d months.', 'wordcamporg' ), (int) REVETTING_MONTHS );
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( 'svs-approved' === $post->post_status ) : ?>
		<?php render_conversations( get_conversations( $post->ID, 'approval' ) ); ?>
		<?php if ( needs_approval_email( $post->ID ) ) : ?>
			<p><button type="submit" name="sv_action" value="send_approval" class="button"><?php esc_html_e( 'Send approval email', 'wordcamporg' ); ?></button></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php
	render_save_button();
}

/**
 * Renders the sponsorship's company, or the choices for linking one.
 *
 * @param \WP_Post $post The sponsorship.
 */
function render_sponsorship_company_box( \WP_Post $post ): void {
	$company_id   = (int) $post->post_parent;
	$suggested_id = (int) get_post_meta( $post->ID, '_sv_suggested_company', true );

	if ( $company_id ) {
		printf(
			'<p><a href="%s">%s</a>: %s</p>',
			esc_url( (string) get_edit_post_link( $company_id ) ),
			esc_html( get_the_title( $company_id ) ),
			esc_html( get_company_statuses()[ get_post_status( $company_id ) ] ?? '' )
		);
	} elseif ( $suggested_id && COMPANY_POST_TYPE === get_post_type( $suggested_id ) ) {
		?>
		<p>
			<?php
			/* translators: %s: Company name. */
			printf( esc_html__( 'The website matches %s.', 'wordcamporg' ), '<a href="' . esc_url( (string) get_edit_post_link( $suggested_id ) ) . '">' . esc_html( get_the_title( $suggested_id ) ) . '</a>' );
			?>
			<button type="submit" name="sv_action" value="confirm_match" class="button"><?php esc_html_e( 'Confirm', 'wordcamporg' ); ?></button>
		</p>
		<?php
	} else {
		echo '<p>' . esc_html__( 'Not linked to a company yet.', 'wordcamporg' ) . '</p>';
	}

	if ( 'svs-approved' === $post->post_status ) {
		echo '<p class="description">' . esc_html__( 'To move an approved sponsorship to another company, cancel it and reopen it first.', 'wordcamporg' ) . '</p>';
		return;
	}

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
	?>
	<p>
		<label for="sv-company-id"><?php echo esc_html( $company_id ? __( 'Move to another company', 'wordcamporg' ) : __( 'Link to a company', 'wordcamporg' ) ); ?></label><br>
		<select id="sv-company-id" name="sv_company_id">
			<option value="0"></option>
			<?php foreach ( $companies as $company ) : ?>
				<option value="<?php echo esc_attr( (string) $company->ID ); ?>"><?php echo esc_html( $company->post_title ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<?php if ( ! $company_id ) : ?>
		<p><button type="submit" name="sv_action" value="create_company" class="button"><?php esc_html_e( 'Create a company from this sponsor', 'wordcamporg' ); ?></button></p>
	<?php endif; ?>
	<?php
}

/**
 * Renders what the camp entered for its sponsor.
 *
 * @param \WP_Post $post The sponsorship.
 */
function render_camp_sponsor_box( \WP_Post $post ): void {
	$blog_id    = (int) get_post_meta( $post->ID, '_sv_blog_id', true );
	$sponsor_id = (int) get_post_meta( $post->ID, '_sv_sponsor_id', true );
	$sponsor    = get_camp_sponsor( $blog_id, $sponsor_id );

	if ( ! $sponsor ) {
		echo '<p>' . esc_html__( 'The event’s sponsor has been deleted.', 'wordcamporg' ) . '</p>';
		return;
	}

	$rows = array(
		__( 'Event', 'wordcamporg' ) => get_event_name( $blog_id ),
		__( 'Name', 'wordcamporg' )  => $sponsor['name'],
	);
	?>
	<table class="form-table" role="presentation">
		<?php foreach ( $rows as $label => $value ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $label ); ?></th>
				<td><?php echo esc_html( $value ); ?></td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Website', 'wordcamporg' ); ?></th>
			<td><?php render_website( $sponsor['website'] ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Contact', 'wordcamporg' ); ?></th>
			<td><?php echo esc_html( trim( $sponsor['first_name'] . ' ' . $sponsor['last_name'] . ' ' . ( $sponsor['email'] ? '<' . $sponsor['email'] . '>' : '' ) ) ); ?></td>
		</tr>
	</table>
	<p><a href="<?php echo esc_url( get_admin_url( $blog_id, 'post.php?post=' . $sponsor_id . '&action=edit' ) ); ?>"><?php esc_html_e( 'Open on the event site', 'wordcamporg' ); ?></a></p>
	<?php
}

/**
 * Saves the company link, a new note, and the approval actions.
 *
 * The override statuses are saved by core, from the `post_status` field, and checked by
 * enforce_status_transition().
 *
 * @param int $post_id The sponsorship.
 */
function save_sponsorship( int $post_id ): void {
	if ( ! is_valid_save( $post_id ) ) {
		return;
	}

	is_saving( true );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().
	$action     = sanitize_key( wp_unslash( (string) ( $_POST['sv_action'] ?? '' ) ) );
	$company_id = absint( $_POST['sv_company_id'] ?? 0 );
	// phpcs:enable

	if ( 'confirm_match' === $action ) {
		$company_id = (int) get_post_meta( $post_id, '_sv_suggested_company', true );
	} elseif ( 'create_company' === $action ) {
		$sponsor    = get_camp_sponsor( (int) get_post_meta( $post_id, '_sv_blog_id', true ), (int) get_post_meta( $post_id, '_sv_sponsor_id', true ) );
		$company_id = $sponsor ? create_company( $sponsor ) : 0;
		$company_id = is_wp_error( $company_id ) ? 0 : $company_id;
	}

	if ( $company_id && COMPANY_POST_TYPE === get_post_type( $company_id ) && (int) get_post_field( 'post_parent', $post_id ) !== $company_id ) {
		$linked = link_sponsorship( $post_id, $company_id );

		if ( is_wp_error( $linked ) ) {
			add_notice( 'error', $linked->get_error_message() );
		} else {
			queue_company( $post_id );
		}
	}

	save_note( $post_id );

	if ( in_array( $action, array( 'approve', 'approve_good_faith' ), true ) ) {
		$approved = approve_sponsorship( $post_id, 'approve_good_faith' === $action );

		if ( is_wp_error( $approved ) ) {
			add_notice( 'error', $approved->get_error_message() );
		} else {
			add_notice( 'success', __( 'The sponsorship was approved.', 'wordcamporg' ) );
			$action = 'send_approval';
		}
	}

	if ( 'send_approval' === $action ) {
		$sent = send_approval_email( $post_id );

		if ( is_wp_error( $sent ) ) {
			add_notice( 'error', get_send_error_message( $sent ) );
		} elseif ( $sent ) {
			add_notice( 'success', __( 'The approval email was sent to the event.', 'wordcamporg' ) );
		} else {
			add_notice( 'success', __( 'No approval email was needed: the event has invoiced the sponsor, and approving the invoice tells them.', 'wordcamporg' ) );
		}
	}

	is_saving( false );
}
add_action( 'save_post_' . SPONSORSHIP_POST_TYPE, __NAMESPACE__ . '\save_sponsorship' );
