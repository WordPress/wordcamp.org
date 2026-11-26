<?php
/**
 * Plugin Name:       Shashura Mobile Money for CampTix
 * Description:       Accept MTN and Airtel Mobile Money (Uganda) in CampTix through the Shashura gateway.
 * Version:           1.0.5
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Techdom Digital Solutions
 * Author URI:        https://techdom.io
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shashura-mobile-money-for-camptix
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;

define( 'SHASMOMO_VERSION', '1.0.5' );
define( 'SHASMOMO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHASMOMO_URL', plugin_dir_url( __FILE__ ) );

require_once SHASMOMO_DIR . 'includes/class-shashura-api.php';
require_once SHASMOMO_DIR . 'includes/class-shashura-phone.php';

/** Whether CampTix can record a webhook result without redirecting or exiting. */
function shasmomo_camptix_supported() {
	if ( ! method_exists( 'CampTix_Plugin', 'payment_result' ) ) {
		return false;
	}
	$method = new ReflectionMethod( 'CampTix_Plugin', 'payment_result' );
	return $method->getNumberOfParameters() >= 4;
}

/**
 * CampTix fires camptix_load_addons on init (priority 8). The payment method extends a
 * CampTix class, so it is only loaded from there.
 */
add_action(
	'camptix_load_addons',
	function () {
		if ( ! class_exists( 'CampTix_Payment_Method' ) || ! function_exists( 'camptix_register_addon' ) ) {
			return;
		}
		if ( ! shasmomo_camptix_supported() ) {
			return;
		}
		require_once SHASMOMO_DIR . 'includes/class-shashura-payment-method.php';
		camptix_register_addon( 'Shasmomo_Payment_Method' );
	}
);

add_action(
	'admin_notices',
	function () {
		if ( ! class_exists( 'CampTix_Plugin' ) && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Shashura Mobile Money for CampTix needs the CampTix plugin to be installed and active.', 'shashura-mobile-money-for-camptix' )
				. '</p></div>';
		} elseif ( ! shasmomo_camptix_supported() && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Shashura Mobile Money for CampTix requires CampTix with non-interactive payment results (the four-argument API in current CampTix 2.x). Upgrade CampTix before accepting Mobile Money payments; CampTix 1.x is not supported.', 'shashura-mobile-money-for-camptix' )
				. '</p></div>';
		}
	}
);
