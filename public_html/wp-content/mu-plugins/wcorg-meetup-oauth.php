<?php

if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) || 'meetup-oauth' !== $_GET['state'] ) {
	return;
}

add_action(
	'admin_init',
	function () {
		// Store the new meetup oauth tokens.
		( new WordCamp\Utilities\Meetup_OAuth2_Client() )->get_oauth_token();
	}
);
