<?php
/*
Plugin Name: WordCamp Budgets
Plugin URI:  http://wordcamp.org/
Description: Provides tools for managing WordCamp budgets, sponsor invoices, vendor payments, and reimbursement requests.
Author:      WordCamp.org
Author URI:  https://wordcamp.org
Version:     0.1
*/

define( 'WORDCAMP_PAYMENTS_PATH', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

if ( is_admin() ) {
	require_once __DIR__ . '/includes/wordcamp-budgets.php';
	require_once __DIR__ . '/includes/payment-request.php';
	require_once __DIR__ . '/includes/reimbursement-request.php';
	require_once __DIR__ . '/includes/encryption.php';
	require_once __DIR__ . '/includes/budget-tool.php';

	$GLOBALS['wordcamp_budgets']    = new WordCamp_Budgets();
	$GLOBALS['wcp_payment_request'] = new WCP_Payment_Request();
}

if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
	require_once __DIR__ . '/includes/sponsor-invoice.php';
}

/*
 * Attachments on budget requests hold bank details and invoices, and `privacy.php` is what keeps them scoped to
 * the organizer they belong to. It has to load on every request, not just the admin ones -- the REST media
 * endpoints and XML-RPC both list attachments from outside `wp-admin`.
 *
 * It deliberately doesn't depend on the files above -- see `get_budget_request_post_types()`.
 */
require_once __DIR__ . '/includes/privacy.php';
