<?php
/*
Plugin Name: Multi-Event Sponsors
Description: Store and display data on Multi-Event Sponsors
Version:     0.1
Author:      WordCamp Central
Author URI:  http://wordcamp.org
*/

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

require_once( __DIR__ . '/classes/multi-event-sponsors.php' );
require_once( __DIR__ . '/classes/mes-region.php' );
require_once( __DIR__ . '/classes/mes-sponsor.php' );
require_once __DIR__ . '/classes/mes-sponsor-group.php';
require_once( __DIR__ . '/classes/mes-sponsorship-level.php' );
require_once( __DIR__ . '/classes/mes-privacy.php' );
require_once __DIR__ . '/includes/class-mes-migrate-groups.php';

$GLOBALS['multi_event_sponsors']  = new Multi_Event_Sponsors();
$GLOBALS['mes_sponsor']           = new MES_Region();
$GLOBALS['mes_sponsor']           = new MES_Sponsor();
$GLOBALS['mes_sponsor_group']     = new MES_Sponsor_Group();
$GLOBALS['mes_sponsorship_level'] = new MES_Sponsorship_Level();
$GLOBALS['mes_privacy']           = new MES_Privacy();

register_activation_hook( __FILE__, array( $GLOBALS['multi_event_sponsors'], 'activate' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'mes migrate-groups',
		function ( $args, $assoc_args ) {
			$dry_run = isset( $assoc_args['dry-run'] );
			$summary = MES_Migrate_Groups::run( $dry_run );

			WP_CLI::log( ( $dry_run ? '[dry-run] ' : '' ) . wp_json_encode( $summary ) );
		}
	);
}
