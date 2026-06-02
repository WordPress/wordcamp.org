<?php
/**
 * Plugin Name: Camptix BD Payments
 * Description: Bangladeshi payment gateways for CampTix
 * Author: Tareq Hasan & Nazmul Hosen
 * Version: 1.3
 * License: GPL2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bd-payments-camptix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Class
 */
class CampTix_BD_Gateways {

	/**
	 * [__construct description]
	 */
	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
		add_action( 'camptix_load_addons', [ $this, 'load_addons' ] );
		add_filter( 'camptix_supported_currencies', [ $this, 'add_supported_currencies' ] );
	}

	/**
	 * Initialize the plugin
	 *
	 * @return void
	 */
	public function init_plugin() {
		$this->includes();
	}

	/**
	 * Include files
	 *
	 * @return void
	 */
	public function includes() {

		if ( ! class_exists( 'CampTix_Payment_Method' ) ) {
			return;
		}

		require_once __DIR__ . '/includes/class-phone-field.php';
		require_once __DIR__ . '/includes/gateway/class-gateway-base.php';
		require_once __DIR__ . '/includes/gateway/class-gateway-sslcommerz.php';
		require_once __DIR__ . '/includes/gateway/class-gateway-surjopay.php';
	}

	/**
	 * Load the add-ons
	 *
	 * @return void
	 */
	public function load_addons() {
		camptix_register_addon( '\CamptixBD\Gateway\SSLCommerz' );
		camptix_register_addon( '\CamptixBD\Gateway\SurjoPay' );
		camptix_register_addon( '\CamptixBD\Phone_Field' );
	}

	/**
	 * Add Bangladeshi payment currencies while the plugin is active.
	 *
	 * CampTix normally only exposes currencies for enabled payment methods. That
	 * creates a setup deadlock for BDT-only gateways, because the payment methods
	 * cannot be enabled while the current currency is USD.
	 *
	 * @param array $currencies Supported currency codes.
	 *
	 * @return array
	 */
	public function add_supported_currencies( $currencies ) {
		$currencies[] = 'BDT';

		return array_unique( $currencies );
	}
}

new CampTix_BD_Gateways();
