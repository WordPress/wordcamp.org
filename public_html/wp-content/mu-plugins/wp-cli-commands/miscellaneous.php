<?php

use cli\progress\Bar;
use cli\Table;

defined( 'WP_CLI' ) || die();

/**
 * WordCamp.org: Miscellaneous commands.
 */
class WordCamp_CLI_Miscellaneous extends WP_CLI_Command {
	/**
	 * Sets skip-feature flags on all sites up to a given ID
	 *
	 * See wcorg_skip_feature() for context. This is useful when you new functionality is introduced, but you want
	 * to skip it on older sites.
	 *
	 * ## OPTIONS
	 *
	 * <flag_name>
	 * : The name of the flag that will be set
	 *
	 * [<max_site_id>]
	 * : The ID of the newest site that the flag will be set on. If empty,
	 * the flag will be applied to all sites.
	 *
	 * [--dry-run]
	 * : Show a report, but don't perform the changes.
	 *
	 * ## EXAMPLES
	 *
	 * wp wc-misc set-skip-feature-flag wcb_viewport_initial_scale                  # Sets the flag on all sites
	 * wp wc-misc set-skip-feature-flag wcb_viewport_initial_scale 437              # Sets the flag on all sites 1 through 437
	 * wp wc-misc set-skip-feature-flag wcb_viewport_initial_scale 437 --dry-run    # Shows a report of what would happen for sites 1 through 437
	 *
	 * @subcommand set-skip-feature-flag
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function set_skip_feature_flag( $args, $assoc_args ) {
		$flag_name   = $args[0];
		$max_site_id = empty( $args[1] ) ? false : absint( $args[1] );
		$dry_run     = isset( $assoc_args['dry-run'] );

		$site_args = array( 'number' => 0 );
		if ( $max_site_id ) {
			$site_args['site__in'] = range( 1, $max_site_id );
		}
		$sites = get_sites( $site_args );

		$notify  = new Bar( 'Applying flag', count( $sites ) );
		$results = array();

		WP_CLI::line();

		foreach ( $sites as $site ) {
			$site_domain = parse_url( $site->siteurl );
			$site_domain = sprintf( '%s%s', $site_domain['host'], $site_domain['path'] );
			$skip_flags  = get_site_meta( $site->blog_id, 'wordcamp_skip_feature' );

			if ( in_array( $flag_name, $skip_flags, true ) ) {
				$results[] = array( $site->blog_id, $site_domain, 'skipped -- already exists' );
				continue;
			}

			if ( ! $dry_run ) {
				add_site_meta( $site->blog_id, 'wordcamp_skip_feature', $flag_name );
			}

			$results[] = array( $site->blog_id, $site_domain, 'applied' );

			$notify->tick();
		}

		$notify->finish();

		WP_CLI::line();
		$table = new Table();
		$table->setHeaders( array( 'ID', 'Site', 'Action' ) );
		$table->setRows( $results );
		$table->display();

		WP_CLI::line();
		WP_CLI::success( sprintf(
			'%s has been applied to all sites%s.',
			$flag_name,
			$max_site_id ? ' up through ' . $max_site_id : ''
		) );

		if ( $dry_run ) {
			WP_CLI::warning( 'This was only a dry-run. No flags were actually applied.' );
		}
	}

	/**
	 * Get or modify the state of a skip-feature flag on a single site.
	 *
	 * See wcorg_skip_feature() for context.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : The skip-feature command to execute on a site.
	 * ---
	 * options:
	 *   - get
	 *   - set
	 *   - unset
	 * ---
	 *
	 * <flag_name>
	 * : The name of the flag to get or modify.
	 *
	 * <blog_id>
	 * : The numeric ID of the site on which the skip-feature command will be executed.
	 *
	 * ## EXAMPLES
	 *
	 * wp wc-misc skip-feature-flag get wcb_viewport_initial_scale 437
	 * wp wc-misc skip-feature-flag set wcb_viewport_initial_scale 437
	 * wp wc-misc skip-feature-flag unset wcb_viewport_initial_scale 437
	 *
	 * @subcommand skip-feature-flag
	 *
	 * @param array $args
	 *
	 * @throws \WP_CLI\ExitException
	 */
	public function skip_feature_flag( $args ) {
		$command   = $args[0] ?? '';
		$flag_name = $args[1] ?? '';
		$blog_id   = ( isset( $args[2] ) ) ? absint( $args[2] ) : 0;

		WP_CLI::line();

		$commands = array( 'get', 'set', 'unset' );

		if ( ! in_array( $command, $commands, true ) ) {
			WP_CLI::error( 'Invalid command. Use `get`, `set`, or `unset` for the first argument.' );
		}

		if ( ! $flag_name ) {
			WP_CLI::error( 'Invalid flag name.' );
		}

		$site = get_site( $blog_id );
		if ( ! $site ) {
			WP_CLI::error( 'Invalid blog ID.' );
		}

		$flags       = get_site_meta( $site->blog_id, 'wordcamp_skip_feature' );
		$site_domain = parse_url( $site->siteurl );
		$site_domain = sprintf( '%s%s', $site_domain['host'], $site_domain['path'] );

		switch ( $command ) {
			case 'get':
				if ( in_array( $flag_name, $flags, true ) ) {
					$message = sprintf(
						'The %s flag is SET on %s (%d).',
						$flag_name,
						$site_domain,
						$site->blog_id
					);
				} else {
					$message = sprintf(
						'The %s flag is NOT SET on %s (%d).',
						$flag_name,
						$site_domain,
						$site->blog_id
					);
				}
				break;
			case 'set':
				if ( in_array( $flag_name, $flags, true ) ) {
					$message = sprintf(
						'The %s flag is already SET on %s (%d).',
						$flag_name,
						$site_domain,
						$site->blog_id
					);
				} else {
					add_site_meta( $site->blog_id, 'wordcamp_skip_feature', $flag_name );
					$message = sprintf(
						'The %s flag was successfully SET for %s (%d).',
						$flag_name,
						$site_domain,
						$site->blog_id
					);
				}
				break;
			case 'unset':
				if ( in_array( $flag_name, $flags, true ) ) {
					delete_site_meta( $site->blog_id, 'wordcamp_skip_feature', $flag_name );
					$message = sprintf(
						'The %s flag was successfully UNSET for %s (%d).',
						$flag_name,
						$site_domain,
						$site->blog_id
					);
				} else {
					$message = sprintf(
						'The %s flag is already NOT SET on %s (%d).',
						$flag_name,
						$site_domain,
						$site->blog_id
					);
				}
				break;
		}

		WP_CLI::line( $message );
	}

	/**
	 * Print a log with our custom entries formatted for humans
	 *
	 * ## OPTIONS
	 *
	 * <raw_log>
	 * : The raw log contents, or the filename of the raw log
	 *
	 * [--foreign=<action>]
	 * : Include foreign log entries from the output, or ignore them
	 * ---
	 * default: include
	 * options:
	 *   - include
	 *   - ignore
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 * wp wc-misc format-log /var/log/php-errors.log
	 * wp wc-misc format-log "$(grep 'foo' /var/log/php-errors.log -C 10)" |less -S
	 * wp wc-misc format-log "$(grep 'bar' /var/log/php-errors.log)" --foreign=ignore
	 *
	 * @todo Sometimes example passing entries as command line param fails because it passes the length limit.
	 *       Add an example of a good workaround for that.
	 *
	 * @subcommand format-log
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function format_log( $args, $assoc_args ) {
		list( $raw_log ) = $args;

		if ( is_file( $raw_log ) ) {
			$raw_log = file_get_contents( $raw_log );
		}

		$formatted_log = \WordCamp\Logger\format_log( $raw_log, $assoc_args['foreign'] );

		WP_CLI::line( "\n" . $formatted_log );
	}
}
