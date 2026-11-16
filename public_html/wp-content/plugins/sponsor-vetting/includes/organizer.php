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
 * @return array[] Keyed by sponsor ID: `status`, a key of get_vetting_status_labels(), `label`, and `requested`,
 *                 whether the sponsor has a sponsorship.
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

	$labels   = get_vetting_status_labels();
	$statuses = array();
	foreach ( $sponsor_ids as $sponsor_id ) {
		$sponsorship = $sponsorships[ $pointers[ $sponsor_id ] ] ?? null;
		$company_id  = get_mes_company( $mes_ids[ $sponsor_id ] );
		$status      = 'not-requested';
		$label       = $labels[ $status ];

		if ( $sponsorship && get_sponsorship_key( $blog_id, $sponsor_id ) === get_post_meta( $sponsorship->ID, '_sv_key', true ) ) {
			$status = get_sponsorship_vetting_status( $sponsorship );
			$label  = $labels[ $status ];
		} elseif ( $company_id ) {
			$status = get_company_vetting_status( $company_id );
			/* translators: %s: Vetting status. */
			$label       = sprintf( __( 'Multi-event sponsor: %s', 'wordcamporg' ), $labels[ $status ] );
			$sponsorship = null;
		} else {
			$sponsorship = null;
		}

		$statuses[ $sponsor_id ] = array(
			'status'    => $status,
			'label'     => $label,
			// Vetting that stopped because the company went Inactive can be asked for again.
			'requested' => null !== $sponsorship && ! is_canceled_for_inactive( $sponsorship->ID ),
		);
	}

	restore_current_blog();

	return $statuses;
}

/**
 * The vetting statuses organizers see, in the order the vetting goes through them.
 *
 * @return string[] Labels, keyed by status.
 */
function get_vetting_status_labels(): array {
	return array(
		'not-requested' => __( 'Not requested', 'wordcamporg' ),
		'in-review'     => __( 'In review', 'wordcamporg' ),
		'waiting'       => __( 'Waiting on the sponsor', 'wordcamporg' ),
		'vetted'        => __( 'Vetted', 'wordcamporg' ),
		'approved'      => __( 'Approved', 'wordcamporg' ),
		'not-approved'  => __( 'Not approved', 'wordcamporg' ),
		'stopped'       => __( 'Vetting stopped', 'wordcamporg' ),
		'canceled'      => __( 'Canceled', 'wordcamporg' ),
	);
}

/**
 * Gets where a sponsorship stands, as organizers may see it. Runs on Central.
 *
 * @param \WP_Post $sponsorship The sponsorship.
 * @return string A key of get_vetting_status_labels().
 */
function get_sponsorship_vetting_status( \WP_Post $sponsorship ): string {
	return match ( $sponsorship->post_status ) {
		'svs-approved'     => 'approved',
		'svs-not-approved' => 'not-approved',
		'svs-canceled'     => is_canceled_for_inactive( $sponsorship->ID ) ? 'stopped' : 'canceled',
		default            => $sponsorship->post_parent ? get_company_vetting_status( (int) $sponsorship->post_parent ) : 'in-review',
	};
}

/**
 * Gets where a company's vetting stands, as organizers may see it, without its details. Runs on Central.
 *
 * @param int $company_id The company.
 * @return string A key of get_vetting_status_labels().
 */
function get_company_vetting_status( int $company_id ): string {
	$state = get_vetting_state( $company_id );

	if ( $state['approvable'] ) {
		return 'vetted';
	}

	return match ( $state['status'] ) {
		'sv-compliance-req' => 'waiting',
		'sv-inactive'       => 'stopped',
		default             => 'in-review',
	};
}

/**
 * Words a company's vetting for organizers, without its details. Runs on Central.
 *
 * @param int $company_id The company.
 * @return string
 */
function get_company_label( int $company_id ): string {
	return get_vetting_status_labels()[ get_company_vetting_status( $company_id ) ];
}

/**
 * The Contact Information fields a camp's sponsor needs before vetting can be requested.
 *
 * The vetting team writes to this contact, and finds the company by its website. Not the phone number, which never
 * reaches them.
 *
 * @return string[] Their meta keys, which the Sponsor Info box also names its fields by.
 */
function get_required_contact_fields(): array {
	return array(
		'_wcpt_sponsor_company_name',
		'_wcpt_sponsor_website',
		'_wcpt_sponsor_first_name',
		'_wcpt_sponsor_last_name',
		'_wcpt_sponsor_email_address',
	);
}

/**
 * What organizers are told when Contact Information keeps vetting from being requested.
 *
 * @return string[] Messages, keyed by problem: `missing`, `website` or `email`.
 */
function get_contact_info_messages(): array {
	return array(
		'missing' => __( 'Fill in the sponsor’s contact information before requesting vetting.', 'wordcamporg' ),
		'website' => __( 'Enter the sponsor’s full website address, like example.com, before requesting vetting.', 'wordcamporg' ),
		'email'   => __( 'Enter a valid email address for the sponsor’s contact before requesting vetting.', 'wordcamporg' ),
	);
}

/**
 * Gets what in a camp's sponsor's Contact Information keeps vetting from being requested.
 *
 * An empty field comes first, then a website the vetting team can't get a domain from, then an email address they
 * can't write to.
 *
 * @param int $sponsor_id The camp's sponsor.
 * @return string The message for organizers, or an empty string when vetting can be requested.
 */
function get_contact_info_problem( int $sponsor_id ): string {
	$values = array();

	foreach ( get_required_contact_fields() as $meta_key ) {
		$values[ $meta_key ] = trim( (string) get_post_meta( $sponsor_id, $meta_key, true ) );
	}

	$problem = match ( true ) {
		in_array( '', $values, true )                          => 'missing',
		! normalize_domain( $values['_wcpt_sponsor_website'] ) => 'website',
		! is_email( $values['_wcpt_sponsor_email_address'] )   => 'email',
		default                                                => '',
	};

	return '' === $problem ? '' : get_contact_info_messages()[ $problem ];
}

/**
 * Adds the vetting box to a camp's sponsor, first among the sidebar's boxes so organizers see it.
 *
 * Organizers who rearranged the boxes keep their order.
 */
function add_organizer_meta_box(): void {
	if ( ! is_central() ) {
		add_meta_box( 'sv-vetting', __( 'Sponsor Vetting', 'wordcamporg' ), __NAMESPACE__ . '\render_organizer_meta_box', 'wcb_sponsor', 'side', 'high' );
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

	$problem = get_contact_info_problem( $post->ID );

	if ( $problem ) {
		echo '<p class="description">' . esc_html( $problem ) . '</p>';
	}

	wp_nonce_field( 'sv_request_vetting_' . $post->ID, 'sv_request_vetting_nonce', false );
	?>
	<p>
		<button
			type="button"
			class="button"
			data-required-fields="<?php echo esc_attr( implode( ' ', get_required_contact_fields() ) ); ?>"
			data-messages="<?php echo esc_attr( (string) wp_json_encode( get_contact_info_messages() ) ); ?>"
		>
			<?php esc_html_e( 'Request vetting', 'wordcamporg' ); ?>
		</button>
	</p>
	<p class="description"><?php esc_html_e( 'The Community Team checks every sponsor follows the WordPress trademark policy and the GPL. You don’t need an invoice to ask.', 'wordcamporg' ); ?></p>
	<?php
}

/**
 * Loads the script that saves the sponsor when vetting is requested, and that redraws the vetting box after the
 * block editor saves, since that doesn't reload the page.
 *
 * Without the redraw, a new sponsor's box keeps asking organizers to save it after they have. Hooked to the edit
 * screens' own script hooks, which run just before scripts are printed.
 */
function enqueue_organizer_script(): void {
	$screen = get_current_screen();

	if ( is_central() || ! $screen || 'wcb_sponsor' !== $screen->post_type ) {
		return;
	}

	$plugin_dir = dirname( __DIR__ );
	$deps       = $screen->is_block_editor() ? array( 'wp-api-fetch', 'wp-data', 'wp-hooks', 'wp-notices', 'wp-preferences' ) : array();

	wp_enqueue_script( 'sv-organizer', plugins_url( 'js/organizer.js', $plugin_dir . '/bootstrap.php' ), $deps, (string) filemtime( $plugin_dir . '/js/organizer.js' ), true );
}
add_action( 'admin_print_scripts-post.php', __NAMESPACE__ . '\enqueue_organizer_script' );
add_action( 'admin_print_scripts-post-new.php', __NAMESPACE__ . '\enqueue_organizer_script' );

/**
 * Registers the route the script reads the vetting box from.
 *
 * REST rather than admin-ajax, so the editor keeps the nonce fresh however long it stays open.
 */
function register_organizer_routes(): void {
	register_rest_route(
		'sponsor-vetting/v1',
		'/vetting-box/(?P<id>\d+)',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\get_organizer_meta_box',
			'permission_callback' => static fn( \WP_REST_Request $request ): bool => ! is_central()
				&& 'wcb_sponsor' === get_post_type( (int) $request['id'] )
				&& current_user_can( 'edit_post', (int) $request['id'] ),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\register_organizer_routes' );

/**
 * Renders the vetting box's current contents, for the script that redraws it, with the notices the save queued.
 *
 * The block editor saves without loading a new screen, so the notices would otherwise wait for the next one.
 *
 * @param \WP_REST_Request $request The request, naming the camp's sponsor.
 * @return array {
 *     @type string  $html    The box's contents.
 *     @type array[] $notices The current user's queued notices, as take_notices() returns them.
 * }
 */
function get_organizer_meta_box( \WP_REST_Request $request ): array {
	ob_start();
	render_organizer_meta_box( get_post( (int) $request['id'] ) );

	return array(
		'html'    => (string) ob_get_clean(),
		'notices' => take_notices(),
	);
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
 * Lets a camp's sponsor list be sorted by vetting status.
 *
 * @param array $columns The sortable columns.
 * @return array
 */
function get_sortable_organizer_columns( array $columns ): array {
	if ( ! is_central() ) {
		$columns['sv_vetting'] = array( 'sv_vetting', false );
	}

	return $columns;
}
add_filter( 'manage_edit-wcb_sponsor_sortable_columns', __NAMESPACE__ . '\get_sortable_organizer_columns' );

/**
 * The vetting statuses a camp's sponsor list can be filtered by, each standing for the statuses that call for the
 * same from organizers: nothing yet, waiting on the vetting, done, or not going ahead.
 *
 * @return array[] The statuses each takes in, keyed by the status it's named after.
 */
function get_vetting_filter_groups(): array {
	return array(
		'not-requested' => array( 'not-requested' ),
		'in-review'     => array( 'in-review', 'waiting', 'vetted' ),
		'approved'      => array( 'approved' ),
		'not-approved'  => array( 'not-approved', 'stopped', 'canceled' ),
	);
}

/**
 * Gets the vetting status the organizer filtered the sponsor list by.
 *
 * @return string A key of get_vetting_filter_groups(), or an empty string for all.
 */
function get_picked_vetting_status(): string {
	$status = $_GET['sv_vetting'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A list filter, checked against the filter's statuses.

	return is_string( $status ) && isset( get_vetting_filter_groups()[ $status ] ) ? $status : '';
}

/**
 * Renders the vetting status dropdown above a camp's sponsor list.
 *
 * @param string $post_type The listed post type.
 * @param string $which     Where the filters are shown, above or below the list.
 */
function render_vetting_filter( string $post_type, string $which ): void {
	if ( 'top' !== $which ) {
		return;
	}

	$current = get_picked_vetting_status();
	$labels  = get_vetting_status_labels();
	?>
	<label for="sv-vetting-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by vetting status', 'wordcamporg' ); ?></label>
	<select name="sv_vetting" id="sv-vetting-filter">
		<option value=""><?php esc_html_e( 'All vetting statuses', 'wordcamporg' ); ?></option>
		<?php foreach ( array_keys( get_vetting_filter_groups() ) as $status ) : ?>
			<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current, $status ); ?>><?php echo esc_html( $labels[ $status ] ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Limits a camp's sponsor list to the vetting statuses picked, and sorts it by vetting status when the organizer
 * picks that column.
 *
 * The statuses live on Central, so they're looked up for every sponsor the list would show, then handed back to
 * the list as the IDs to show in order. A Multi-Event Sponsor's copy counts as its company's status.
 *
 * @param \WP_Query $query The list's query.
 */
function filter_sponsors_by_vetting( \WP_Query $query ): void {
	$picked  = get_picked_vetting_status();
	$sorting = 'sv_vetting' === $query->get( 'orderby' );

	if ( ! $query->is_main_query() || ( '' === $picked && ! $sorting ) ) {
		return;
	}

	$sponsor_ids = ( new \WP_Query(
		array_merge(
			$query->query,
			array(
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		)
	) )->posts;
	$statuses    = get_organizer_statuses( $sponsor_ids );

	if ( '' !== $picked ) {
		$group       = get_vetting_filter_groups()[ $picked ];
		$sponsor_ids = array_values( array_filter( $sponsor_ids, static fn( int $id ): bool => in_array( $statuses[ $id ]['status'], $group, true ) ) );
	}

	if ( $sorting ) {
		$rank = array_flip( array_keys( get_vetting_status_labels() ) );
		$desc = 'DESC' === strtoupper( (string) $query->get( 'order' ) );

		// Stable, so sponsors with the same status stay in title order.
		usort( $sponsor_ids, static fn( int $a, int $b ): int => ( $rank[ $statuses[ $a ]['status'] ] <=> $rank[ $statuses[ $b ]['status'] ] ) * ( $desc ? -1 : 1 ) );

		$query->set( 'orderby', 'post__in' );
	}

	// An empty list would list every sponsor.
	$query->set( 'post__in', $sponsor_ids ?: array( 0 ) );
}

/**
 * Reminds organizers to request vetting for the camp's sponsors that haven't been submitted yet.
 *
 * Counts only published and scheduled sponsors, the ones the camp has taken on: the Call for Sponsors form makes a
 * draft of every application, accepted or not. Links to the sponsors not submitted through the vetting filter,
 * unless the list already shows only them.
 */
function render_vetting_reminder(): void {
	$sponsor_ids = get_posts(
		array(
			'post_type'      => 'wcb_sponsor',
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	$count       = count(
		array_filter(
			get_organizer_statuses( $sponsor_ids ),
			static fn( array $status ): bool => 'not-requested' === $status['status']
		)
	);

	if ( ! $count ) {
		return;
	}

	$message = esc_html(
		sprintf(
			/* translators: %s: Number of sponsors. */
			_n(
				'%s sponsor hasn’t been submitted for vetting yet. Open it and click “Request vetting” in its Sponsor Vetting box.',
				'%s sponsors haven’t been submitted for vetting yet. Open each one and click “Request vetting” in its Sponsor Vetting box.',
				$count,
				'wordcamporg'
			),
			number_format_i18n( $count )
		)
	);

	if ( 'not-requested' !== get_picked_vetting_status() ) {
		$message .= sprintf(
			' <a href="%s">%s</a>',
			esc_url( add_query_arg( 'sv_vetting', 'not-requested', admin_url( 'edit.php?post_type=wcb_sponsor' ) ) ),
			esc_html( _n( 'Show it', 'Show them', $count, 'wordcamporg' ) )
		);
	}

	wp_admin_notice( $message, array( 'type' => 'info' ) );
}

/**
 * Adds the vetting reminder, filter and sort to a camp's sponsor list screen only.
 */
function load_organizer_list(): void {
	if ( ! is_central() && 'wcb_sponsor' === get_current_screen()?->post_type ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\render_vetting_reminder' );
		add_action( 'restrict_manage_posts', __NAMESPACE__ . '\render_vetting_filter', 10, 2 );
		add_action( 'pre_get_posts', __NAMESPACE__ . '\filter_sponsors_by_vetting' );
	}
}
add_action( 'load-edit.php', __NAMESPACE__ . '\load_organizer_list' );

/**
 * Requests vetting when a camp's sponsor is saved with the vetting box's button.
 *
 * Part of the save, so the request carries what the organizer entered with it. Runs after `save_post`, where the
 * sponsor's meta is saved.
 *
 * @param int      $post_id The post ID.
 * @param \WP_Post $post    The post.
 */
function request_vetting_on_save( int $post_id, \WP_Post $post ): void {
	if (
		'wcb_sponsor' !== $post->post_type
		|| is_central()
		|| ! is_admin()
		|| ! isset( $_POST['sv_request_vetting'], $_POST['sv_request_vetting_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['sv_request_vetting_nonce'] ), 'sv_request_vetting_' . $post_id )
		|| ! current_user_can( 'edit_post', $post_id )
	) {
		return;
	}

	$problem = get_contact_info_problem( $post_id );

	if ( $problem ) {
		add_notice( 'error', $problem );
		return;
	}

	$result = request_vetting( get_current_blog_id(), $post_id );

	if ( is_wp_error( $result ) ) {
		add_notice( 'error', $result->get_error_message() );
	} else {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$approved = 'svs-approved' === get_post_status( $result );
		restore_current_blog();

		add_notice(
			'success',
			$approved
				? __( 'Approved: the Community Team has already vetted this sponsor’s company.', 'wordcamporg' )
				: __( 'Vetting requested. The Community Team will be in touch with the sponsor if anything needs changing.', 'wordcamporg' )
		);
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\request_vetting_on_save', 10, 2 );
