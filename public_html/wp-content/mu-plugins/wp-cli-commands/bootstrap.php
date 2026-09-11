<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/dangling-hosts.php';
require_once __DIR__ . '/miscellaneous.php';
require_once __DIR__ . '/rest-api.php';
require_once __DIR__ . '/rewrite-rules.php';
require_once __DIR__ . '/users.php';

// A one-time migration; remove this line and the file once it has been run across the network.
require_once __DIR__ . '/backfill-sponsor-agreements.php';

WP_CLI::add_command( 'wc-dangling', 'WordCamp_CLI_Dangling_Hosts' );
WP_CLI::add_command( 'wc-misc',     'WordCamp_CLI_Miscellaneous'  );
WP_CLI::add_command( 'wc-rewrite',  'WordCamp_CLI_Rewrite_Rules'  );
WP_CLI::add_command( 'wc-rest',     'WordCamp_CLI_REST_API'       );
WP_CLI::add_command( 'wc-users',    'WordCamp_CLI_Users'          );
