<?php

defined( 'WP_CLI' ) or die();

use function WP_CLI\Utils\make_progress_bar;

/**
 * WordCamp.org: Manage rewrite rules.
 */
class WordCamp_CLI_Rewrite_Rules extends WP_CLI_Command {
	/**
	 * Flush rewrite rules on all sites.
	 *
	 * Periodically they break for various reasons and need to be reset on all sites.
	 */
	public function flush() {
		$sites = get_sites( array(
			'number'  => 10000,
			'public'  => 1,
			'deleted' => 0,
		) );

		WP_CLI::line();

		$notify = make_progress_bar( 'Flushing sites...', count( $sites ) );

		foreach ( $sites as $site ) {
			/*
			 * We can't call `flush_rewrite_rules()` inside a `switch_to_blog()` loop, because plugins, etc
			 * wouldn't be loaded, and the rewrite rules wouldn't be correct.
			 *
			 * If we delete them, then they'll get reset the next time that site receives a request.
			 */
			switch_to_blog( $site->id );
			delete_option( 'rewrite_rules' );
			restore_current_blog();

			$notify->tick();
		}

		$notify->finish();

		WP_CLI::success( 'Done.' );
	}
}
