<?php
/**
 * Plugin Name: Camptix BD Payments
 * Description: Bangladeshi payment gateways for CampTix
 * Plugin URI: https://github.com/tareq1988/camptix-bd-payments
 * Author: Tareq Hasan
 * Author URI: https://tareq.co
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
		add_filter( 'camptix_currencies', [ $this, 'add_currency' ] );
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
		require_once __DIR__ . '/includes/gateway/class-gateway-sslcommerz.php';
	}

	/**
	 * Load the add-ons
	 *
	 * @return void
	 */
	public function load_addons() {
		camptix_register_addon( '\CamptixBD\Gateway\SSLCommerz' );
		camptix_register_addon( '\CamptixBD\Phone_Field' );
	}

	/**
	 * Add BDT currency
	 *
	 * @param array $currencies
	 *
	 * @return array
	 */
	public function add_currency( $currencies ) {
		$currencies['BDT'] = [
			'label'         => __( 'Taka', 'bd-payments-camptix' ),
			'format'        => 'BDT %s',
			'decimal_point' => 2,
		];

		return $currencies;
	}
}

new CampTix_BD_Gateways();
