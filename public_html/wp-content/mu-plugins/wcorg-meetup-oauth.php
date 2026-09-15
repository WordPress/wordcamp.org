<?php

if ( 'wcorg-meetup-authorize' !== ( $_GET['action'] ?? '' ) && ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) ) {
	return;
}

add_action(
	'admin_init',
	function () {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		if ( 'wcorg-meetup-authorize' === ( $_GET['action'] ?? '' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					$authorize_url = add_query_arg(
						array(
							'client_id'     => rawurlencode( MEETUP_OAUTH_CONSUMER_KEY ),
							'response_type' => 'code',
							'redirect_uri'  => rawurlencode( MEETUP_OAUTH_CONSUMER_REDIRECT_URI ),
							'state'         => wp_create_nonce( 'meetup-oauth' ),
						),
						'https://secure.meetup.com/oauth2/authorize'
					);
					printf(
						'<div class="notice notice-warning"><p><a href="%s">%s</a></p></div>',
						esc_url( $authorize_url ),
						esc_html__( 'Reconnect Meetup', 'wordcamporg' )
					);
				}
			);
			return;
		}

		if ( ! is_string( $_GET['code'] ) || ! is_string( $_GET['state'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ), 'meetup-oauth' )
		) {
			return;
		}

		// Store the new meetup oauth tokens.
		( new WordPressdotorg\MU_Plugins\Utilities\Meetup_OAuth2_Client() )->get_oauth_token();
	}
);
