<?php
/**
 * Plugin Name: CampTix Indonesian Payments
 * Description: Accept IDR ticket payments through Mayar's hosted checkout.
 * Version: 1.0.0
 * Author: Nazmul Hosen
 * Author URI: https://profiles.wordpress.org/nazmul111/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.3
 * Requires Plugins: camptix
 * Text Domain: camptix-indonesian-payments
 */

namespace WordCamp\CampTix\IndonesianPayments;

defined( 'ABSPATH' ) || exit;

/** Register only after CampTix has loaded its base classes. */
function register_gateway() {
	if ( ! class_exists( '\CampTix_Payment_Method' ) ) {
		return;
	}

	require_once __DIR__ . '/includes/class-payment-store.php';
	require_once __DIR__ . '/includes/class-mayar-client.php';
	require_once __DIR__ . '/includes/class-payment-method-mayar.php';
	require_once __DIR__ . '/includes/class-webhook-controller.php';
	require_once __DIR__ . '/includes/class-reconciliation.php';
	camptix_register_addon( Payment_Method_Mayar::class );
}
add_action( 'camptix_load_addons', __NAMESPACE__ . '\register_gateway' );

/** Make IDR selectable before the payment method is enabled. */
function supported_currencies( $currencies ) {
	$currencies[] = 'IDR';
	return array_unique( $currencies );
}
add_filter( 'camptix_supported_currencies', __NAMESPACE__ . '\supported_currencies' );

/** Stop scheduled work without deleting payment records. */
function deactivate() {
	wp_unschedule_hook( 'camptix_mayar_reconcile' );
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );
