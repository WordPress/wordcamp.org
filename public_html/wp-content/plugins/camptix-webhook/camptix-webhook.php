<?php
/**
 * Plugin Name: CampTix - Webhook
 * Description: An addon for CampTix that allows 3rd party integration via webhook.
 * Version: 1.0
 * Author: Ivan Kristianto
 * Author URI: https://profiles.wordpress.org/ivankristianto/
 */

 namespace CampTix\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BASE_DIR = __DIR__;
const BASE_FILE = __FILE__;

require_once( __DIR__ . '/addons/webhook.php' );
require_once __DIR__ . '/inc/integration.php';

/**
 * Bootstrap the plugin.
 * @return void
 */
function bootstrap() {
	add_action( 'camptix_load_addons', __NAMESPACE__ . '\\camptix_webhook_register' );

	Integration\bootstrap();
}

/**
 * Register the webhook addon.
 *
 * @return void
 */
function camptix_webhook_register() {
	Addon\CampTix_Webhook::register_addon();
}

// Bootstrap the plugin.
bootstrap();