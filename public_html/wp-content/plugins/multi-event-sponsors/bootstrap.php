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
require_once __DIR__ . '/includes/class-mes-propagator.php';
require_once __DIR__ . '/includes/class-mes-flagships.php';

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

			foreach ( $summary['regions_skipped'] as $region_id => $region_name ) {
				WP_CLI::warning( sprintf( 'Skipped region %d (%s): a sponsor group already has that name.', $region_id, $region_name ) );
			}

			WP_CLI::log( ( $dry_run ? '[dry-run] ' : '' ) . wp_json_encode( $summary ) );
		}
	);

	WP_CLI::add_command(
		'mes propagate',
		function ( $args, $assoc_args ) {
			$mes_id  = absint( $assoc_args['sponsor'] ?? 0 );
			$dry_run = isset( $assoc_args['dry-run'] );
			$summary = MES_Propagator::run( $mes_id, $dry_run );

			foreach ( $summary['camps_skipped_no_author'] as $camp_id ) {
				WP_CLI::warning( sprintf( 'Skipped camp %d: it has no lead organizer to author the stubs. Pass --user=<login> to author them yourself.', $camp_id ) );
			}

			WP_CLI::log( ( $dry_run ? '[dry-run] ' : '' ) . wp_json_encode( $summary ) );
		}
	);

	WP_CLI::add_command(
		'mes seed-flagships',
		function ( $args, $assoc_args ) {
			$domains = empty( $assoc_args['domains'] ) ? array() : explode( ',', $assoc_args['domains'] );
			$dry_run = isset( $assoc_args['dry-run'] );
			$summary = MES_Flagships::seed( $domains, $dry_run );

			if ( $summary['group_name_taken'] ) {
				WP_CLI::error( sprintf( 'A sponsor group named "%s" already exists but was not seeded by this command. Rename it, or add the flagship camps to it by hand.', MES_Flagships::GROUP_NAME ) );
			}

			WP_CLI::log( ( $dry_run ? '[dry-run] ' : '' ) . wp_json_encode( $summary ) );
		}
	);
}
