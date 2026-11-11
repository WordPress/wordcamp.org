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
		'sv_event'    => __( 'Event date', 'wordcamporg' ),
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

		case 'sv_event':
			$start = get_listed_event_date( $sponsorship_id );
			echo esc_html( $start ? format_event_date( $start ) : '—' );
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
 * Lets the sponsorship list be sorted by event date.
 *
 * @param array $columns The sortable columns.
 * @return array
 */
function get_sortable_sponsorship_columns( array $columns ): array {
	$columns['sv_event'] = array( 'sv_event', false );

	return $columns;
}
add_filter( 'manage_edit-' . SPONSORSHIP_POST_TYPE . '_sortable_columns', __NAMESPACE__ . '\get_sortable_sponsorship_columns' );

/**
 * Sorts the sponsorship list by event date, when the reviewer picks that column.
 *
 * @param string[]  $clauses The list's query clauses.
 * @param \WP_Query $query   The list's query.
 * @return string[]
 */
function sort_sponsorships( array $clauses, \WP_Query $query ): array {
	global $wpdb;

	if ( ! $query->is_main_query() || 'sv_event' !== $query->get( 'orderby' ) ) {
		return $clauses;
	}

	$blog_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The camp sites of every sponsorship, once per sorted list screen.
		$wpdb->prepare(
			"SELECT DISTINCT sv_blog.meta_value FROM {$wpdb->postmeta} sv_blog INNER JOIN {$wpdb->posts} sv_sponsorship ON sv_sponsorship.ID = sv_blog.post_id AND sv_sponsorship.post_type = %s WHERE sv_blog.meta_key = '_sv_blog_id'",
			SPONSORSHIP_POST_TYPE
		)
	);
	$dates    = get_event_dates( $blog_ids );

	asort( $dates );

	return sort_by_ids( $clauses, $query, "( SELECT CAST( sv_blog.meta_value AS UNSIGNED ) FROM {$wpdb->postmeta} sv_blog WHERE sv_blog.post_id = {$wpdb->posts}.ID AND sv_blog.meta_key = '_sv_blog_id' LIMIT 1 )", array_keys( $dates ) );
}

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
 * Gets when a sponsorship's event starts on the list screen, looking up the sponsorships listed with it at once.
 *
 * Quick Edit redraws one row without the list's query, so a sponsorship that wasn't listed is looked up on its own.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return int The start date, as get_event_dates() returns it, or 0 for none.
 */
function get_listed_event_date( int $sponsorship_id ): int {
	static $starts = array();

	if ( ! array_key_exists( $sponsorship_id, $starts ) ) {
		$listed   = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts ?? array(), 'ID' ) );
		$blog_ids = get_sponsorship_blog_ids( array_diff( array_merge( $listed, array( $sponsorship_id ) ), array_keys( $starts ) ) );
		$dates    = get_event_dates( $blog_ids );

		foreach ( $blog_ids as $id => $blog_id ) {
			$starts[ $id ] = $dates[ $blog_id ] ?? 0;
		}
	}

	return $starts[ $sponsorship_id ];
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
	if ( ! $query->is_main_query() ) {
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

/**
 * Filters the queries of the sponsorship list screen only.
 */
function load_sponsorship_list(): void {
	if ( SPONSORSHIP_POST_TYPE === get_current_screen()?->post_type ) {
		add_action( 'pre_get_posts', __NAMESPACE__ . '\filter_sponsorships' );
		add_filter( 'posts_clauses', __NAMESPACE__ . '\sort_sponsorships', 10, 2 );
	}
}
add_action( 'load-edit.php', __NAMESPACE__ . '\load_sponsorship_list' );

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
		<input type="hidden" name="sv_shown_domain" value="<?php echo esc_attr( (string) get_current_domain( $post->ID ) ); ?>">
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

	<?php if ( 'svs-approved' === $post->post_status && website_changed( $post->ID ) ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: 1: Approved website domain, 2: New website domain. */
					esc_html__( 'The camp changed the sponsor’s website from %1$s to %2$s after approval. Invoices can’t be approved until you confirm the new website.', 'wordcamporg' ),
					'<code>' . esc_html( (string) get_post_meta( $post->ID, '_sv_approved_domain', true ) ?: __( 'none', 'wordcamporg' ) ) . '</code>',
					'<code>' . esc_html( (string) get_current_domain( $post->ID ) ?: __( 'none', 'wordcamporg' ) ) . '</code>'
				);
				?>
			</p>
			<input type="hidden" name="sv_confirm_domain" value="<?php echo esc_attr( (string) get_current_domain( $post->ID ) ); ?>">
			<p><button type="submit" name="sv_action" value="confirm_website" class="button"><?php esc_html_e( 'Confirm new website', 'wordcamporg' ); ?></button></p>
			<p class="description"><?php esc_html_e( 'If it’s a different company now, cancel the sponsorship instead.', 'wordcamporg' ); ?></p>
		</div>
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
		$sponsor = get_camp_sponsor( (int) get_post_meta( $post->ID, '_sv_blog_id', true ), (int) get_post_meta( $post->ID, '_sv_sponsor_id', true ) );

		if ( $sponsor && in_array( normalize_domain( $sponsor['website'] ), get_company_domains( $suggested_id ), true ) ) {
			/* translators: %s: Company name. */
			$message = __( 'The website matches %s.', 'wordcamporg' );
		} elseif ( $sponsor && $sponsor['mes_id'] ) {
			/* translators: %s: Company name. */
			$message = __( 'This is a copy of the Multi-Event Sponsor of %s, but its website isn’t one of the company’s domains.', 'wordcamporg' );
		} else {
			/* translators: %s: Company name. */
			$message = __( 'Suggested: %s. The website doesn’t match the company’s domains.', 'wordcamporg' );
		}
		?>
		<p>
			<?php printf( esc_html( $message ), '<a href="' . esc_url( (string) get_edit_post_link( $suggested_id ) ) . '">' . esc_html( get_the_title( $suggested_id ) ) . '</a>' ); ?>
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

	$start = get_event_dates( array( $blog_id ) )[ $blog_id ] ?? 0;
	$rows  = array(
		__( 'Event', 'wordcamporg' )      => get_event_name( $blog_id ),
		__( 'Event date', 'wordcamporg' ) => $start ? format_event_date( $start ) : '—',
		__( 'Name', 'wordcamporg' )       => $sponsor['name'],
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

			if ( 'svs-approved' === get_post_status( $post_id ) ) {
				add_notice( 'success', __( 'The sponsorship was approved automatically: its company is vetted.', 'wordcamporg' ) );

				if ( needs_approval_email( $post_id ) ) {
					add_notice( 'error', __( 'The approval email wasn’t sent (see history). Send it again below.', 'wordcamporg' ) );
				}

				// Already approved, so an Approve button sent with the link has nothing left to do.
				$action = in_array( $action, array( 'approve', 'approve_good_faith' ), true ) ? '' : $action;
			}
		}
	}

	save_note( $post_id );

	if ( 'confirm_website' === $action ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().
		$confirmed = confirm_website( $post_id, sanitize_text_field( wp_unslash( (string) ( $_POST['sv_confirm_domain'] ?? '' ) ) ) );

		if ( is_wp_error( $confirmed ) ) {
			add_notice( 'error', $confirmed->get_error_message() );
		} else {
			add_notice( 'success', __( 'The new website was confirmed.', 'wordcamporg' ) );
		}
	}

	if ( in_array( $action, array( 'approve', 'approve_good_faith' ), true ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by is_valid_save().
		$approved = approve_sponsorship( $post_id, 'approve_good_faith' === $action, sanitize_text_field( wp_unslash( (string) ( $_POST['sv_shown_domain'] ?? '' ) ) ) );

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
		} elseif ( get_conversations( $post_id, 'approval' ) ) {
			add_notice( 'success', __( 'No approval email was sent: the event already has one.', 'wordcamporg' ) );
		} else {
			add_notice( 'success', __( 'No approval email was needed: the event has invoiced the sponsor, and approving the invoice tells them.', 'wordcamporg' ) );
		}
	}

	is_saving( false );
}
add_action( 'save_post_' . SPONSORSHIP_POST_TYPE, __NAMESPACE__ . '\save_sponsorship' );
