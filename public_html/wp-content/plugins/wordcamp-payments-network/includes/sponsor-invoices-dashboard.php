<?php

namespace WordCamp\Budgets_Dashboard\Sponsor_Invoices;

use WP_Post;
use WordCamp\Logger;
use WordCamp_QBO, WordCamp_QBO_Client;
use WordCamp_Budgets;
use const WordCamp\Budgets\Sponsor_Invoices\POST_TYPE;

defined( 'WPINC' ) || die();

const LATEST_DATABASE_VERSION = 3;

if ( defined( 'DOING_AJAX' ) ) {
	add_action( 'wp_ajax_wcbdsi_approve_invoice', __NAMESPACE__ . '\handle_approve_invoice_request'       );
} elseif ( is_network_admin() ) {
	add_action( 'network_admin_menu', __NAMESPACE__ . '\register_submenu_page' );
	add_action( 'init',               __NAMESPACE__ . '\upgrade_database'      );
}

add_action( 'plugins_loaded',                 __NAMESPACE__ . '\schedule_cron_events'  );
add_action( 'wcbdsi_check_for_paid_invoices', __NAMESPACE__ . '\check_for_paid_invoices'       );
add_action( 'send_invoice_pending_reminder',  __NAMESPACE__ . '\send_invoice_pending_reminder' );
add_action( 'save_post',                      __NAMESPACE__ . '\update_index_row', 11, 2 ); // See note in callback about priority.
add_action( 'trashed_post',                   __NAMESPACE__ . '\delete_index_row'        );
add_action( 'delete_post',                    __NAMESPACE__ . '\delete_index_row'        );

/**
 * Schedule cron jobs.
 *
 * @return void
 */
function schedule_cron_events() {
	if ( ! wp_next_scheduled( 'wcbdsi_check_for_paid_invoices' ) ) {
		wp_schedule_event(
			time(),
			'hourly',
			'wcbdsi_check_for_paid_invoices'
		);
	}

	if ( ! wp_next_scheduled( 'send_invoice_pending_reminder' ) ) {
		wp_schedule_event(
			time(),
			'daily',
			'send_invoice_pending_reminder'
		);
	}
}

/**
 * Register the admin page
 */
function register_submenu_page() {
	$hook_suffix = add_submenu_page(
		'wordcamp-budgets-dashboard',
		'WordCamp Sponsor Invoices',
		'Sponsor Invoices',
		'manage_network',
		'sponsor-invoices-dashboard',
		__NAMESPACE__ . '\render_submenu_page'
	);

	add_action( "admin_print_scripts-$hook_suffix", __NAMESPACE__ . '\enqueue_scripts' );
}

/**
 * Render the admin page
 */
function render_submenu_page() {
	require_once __DIR__ . '/sponsor-invoices-list-table.php';

	$list_table = new Sponsor_Invoices_List_Table();
	$sections   = get_submenu_page_sections();

	$list_table->prepare_items();

	switch ( get_current_section() ) {
		case 'submitted':
			$section_explanation = 'These invoices have been completed by the organizer, but need to be approved by a deputy before being sent to the sponsor.';
			break;

		case 'approved':
			$section_explanation = "These invoices have been approved by a deputy and sent to the sponsor, but haven't been paid yet.";
			break;

		case 'paid':
			$section_explanation = 'These invoices have been paid by the sponsor.';
			break;

		case 'uncollectible':
			$section_explanation = 'These invoices have been marked as uncollectible. They were not paid, and we don\'t expect payment.';
			break;

		case 'refunded':
			$section_explanation = 'These invoices have been refunded.';
			break;
	}

	require_once dirname( __DIR__ ) . '/views/sponsor-invoices/page-sponsor-invoices.php';
}

/**
 * Get the current section
 *
 * @return string
 */
function get_current_section() {
	$sections        = array( 'submitted', 'approved', 'paid', 'uncollectible', 'refunded' );
	$current_section = 'submitted';

	if ( isset( $_GET['section'] ) && in_array( $_GET['section'], $sections, true ) ) {
		$current_section = $_GET['section'];
	}

	return $current_section;
}

/**
 * Get all the data needed to render the section tabs
 *
 * @return array
 */
function get_submenu_page_sections() {
	$statuses        = \WordCamp\Budgets\Sponsor_Invoices\get_custom_statuses();
	$sections        = array();
	$current_section = get_current_section();

	foreach ( $statuses as $slug => $status ) {
		$slug = str_replace( 'wcbsi_', '', $slug );    // make the URL easier to read.

		$classes = 'nav-tab';
		if ( $slug === $current_section ) {
			$classes .= ' nav-tab-active';
		}

		$href = add_query_arg(
			array(
				'page'    => 'sponsor-invoices-dashboard',
				'section' => $slug,
			),
			network_admin_url( 'admin.php' )
		);

		$sections[ $slug ] = array(
			'classes' => $classes,
			'href'    => $href,
			'text'    => $status['label'],
		);
	}

	return $sections;
}

/**
 * Enqueue JavaScript and CSS files
 */
function enqueue_scripts() {
	wp_enqueue_script(
		'wcbd-sponsor-invoices',
		plugins_url( 'javascript/sponsor-invoices.js', __DIR__ ),
		array( 'jquery', 'underscore' ),
		1,
		true
	);
}

/**
 * Create or update the database tables
 */
function upgrade_database() {
	global $wpdb;

	$current_database_version = get_site_option( 'wcbdsi_database_version', 0 );

	if ( version_compare( $current_database_version, LATEST_DATABASE_VERSION, '>=' ) ) {
		return;
	}

	$table_name = get_index_table_name();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$schema = "
		CREATE TABLE $table_name (
			blog_id        int( 11 )        unsigned NOT NULL default '0',
			invoice_id     int( 11 )        unsigned NOT NULL default '0',
			qbo_invoice_id int( 11 )        unsigned NOT NULL default '0',
			invoice_title  varchar( 75 )             NOT NULL default '',
			status         varchar( 30 )             NOT NULL default '',
			wordcamp_name  varchar( 75 )             NOT NULL default '',
			sponsor_name   varchar( 75 )             NOT NULL default '',
			description    varchar( 75 )             NOT NULL default '',
			currency       varchar( 3  )             NOT NULL default '',
			due_date       int( 11 )        unsigned NOT NULL default '0',
			amount         numeric( 10, 2 ) unsigned NOT NULL default '0',

			PRIMARY KEY (blog_id, invoice_id),
			KEY status (status)
		)
		DEFAULT CHARACTER SET {$wpdb->charset}
		COLLATE {$wpdb->collate};
	";

	dbDelta( $schema );

	update_site_option( 'wcbdsi_database_version', LATEST_DATABASE_VERSION );
}

/**
 * Returns the name of the custom table.
 */
function get_index_table_name() {
	global $wpdb;

	return $wpdb->get_blog_prefix( 0 ) . 'wcbd_sponsor_invoice_index';
}

/**
 * Handle an AJAX request to approve an invoice.
 *
 * This is executed from the network admin, so still needs to switch to blog in order to process the invoice.
 *
 * @return void
 */
function handle_approve_invoice_request() {
	$required_parameters = array( 'action', 'nonce', 'site_id', 'invoice_id' );

	foreach ( $required_parameters as $parameter ) {
		if ( empty( $_REQUEST[ $parameter ] ) ) {
			wp_send_json_error( array( 'error' => 'Required parameters not set.' ) );
		}
	}

	$site_id    = absint( $_REQUEST['site_id'] );
	$invoice_id = absint( $_REQUEST['invoice_id'] );

	if ( ! wp_verify_nonce( $_REQUEST['nonce'], "wcbdsi-approve-invoice-$site_id-$invoice_id" ) || ! current_user_can( 'manage_network' ) ) {
		wp_send_json_error( array( 'error' => 'Permission denied.' ) );
	}

	switch_to_blog( $site_id );

	$quickbooks_result = WordCamp_QBO::create_invoice( $invoice_id );
	Logger\log( 'create_invoice', compact( 'invoice_id', 'quickbooks_result' ) );

	if ( is_int( $quickbooks_result ) ) {
		update_post_meta( $invoice_id, '_wcbsi_qbo_invoice_id', absint( $quickbooks_result ) );
		update_invoice_status( $invoice_id, 'approved' );
		notify_organizer_status_changed( $invoice_id, 'approved' );

		$result = array( 'success' => 'The invoice has been approved and e-mailed to the sponsor.' );
	} else {
		$result = array( 'error' => $quickbooks_result->get_error_message() );
	}

	restore_current_blog();

	if ( isset( $result['success'] ) ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result );
	}
}

/**
 * Send a request to QuickBooks to check if any sent invoices have been paid
 *
 * If any have been, update the status of the local copy, and notify the organizer who sent the invoice.
 *
 * This runs as a cron job on every site, so it only needs to look for invoices that are from the current site.
 *
 * @return void
 */
function check_for_paid_invoices() {
	$sent_invoices = get_posts( array(
		'post_type'      => POST_TYPE,
		'post_status'    => 'wcbsi_approved',
		'posts_per_page' => 99,
	) );

	// Batch requests in order to avoid request size limits imposed by QuickBooks, nginx, etc.
	$qbo_invoice_ids = wp_list_pluck( $sent_invoices, '_wcbsi_qbo_invoice_id' );
	$qbo_invoice_ids = array_chunk( $qbo_invoice_ids, 20 );

	$paid_invoices = array();

	foreach ( $qbo_invoice_ids as $batch ) {
		$paid_invoices = array_merge(
			$paid_invoices,
			WordCamp_QBO_Client::get_paid_invoices( $batch )
		);

		usleep( 300000 ); // Avoid hitting the API too frequently.
	}

	if ( ! empty( $paid_invoices ) ) {
		mark_invoices_as_paid( $sent_invoices, $paid_invoices );
	}
}

/**
 * Mark WordCamp.org invoices as paid when they've been paid in QuickBooks
 *
 * @param WP_Post[] $sent_invoices
 * @param array     $paid_invoices
 */
function mark_invoices_as_paid( $sent_invoices, $paid_invoices ) {
	foreach ( $sent_invoices as $invoice ) {
		if ( in_array( (int) $invoice->_wcbsi_qbo_invoice_id, $paid_invoices, true ) ) {
			update_invoice_status( $invoice->ID, 'paid' );
			notify_organizer_status_changed( $invoice->ID, 'paid' );
		}
	}
}

/**
 * Update the status of an invoice
 *
 * @param int    $invoice_id
 * @param string $new_status
 *
 * @return void
 */
function update_invoice_status( $invoice_id, $new_status ) {
	if ( POST_TYPE !== get_post_type( $invoice_id ) ) {
		return;
	}

	// Disable the functions that run during a normal save, because they'd interfere with this.
	remove_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Sponsor_Invoices\set_invoice_status', 10 );
	remove_action( 'save_post',           'WordCamp\Budgets\Sponsor_Invoices\save_invoice',       10 );

	wp_update_post(
		array(
			'ID'          => $invoice_id,
			'post_status' => "wcbsi_$new_status",
		),
		true
	);
}

/**
 * Notify the organizer when the status of their invoice changes
 *
 * @param int    $invoice_id
 * @param string $new_status
 *
 * @return void
 */
function notify_organizer_status_changed( $invoice_id, $new_status ) {
	if ( POST_TYPE !== get_post_type( $invoice_id ) ) {
		return;
	}

	$invoice            = get_post( $invoice_id );
	$to                 = WordCamp_Budgets::get_requester_formatted_email( $invoice->post_author );
	$subject            = "Invoice for {$invoice->post_title} $new_status";
	$sponsor_name       = get_sponsor_name( $invoice_id );
	$invoice_url        = admin_url( sprintf( 'post.php?post=%s&action=edit', $invoice_id ) );
	$headers            = array( 'Reply-To: support@wordcamp.org' );
	$attachments        = array();
	$attachment_message = '';
	$invoice_filename   = false;

	if ( 'approved' === $new_status ) {
		$sponsor_id       = get_post_meta( $invoice_id, '_wcbsi_sponsor_id',           true );
		$sponsor_email    = get_post_meta( $sponsor_id, '_wcpt_sponsor_email_address', true );
		$qbo_invoice_id   = get_post_meta( $invoice_id, '_wcbsi_qbo_invoice_id',       true );
		$status_message   = "has been sent to $sponsor_name via $sponsor_email. You will receive another notification when they have paid the invoice.";
		$invoice_filename = WordCamp_QBO::download_invoice_pdf( $qbo_invoice_id );

		Logger\log( 'get_invoice_filename', compact( 'qbo_invoice_id', 'invoice_filename' ) );

		if ( ! is_wp_error( $invoice_filename ) ) {
			$attachments[]      = $invoice_filename;
			$attachment_message = "\nA copy of the invoice has been attached to this message, in case you need to follow up with the sponsor.";
		}
	} elseif ( 'paid' === $new_status ) {
		$status_message = "has been paid by $sponsor_name. Go ahead and publish them to your website!";
	} else {
		return;
	}

	$message = str_replace(
		"\t",
		'',
		"
		The invoice for `{$invoice->post_title}` $status_message

		You can view the invoice and its status any time at:

		$invoice_url
		$attachment_message

		If you have any questions, please reply to let us know."
	);

	wp_mail( $to, $subject, $message, $headers, $attachments );

	if ( $invoice_filename ) {
		unlink( $invoice_filename );
	}
}

/**
 * Get the name of the sponsor for a given invoice
 *
 * NOTE: This must be called from inside a switch_to_blog() context.
 *
 * @param int $invoice_id
 *
 * @return string
 */
function get_sponsor_name( $invoice_id ) {
	$sponsor_name = '';
	$sponsor_id   = get_post_meta( $invoice_id, '_wcbsi_sponsor_id',  true );
	$sponsor      = get_post( $sponsor_id );

	if ( is_a( $sponsor, 'WP_Post' ) ) {
		$sponsor_name = $sponsor->post_title;
	}

	return $sponsor_name;
}

/**
 * Add or update a row in the index
 *
 * NOTE: This must run after \WordCamp\Budgets\Sponsor_Invoices\save_invoice(), because otherwise the
 * get_post_meta() calls would be fetching the old data, rather than the latest from the current process.
 *
 * @param int      $invoice_id
 * @param \WP_Post $invoice
 */
function update_index_row( $invoice_id, $invoice ) {
	global $wpdb;

	if ( POST_TYPE !== $invoice->post_type ) {
		return;
	}

	// Drafts, etc aren't displayed in the list table, so there's no reason to index them.
	$ignored_statuses = array( 'auto-draft', 'draft', 'trash' );

	if ( in_array( $invoice->post_status, $ignored_statuses, true ) ) {
		return;
	}

	// todo use post_edit_is_actionable instead?

	$index_row = array(
		'blog_id'        => get_current_blog_id(),
		'invoice_id'     => $invoice_id,
		'qbo_invoice_id' => get_post_meta( $invoice_id, '_wcbsi_qbo_invoice_id', true ),
		'invoice_title'  => substr( $invoice->post_title, 0, 75 ),
		'status'         => $invoice->post_status,
		'wordcamp_name'  => get_wordcamp_name(),
		'sponsor_name'   => substr( get_sponsor_name( $invoice_id ), 0, 75 ),
		'description'    => get_post_meta( $invoice_id, '_wcbsi_description', true ),
		'currency'       => get_post_meta( $invoice_id, '_wcbsi_currency',    true ),
		'due_date'       => 0,  // todo remove this field from index.
		'amount'         => get_post_meta( $invoice_id, '_wcbsi_amount',      true ),
	);

	$formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f' );

	$wpdb->replace( get_index_table_name(), $index_row, $formats );
}

/**
 * Delete a row from the index
 *
 * @param int $invoice_id
 */
function delete_index_row( $invoice_id ) {
	global $wpdb;

	/*
	 * Normally we would check if $invoice_id is from the kind of post type we want, but that's not necessary in
	 * this case, because only invoices are added to the index to begin with. If $invoice_id is from some other
	 * post type, then $wpdb->delete() will return false with no negative consequences. That's quicker than having
	 * to query for the $invoice post in order to check the post type.
	 */

	$wpdb->delete(
		get_index_table_name(),
		array(
			'blog_id'    => get_current_blog_id(),
			'invoice_id' => $invoice_id,
		)
	);
}

/**
 * Send reminder to organizer about the unpaid invoice.
 *
 * This runs as a cron job on every site, so it only needs to look for invoices that are from the current site.
 * (Previously it pulled all pending invoices from the index table, which caused weird issues like the email about
 * an invoice in Chicago coming from a WordCamp site in Germany, translated in `de_DE`.)
 *
 * @return void
 */
function send_invoice_pending_reminder() {
	$sent_invoices = get_posts( array(
		'post_type'      => POST_TYPE,
		'post_status'    => 'wcbsi_approved',
		'posts_per_page' => 99,
	) );

	foreach ( $sent_invoices as $invoice ) {
		$invoice_id = $invoice->ID;

		$invoice_sent_at = get_post_meta( $invoice_id, 'Sent at', true );

		if ( empty( $invoice_sent_at ) ) {
			// Backfill for older invoices.
			update_post_meta( $invoice_id, 'Sent at', time() );
			update_post_meta( $invoice_id, 'Backfilled Sent at', true );
			continue;
		}

		$last_reminder       = get_post_meta( $invoice_id, 'last_reminder_details', true );
		$invoice_defaulted   = get_post_meta( $invoice_id, 'invoice_defaulted', true );
		$reminder_step       = 1;
		$last_step_time      = $invoice_sent_at;
		$wordcamp_post       = get_wordcamp_post();
		$wordcamp_start_date = $wordcamp_post->meta['Start Date (YYYY-mm-dd)'][0] ?? false;
		$wordcamp_lead_email = $wordcamp_post->meta['Email Address'][0]           ?? false;

		if ( empty( $wordcamp_post ) || ! $wordcamp_lead_email ) {
			// Maybe this is a central.wordcamp.org test sponsor invoice.
			continue;
		}

		if ( ! empty( $invoice_defaulted ) ) {
			continue;
		}

		if ( ! empty( $last_reminder ) ) {
			$reminder_step  = $last_reminder['step'] + 1;
			$last_step_time = $last_reminder['sent_at'];
		}

		// We will send reminders after 30, 45, and 60 days.
		$reminder_schedule = array(
			1 => 30,
			2 => 15,
			3 => 15,
		);

		if ( $reminder_step > count( $reminder_schedule ) || ( $wordcamp_start_date && time() > ( (int) $wordcamp_start_date + 2 * MONTH_IN_SECONDS ) ) ) {
			send_invoice_defaulted_notification( $invoice_id );
			update_post_meta( $invoice_id, 'invoice_defaulted', true );
			continue;
		}

		$next_reminder_in = $last_step_time + $reminder_schedule[ $reminder_step ] * DAY_IN_SECONDS;

		if ( time() < (int) $next_reminder_in ) {
			continue;
		}

		$current_reminder_details = array(
			'sent_at' => time(),
			'step'    => $reminder_step,
		);

		send_invoice_pending_reminder_mail( $invoice_id, $wordcamp_lead_email );

		update_post_meta( $invoice_id, 'last_reminder_details', $current_reminder_details );
	}
}

/**
 * Send mail to organizer about a pending sponsor invoice.
 *
 * @param int    $invoice_id
 * @param string $organizer_mail
 */
function send_invoice_pending_reminder_mail( $invoice_id, $organizer_mail ) {
	if ( POST_TYPE !== get_post_type( $invoice_id ) ) {
		return;
	}

	$invoice   = get_post( $invoice_id );
	$edit_link = get_site_url() . "/wp-admin/post.php?post=$invoice_id&action=edit";

	$reminder_body = str_replace(
		"\t",
		'',
		sprintf(
			__(
				'Howdy organizers,
				<br>
				It looks like the invoice <a href="%1$s">%2$s</a> is still unpaid. If you still expect the sponsor to pay this invoice, please contact them to find out when we should expect payment. If this invoice needs to be cancelled, please email support@wordcamp.org.
				<br>
				Thanks for all your hard work on WordCamp.',
				'wordcamporg'
			),
			$edit_link,
			$invoice->post_title
		)
	);

	$author = get_user_by( 'ID', $invoice->post_author );

	wp_mail(
		array( $author->user_email ),
		sprintf( __( 'Pending invoice: %s', 'wordcamporg' ), $invoice->post_title ),
		$reminder_body,
		array(
			'From: WordCamp Central <support@wordcamp.org>',
			'Content-Type: text/html; charset=UTF-8',
			'Sender: wordpress@' . strtolower( $_SERVER['SERVER_NAME'] ),
			sprintf( 'CC: %s', $organizer_mail ), // CC to lead organizer in case post author is not active anymore.
		)
	);
}

/**
 * Send email to support@wordcamp.org about Sponsor invoice that has been pending for too long.
 *
 * @param int $invoice_id
 */
function send_invoice_defaulted_notification( $invoice_id ) {
	if ( POST_TYPE !== get_post_type( $invoice_id ) ) {
		return;
	}

	$edit_link = get_site_url() . "/wp-admin/post.php?post=$invoice_id&action=edit";

	$notification_body = str_replace(
		"\t",
		'',
		"A Sponsor Invoice has been in pending state for too long now. Please check to see if we want to cancel this. No further reminders will be sent to the organizers.
		<br>
		Invoice link: $edit_link
		<br>"
	);

	wp_mail(
		array( 'support@wordcamp.org' ),
		'Sponsor Invoice pending for too long',
		$notification_body,
		array(
			'From: WordCamp Central <support@wordcamp.org>',
			'Content-Type: text/html; charset=UTF-8',
			'Sender: wordpress@' . strtolower( $_SERVER['SERVER_NAME'] ),
		)
	);
}
