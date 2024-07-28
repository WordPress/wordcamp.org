<?php
/**
 * Plugin Name: CampTix - Webhook
 * Description: An addon for CampTix that allows 3rd party integration via webhook.
 * Version: 0.1
 * Author: Ivan Kristianto
 * Author URI: https://profiles.wordpress.org/ivankristianto/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function camptix_webhook_register() {
	require_once( plugin_dir_path( __FILE__ ) . 'addons/webhook.php' );
}
add_action( 'camptix_load_addons', 'camptix_webhook_register' );
