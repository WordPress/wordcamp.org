<?php

// @todo Delete this file after Jetpack 7.6 is installed.

use Automattic\Jetpack\Connection\XMLRPC_Connector as XMLRPC_Connector;

/*
 * Plugin Name: Fix Jetpack Registration
 * Author:      mdawaffe
 * Description: Temp fix for https://github.com/Automattic/jetpack/issues/13136. Will automatically deactivate for Jetpack 7.6+.
 *
 * Note: This has been adapted for use as an mu-plugin
 */

function mdawaffe_fix_jetpack_registration() {
	if ( ! defined( 'JETPACK__VERSION' ) ) {
		return;
	}

	if ( version_compare( '7.6', JETPACK__VERSION, '<=' ) ) {
		//add_action( 'admin_init', 'mdawaffe_fix_jetpack_registration_deactivate_me' );

		return;
	}

	if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST || ! isset( $_GET['for'] ) || 'jetpack' != $_GET['for'] ) {
		return;
	}

	if ( Jetpack::is_active() ) {
		return;
	}

	new XMLRPC_Connector( Jetpack::connection() );
}

//function mdawaffe_fix_jetpack_registration_deactivate_me() {
//	deactivate_plugins( plugin_basename( __FILE__ ) );
//}

add_action( 'init', 'mdawaffe_fix_jetpack_registration', 20 );
