<?php

if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
	return;
}

add_action(
	'admin_init',
	function () {
		if ( ! is_string( $_GET['code'] ) || ! is_string( $_GET['state'] )
			|| ! current_user_can( 'manage_network_options' )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ), 'meetup-oauth' )
		) {
			return;
		}

		// Store the new meetup oauth tokens.
		( new WordPressdotorg\MU_Plugins\Utilities\Meetup_OAuth2_Client() )->get_oauth_token();
	}
);
