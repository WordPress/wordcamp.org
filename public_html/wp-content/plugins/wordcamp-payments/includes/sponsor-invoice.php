<?php
/*
 * Create Sponsor Invoice Post type
 */

namespace WordCamp\Budgets\Sponsor_Invoices;
use WP_Post;
use WordCamp_Loader;

defined( 'WPINC' ) || die();

const POST_TYPE = 'wcb_sponsor_invoice';

// Initialization.
add_action( 'init',                  __NAMESPACE__ . '\register_post_type'        );
add_action( 'init',                  __NAMESPACE__ . '\register_post_statuses'    );
add_action( 'add_meta_boxes',        __NAMESPACE__ . '\init_meta_boxes'           );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets',        11 );

// Admin UI.
add_action( 'edit_form_top',                              __NAMESPACE__ . '\print_introduction_text'    );
add_filter( 'display_post_states',                        __NAMESPACE__ . '\display_post_states', 10, 2 );
add_filter( 'manage_'. POST_TYPE .'_posts_columns',       __NAMESPACE__ . '\get_columns'                );
add_action( 'manage_'. POST_TYPE .'_posts_custom_column', __NAMESPACE__ . '\render_columns',      10, 2 );

// Add "Uncollectible" status & action to the list table actions.
add_filter( 'post_row_actions',         __NAMESPACE__ . '\add_row_action', 10, 2 );
add_action( 'post_action_wcbsi_update', __NAMESPACE__ . '\handle_status_action', 10, 3 );
add_action( 'admin_notices',            __NAMESPACE__ . '\action_success_message' );

// Saving posts.
add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\set_invoice_status',  10, 2 );
add_action( 'save_post',           __NAMESPACE__ . '\save_invoice',        10, 2 );
add_filter( 'map_meta_cap',        __NAMESPACE__ . '\modify_capabilities', 10, 4 );

/**
 * Register the custom post type.
 *
 * @return object|\WP_Error
 */
function register_post_type() {
	$labels = array(
		'name'               => esc_html_x( 'Sponsor Invoices', 'general sponsor invoices', 'wordcamporg' ),
		'singular_name'      => esc_html_x( 'Sponsor Invoice',  'post type singular name',  'wordcamporg' ),
		'menu_name'          => esc_html_x( 'Sponsor Invoices', 'admin menu',               'wordcamporg' ),
		'name_admin_bar'     => esc_html_x( 'Sponsor Invoices', 'add new on admin bar',     'wordcamporg' ),
		'add_new'            => esc_html_x( 'Add New',          'invoice',                  'wordcamporg' ),

		'add_new_item'       => esc_html__( 'Add New Sponsor Invoice',    'wordcamporg' ),
		'new_item'           => esc_html__( 'New Invoice',                'wordcamporg' ),
		'edit_item'          => esc_html__( 'Edit Invoice',               'wordcamporg' ),
		'view_item'          => esc_html__( 'View Invoice',               'wordcamporg' ),
		'all_items'          => esc_html__( 'Sponsor Invoices',           'wordcamporg' ),
		'search_items'       => esc_html__( 'Search Invoices',            'wordcamporg' ),
		'not_found'          => esc_html__( 'No invoice found.',          'wordcamporg' ),
		'not_found_in_trash' => esc_html__( 'No invoice found in Trash.', 'wordcamporg' ),
	);

	$args = array(
		'labels'            => $labels,
		'description'       => 'WordCamp Sponsor Invoices',
		'public'            => false,
		'show_ui'           => true,
		'show_in_menu'      => 'wordcamp-budget',
		'show_in_nav_menus' => true,
		'supports'          => array( 'title' ),
		'has_archive'       => true,
	);

	return \register_post_type( POST_TYPE, $args );
}

/**
 * Get the slugs and names for our custom post statuses.
 *
 * @return array
 */
function get_custom_statuses() {
	return array(
		'wcbsi_submitted'     => array(
			'label'       => esc_html__( 'Submitted', 'wordcamporg' ),
			'label_count' => _nx_noop(
				'Submitted <span class="count">(%s)</span>',
				'Submitted <span class="count">(%s)</span>',
				'wordcamporg'
			),
		),
		'wcbsi_approved'      => array(
			'label'       => esc_html__( 'Sent', 'wordcamporg' ),
			'label_count' => _nx_noop(
				'Sent <span class="count">(%s)</span>',
				'Sent <span class="count">(%s)</span>',
				'wordcamporg'
			),
		),
		'wcbsi_paid'          => array(
			'label'       => esc_html__( 'Paid', 'wordcamporg' ),
			'label_count' => _nx_noop(
				'Paid <span class="count">(%s)</span>',
				'Paid <span class="count">(%s)</span>',
				'wordcamporg'
			),
		),
		'wcbsi_uncollectible' => array(
			'label'       => esc_html__( 'Uncollectible', 'wordcamporg' ),
			'label_count' => _nx_noop(
				'Uncollectible <span class="count">(%s)</span>',
				'Uncollectible <span class="count">(%s)</span>',
				'wordcamporg'
			),
		),
		'wcbsi_refunded'      => array(
			'label'       => esc_html__( 'Refunded', 'wordcamporg' ),
			'label_count' => _nx_noop(
				'Refunded <span class="count">(%s)</span>',
				'Refunded <span class="count">(%s)</span>',
				'wordcamporg'
			),
		),
	);
}

/**
 * Register our custom post statuses.
 */
function register_post_statuses() {
	$custom_states = get_custom_statuses();

	foreach ( $custom_states as $slug => $status ) {
		register_post_status(
			$slug,
			array(
				'label'              => $status['label'],
				'label_count'        => $status['label_count'],
				'public'             => true,
				'publicly_queryable' => false,
			)
		);
	}
}

/**
 * Register meta boxes.
 */
function init_meta_boxes() {
	// Replace Core's status box with a custom one.
	remove_meta_box( 'submitdiv', POST_TYPE, 'side' );

	add_meta_box(
		'submitdiv',
		esc_html__( 'Status', 'wordcamporg' ),
		__NAMESPACE__ . '\render_status_metabox',
		POST_TYPE,
		'side',
		'high'
	);

	add_meta_box(
		'wcbsi_sponsor_invoice',
		esc_html__( 'Sponsor Invoice', 'wordcamporg' ),
		__NAMESPACE__ . '\render_sponsor_invoice_metabox',
		POST_TYPE,
		'normal',
		'high'
	);
}

/**
 * Enqueue scripts and stylesheets.
 */
function enqueue_assets() {
	wp_register_script(
		'sponsor-invoices',
		plugins_url( 'javascript/sponsor-invoices.js', __DIR__ ),
		array( 'wordcamp-budgets', 'jquery', 'underscore', 'wp-util' ),
		1,
		true
	);

	$current_screen = get_current_screen();

	if ( POST_TYPE !== $current_screen->id ) {
		return;
	}

	wp_enqueue_script( 'sponsor-invoices' );
}

/**
 * Prepare sponsor data for displaying in the UI.
 *
 * @param int $sponsor_id If passed, will return only data for that sponsor. Otherwise returns all sponsors.
 *
 * @return array
 */
function prepare_sponsor_data( $sponsor_id = null ) {
	$data = array();

	$field_names = array(
		'company_name', 'first_name', 'last_name', 'email_address', 'phone_number',
		'street_address1', 'street_address2', 'city', 'state', 'zip_code', 'country',
	);

	// These use dashes instead of underscores because the loop below converts to dashes.
	$required_fields = array(
		'company-name', 'first-name', 'last-name', 'email-address', 'phone-number',
		'street-address1', 'city', 'state', 'zip-code', 'country',
	);

	if ( is_numeric( $sponsor_id ) ) {
		$sponsors = array( get_post( $sponsor_id ) );
	} else {
		$sponsors = get_posts( array(
			'post_type'      => 'wcb_sponsor',
			'posts_per_page' => 100,
			'post_status'    => array( 'draft', 'pending', 'publish' ),
		) );
	}

	foreach ( $sponsors as $sponsor ) {
		$meta_values = get_post_custom( $sponsor->ID );

		$data[ $sponsor->ID ]                                = array( 'name' => $sponsor->post_title );
		$data[ $sponsor->ID ]['data_attributes']['edit-url'] = admin_url( sprintf( 'post.php?post=%s&action=edit', $sponsor->ID ) );

		foreach ( $field_names as $name ) {
			$meta_key = "_wcpt_sponsor_$name";
			$data_key = str_replace( '_', '-', $name ); // for consistency with JavaScript conventions.
			$value    = '';

			if ( isset( $meta_values[ $meta_key ][0] ) ) {
				 $value = $meta_values[ $meta_key ][0];
			}

			$data[ $sponsor->ID ]['data_attributes'][ $data_key ] = $value;
		}

		$complete = required_fields_complete( $data[ $sponsor->ID ]['data_attributes'], $required_fields );
		$data[ $sponsor->ID ]['data_attributes']['required-fields-complete'] = $complete ? 'true' : 'false';
	}

	return $data;
}

/**
 * Check if all of the required fields have values.
 *
 * @param array $submitted_values
 * @param array $required_fields
 *
 * @return bool
 */
function required_fields_complete( $submitted_values, $required_fields ) {
	$complete = true;

	foreach ( $submitted_values as $key => $value ) {
		if ( in_array( $key, $required_fields, true ) ) {
			if ( empty( $value ) || 'null' === substr( $value, 0, 4 ) ) {
				$complete = false;
				break;
			}
		}
	}

	return $complete;
}

/**
 * Render the Status metabox.
 *
 * @param \WP_Post $post The invoice post.
 */
function render_status_metabox( $post ) {
	require_once WP_PLUGIN_DIR . '/wcpt/wcpt-event/class-event-loader.php';
	require_once WP_PLUGIN_DIR . '/wcpt/wcpt-wordcamp/wordcamp-loader.php';

	wp_nonce_field( 'status', 'status_nonce' );

	$delete_text = EMPTY_TRASH_DAYS ? esc_html__( 'Move to Trash' ) : esc_html__( 'Delete Permanently' );
	$wordcamp    = get_wordcamp_post();

	/*
	 * We can't use current_user_can( 'edit_post', N ) in this case, because the restriction only applies when
	 * submitting the edit form, not when viewing the post. We also want to allow editing by plugins, but not
	 * always through the UI. So, instead, we simulate get the same result in a different way.
	 *
	 * Network admins can edit submitted invoices in order to correct them before they're sent to QuickBooks, but
	 * not even network admins can edit them once they've been created in QuickBooks, because then our copy of the
	 * invoice would no longer match QuickBooks.
	 *
	 * This intentionally only prevents editing through the UI; we still want plugins to be able to edit the
	 * invoice, so that the status can be updated to paid, etc.
	 */
	$allowed_edit_statuses = array( 'auto-draft', 'draft' );

	if ( current_user_can( 'manage_network' ) ) {
		$allowed_edit_statuses[] = 'wcbsi_submitted';
	}

	$allowed_submit_statuses         = WordCamp_Loader::get_after_contract_statuses();
	$current_user_can_edit_request   = in_array( $post->post_status, $allowed_edit_statuses, true );
	$current_user_can_submit_request = $wordcamp && in_array( $wordcamp->post_status, $allowed_submit_statuses, true );

	$invoice_url = '';
	if ( current_user_can( 'manage_network' ) && ! empty( $post->_wcbsi_qbo_invoice_id ) ) {
		$invoice_url = sprintf(
			'https://app%s.qbo.intuit.com/app/invoice?txnId=%s',
			'local' === get_wordcamp_environment() ? '.sandbox' : '',
			absint( $post->_wcbsi_qbo_invoice_id )
		);
	}

	require_once dirname( __DIR__ ) . '/views/sponsor-invoice/metabox-status.php';
}

/**
 * Render Sponsor Invoice Metabox.
 *
 * @param \WP_Post $post
 */
function render_sponsor_invoice_metabox( $post ) {
	wp_nonce_field( 'sponsor_invoice', 'sponsor_invoice_nonce' );

	$current_screen       = get_current_screen();
	$available_sponsors   = prepare_sponsor_data();
	$available_classes    = \WordCamp_QBO_Client::get_classes();
	$available_currencies = \WordCamp_Budgets::get_currencies();
	$selected_sponsor_id  = get_post_meta( $post->ID, '_wcbsi_sponsor_id',      true );
	$selected_class_id    = get_post_meta( $post->ID, '_wcbsi_qbo_class_id',    true );
	$selected_currency    = get_post_meta( $post->ID, '_wcbsi_currency',        true );
	$description          = get_post_meta( $post->ID, '_wcbsi_description',     true );
	$amount               = get_post_meta( $post->ID, '_wcbsi_amount',          true );

	if ( 'add' === $current_screen->action && isset( $_GET['sponsor_id'] ) ) {
		$selected_sponsor_id = absint( $_GET['sponsor_id'] );
	}

	require_once dirname( __DIR__ ) . '/views/sponsor-invoice/metabox-general.php';
}

/**
 * Print introduction text at the top of the Edit Invoice screen.
 *
 * @param \WP_Post $post
 */
function print_introduction_text( $post ) {
	if ( POST_TYPE !== $post->post_type ) {
		return;
	}

	?>

	<p>
		<?php esc_html_e(
			'Invoices typically arrive 1-2 business days after the invoice request has been reviewed and approved.',
			'wordcamporg'
		); ?>
	</p>

	<?php
}

/**
 * Display the status of a post after its title on the Sponsor Invoices page.
 *
 * @param array   $states
 * @param WP_Post $post
 *
 * @return array
 */
function display_post_states( $states, $post ) {
	$custom_states = get_custom_statuses();

	foreach ( $custom_states as $slug => $status ) {
		if ( $post->post_status === $slug && get_query_var( 'post_status' ) !== $slug ) {
			$states[ $slug ] = $status['label'];
		}
	}

	return $states;
}

/**
 * Set the status when invoices are submitted.
 *
 * @param array $post_data
 * @param array $post_data_raw
 *
 * @return array
 */
function set_invoice_status( $post_data, $post_data_raw ) {
	if ( ! \WordCamp_Budgets::post_edit_is_actionable( $post_data, POST_TYPE ) ) {
		return $post_data;
	}

	$sponsor                 = prepare_sponsor_data( $post_data_raw['_wcbsi_sponsor_id'] );
	$sponsor                 = array_pop( $sponsor );
	$sponsor_fields_complete = 'true' === $sponsor['data_attributes']['required-fields-complete'];

	$required_invoice_fields = array(
		'_wcbsi_sponsor_id', '_wcbsi_description', '_wcbsi_currency', '_wcbsi_amount',
		'_wcbsi_qbo_class_id',
	);
	$invoice_fields_complete = required_fields_complete( $post_data_raw, $required_invoice_fields );

	if ( ! $sponsor_fields_complete || ! $invoice_fields_complete ) {
		// Set to draft if any required info isn't available.
		$post_data['post_status'] = 'draft';

		// todo display message to user letting them know why this is happening.
		// todo this should run after save, b/c sanitization/validation could empty out some fields.

	} elseif (
		in_array( $post_data['post_status'], array( 'auto-draft', 'draft' ), true ) &&
		// phpcs:ignore WordPress.Security.NonceVerification -- nonce protection is granted before this hook is called.
		isset( $_POST['send-invoice'] )
	) {
		/*
		 * Only set to submitted if the previous status was a draft, because a network admin can make changes
		 * after it's been submitted, and we don't want to revert the post status in those cases.
		 */

		$post_data['post_status'] = 'wcbsi_submitted';
	}

	return $post_data;
}

/**
 * Save the extra invoice information after the post is saved.
 *
 * @param int      $post_id
 * @param \WP_Post $post
 */
function save_invoice( $post_id, $post ) {
	if ( ! \WordCamp_Budgets::post_edit_is_actionable( $post, POST_TYPE ) ) {
		return;
	}

	// Verify nonces.
	$nonces = array( 'status_nonce', 'sponsor_invoice_nonce' );

	foreach ( $nonces as $nonce ) {
		check_admin_referer( str_replace( '_nonce', '', $nonce ), $nonce );
	}

	// Sanitize and save the field values.
	$fields = array( 'sponsor_id', 'qbo_class_id', 'currency', 'description', 'amount' );

	foreach ( $fields as $field ) {
		$meta_key = "_wcbsi_$field";
		$value    = sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) );

		if ( 'amount' === $field ) {
			$value = \WordCamp_Budgets::validate_amount( $value );
		}

		if ( empty( $value ) ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	if ( 'wcbsi_approved' === $post->post_status ) {
		$invoice_sent_at = get_post_meta( $post_id, 'Sent at', true );
		if ( empty( $invoice_sent_at ) ) {
			update_post_meta( $post_id, 'Sent at', time() );
		}
	}
}

/**
 * Define columns for the Sponsor Invoices screen.
 *
 * @param array $_columns
 * @return array
 */
function get_columns( $_columns ) {
	$columns = array(
		'cb'             => $_columns['cb'],
		'author'         => esc_html__( 'Author' ),
		'title'          => $_columns['title'],
		'date'           => $_columns['date'],
		'sponsor_name'   => esc_html__( 'Sponsor',  'wordcamporg' ),
		'payment_amount' => esc_html__( 'Amount',   'wordcamporg' ),
	);

	return $columns;
}

/**
 * Render custom columns on the Sponsor Invoices screen.
 *
 * @param string $column
 * @param int    $post_id
 */
function render_columns( $column, $post_id ) {
	switch ( $column ) {
		case 'sponsor_name':
			// todo could reuse get_sponsor_name() from dashboard if made some minor modifications.

			$sponsor = get_post( get_post_meta( $post_id, '_wcbsi_sponsor_id', true ) );
			if ( is_a( $sponsor, 'WP_Post' ) ) {
				echo esc_html( $sponsor->post_title );
			}
			break;

		case 'payment_amount':
			$currency = get_post_meta( $post_id, '_wcbsi_currency', true );
			if ( $currency && false === strpos( $currency, 'null' ) ) {
				echo esc_html( $currency ) . ' ';
			}

			echo esc_html( get_post_meta( $post_id, '_wcbsi_amount', true ) );
			break;
	}
}

/**
 * Add status management links into row actions.
 *
 * @param string[] $actions An array of row action links.
 * @param WP_Post  $post    The post object.
 *
 * @return array An array of row action links.
 */
function add_row_action( $actions, $post ) {
	if ( POST_TYPE !== $post->post_type || ! current_user_can( 'manage_network' ) ) {
		return $actions;
	}

	$post_type_object = get_post_type_object( $post->post_type );

	/*
	 * A list of current status, new status pairs. If the current status is matched, the show a link to transform
	 * this invoice into the new status.
	 */
	$status_map = array(
		// Pairs in ( current, new ) format.
		array( 'wcbsi_paid', 'wcbsi_refunded' ),
		array( 'wcbsi_refunded', 'wcbsi_paid' ),
		array( 'wcbsi_approved', 'wcbsi_uncollectible' ),
		array( 'wcbsi_uncollectible', 'wcbsi_approved' ),
	);

	foreach ( $status_map as $index => list( $current_status, $new_status ) ) {
		if ( $current_status === $post->post_status ) {
			$url = add_query_arg(
				array(
					'action' => 'wcbsi_update',
					'status' => str_replace( 'wcbsi_', '', $new_status ),
				),
				admin_url( sprintf( $post_type_object->_edit_link, $post->ID ) )
			);

			$status = get_post_status_object( $new_status );
			$actions[ 'new-status-' . $index ] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				wp_nonce_url( $url, 'wcbsi_update-post_' . $post->ID ),
				esc_attr( sprintf(
					/* translators: %1$s: Post title, %2$s: New status label. */
					__( 'Mark invoice &#8220;%1$s&#8221; %2$s', 'wordcamporg' ),
					$post->post_title,
					$status->label
				) ),
				$status->label
			);
		}
	}

	return $actions;
}

/**
 * Trigger the post status change when wcbsi_update actions are seen.
 *
 * @return void
 */
function handle_status_action( $post_id ) {
	$action = sanitize_text_field( $_REQUEST['action'] ?? '' );
	$status = sanitize_text_field( $_REQUEST['status'] ?? '' );
	if ( 'wcbsi_update' !== $action ) {
		return;
	}

	check_admin_referer( 'wcbsi_update-post_' . $post_id );

	$post = get_post( $post_id );
	if ( ! is_a( $post, 'WP_Post' ) || POST_TYPE !== $post->post_type ) {
		return;
	}

	if ( ! current_user_can( 'manage_network' ) ) {
		return;
	}

	// The `$index` here is used for the status message below in `action_success_message`.
	$index = array_search( $status, array( 'uncollectible', 'refunded', 'paid', 'approved' ) );
	if ( false !== $index ) {
		// Remove filters that intercept the `wp_update_post` process.
		remove_filter( 'wp_insert_post_data', __NAMESPACE__ . '\set_invoice_status', 10 );
		remove_filter( 'save_post', __NAMESPACE__ . '\save_invoice', 10 );
		wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'wcbsi_' . $status,
		) );
		$sendback = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'wcbsi_updated', $index, $sendback ) );
		exit();
	}
}

/**
 * Output success messages when an invoice is updated.
 *
 * @return void
 */
function action_success_message() {
	if ( isset( $_GET['wcbsi_updated'] ) ) : ?>
	<div id="message" class="updated notice-success notice is-dismissible">
		<p>
			<?php
			switch ( $_GET['wcbsi_updated'] ) {
				case '0':
					esc_html_e( 'Invoice marked uncollectible.', 'wordcamporg' );
					break;
				case '1':
					esc_html_e( 'Invoice marked refunded.', 'wordcamporg' );
					break;
				case '2':
					esc_html_e( 'Invoice marked paid.', 'wordcamporg' );
					break;
				case '3':
					esc_html_e( 'Invoice marked sent.', 'wordcamporg' );
					break;
			}
			?>
		</p>
	</div>
	<?php endif;
}

/**
 * Modify the default capabilities.
 *
 * @param array  $required_capabilities The primitive capabilities that are required to perform the requested meta capability.
 * @param string $requested_capability  The requested meta capability.
 * @param int    $user_id               The user ID.
 * @param array  $args                  Adds the context to the cap. Typically the object ID.
 */
function modify_capabilities( $required_capabilities, $requested_capability, $user_id, $args ) {
	// todo maybe centralize this, since almost identical to counterpart in payment-requests.php.
	$post = \WordCamp_Budgets::get_map_meta_cap_post( $args );

	if ( is_a( $post, 'WP_Post' ) && POST_TYPE === $post->post_type ) {
		/*
		 * Only network admins can edit/delete requests once they've been submitted.
		 *
		 * The organizer can still open the request (in order to view the status and details), but won't be allowed to make any changes to it.
		 */
		if ( ! in_array( $post->post_status, array( 'auto-draft', 'draft' ), true ) ) {
			if ( 'edit_post' === $requested_capability ) {
				$is_saving_edit = isset( $_REQUEST['action'] ) && 'edit' !== $_REQUEST['action'];  // 'edit' is opening the Edit Invoice screen, 'editpost' is when it's submitted
				$is_bulk_edit   = isset( $_REQUEST['bulk_edit'] );

				if ( $is_saving_edit || $is_bulk_edit ) {
					$required_capabilities[] = 'manage_network';
				}
			}

			if ( 'delete_post' === $requested_capability ) {
				$required_capabilities[] = 'manage_network';
			}
		}
	}

	return $required_capabilities;
}
