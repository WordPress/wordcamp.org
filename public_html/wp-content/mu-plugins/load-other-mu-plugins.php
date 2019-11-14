<?php

defined( 'WPINC' ) || die();

wcorg_include_individual_mu_plugins();
wcorg_include_mu_plugin_folders();
enable_pwa_alpha_test();    // Do _not_ comment out this line to disable this, see instructions in docblock instead.

/**
 * Load individually-targeted files
 *
 * This is because the folder contains some .php files that we don't want to automatically include with glob().
 */
function wcorg_include_individual_mu_plugins() {
	$shortcodes = dirname( __DIR__ ) . '/mu-plugins-private/wordcamp-shortcodes/wc-shortcodes.php';

	require_once( __DIR__ . '/wp-cli-commands/bootstrap.php' );
	require_once( __DIR__ . '/camptix-tweaks/camptix-tweaks.php' );
	require_once( __DIR__ . '/blocks/blocks.php' );
	require_once __DIR__ . '/quickbooks/quickbooks.php';

	if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	} else {
		wp_die( 'Required files are missing. Please run <code>composer install</code> and try again.' );
	}

	if ( is_file( $shortcodes ) ) {
		require_once( $shortcodes );
	}
}

/**
 * Load every mu-plugin in these folders
 */
function wcorg_include_mu_plugin_folders() {
	$include_folders = array(
		dirname( __DIR__ ) . '/mu-plugins-private',
		__DIR__ . '/jetpack-tweaks',
	);

	foreach ( $include_folders as $folder ) {
		$plugins = glob( $folder . '/*.php' );

		if ( is_array( $plugins ) ) {
			foreach ( $plugins as $plugin ) {
				if ( is_file( $plugin ) ) {
					require_once( $plugin );
				}
			}
		}
	}
}

/**
 * Enable the day-of-event and PWA features on alpha test sites.
 *
 * If something goes seriously wrong during WCEU2019, the following should disable the features and restore
 * stability:
 *
 * 1) Comment out the `2019.europe` ID inside `$pwa_test_sites`.
 * 2) Uncomment the `2019.europe` ID in the `rest_pre_dispatch` callback below.
 * 3) Deploy the above changes.
 * 4) Deactivate the `pwa` plugin on `2019.europe`.
 * 5) Visit https://2019.europe.wordcamp.org/wp-admin/options-reading.php and set the homepage to "Your latest posts".
 *
 * If that doesn't work, ask Systems for details about what's causing problems, and work with them to resolve it.
 * They may need to block REST API requests to `2019.europe` at the network level. Also, call Ian's cell phone until
 * he picks up :)
 *
 * todo When enabling this for all sites, most of this function can be removed, and the `require_once()` statements
 * can be integrated into `wcorg_include_individual_mu_plugins()`, and then this function can be removed.
 */
function enable_pwa_alpha_test() {
	/*
	 * These are experimental features that were rushed to activate before the WCEU 2019 deadline, and are _not_
	 * ready for production beyond this limited alpha test. There are many UX and code issues that need to be
	 * resolved before any other sites are added to this list, including WCEU 2020.
	 *
	 * See https://github.com/wceu/wordcamp-pwa-page/issues/6, the other open issues in that repository, and the
	 * `todo` notes in all of these files.
	 *
	 * When adding more sites here, you'll need to activate the `pwa` plugin for that site first.
	 *
	 * WARNING: Do _not_ add more sites, or network-enable this, until the issues mentioned above are resolved.
	 */
	$pwa_test_sites = array(
		928,  // 2017.testing
		1026, // 2019.europe
		1190, // 2019.dublin
		1028, // 2019.us
		1134, // 2019.stuttgart
	);

	$is_remote_sandbox = defined( 'WPORG_SANDBOXED' ) && WPORG_SANDBOXED;
	$is_local_sandbox  = 'development' === WORDCAMP_ENVIRONMENT && ! $is_remote_sandbox;
	$is_test_site      = in_array( get_current_blog_id(), $pwa_test_sites, true );

	if ( $is_local_sandbox || $is_test_site ) {
		require_once( __DIR__ . '/service-worker-caching.php' );
		require_once( __DIR__ . '/theme-templates/bootstrap.php' );
	}

	/**
	 * Disable the REST API if the server is overloaded.
	 *
	 * This is a kill switch for the REST API, because even after disabling the `require()` calls above and the
	 * `pwa` plugin, browsers will still be making requests to the API. This will short-circuit those, so that
	 * the server isn't overloaded.
	 */
	add_action(
		'rest_pre_dispatch',
		function( $result, $server, $request ) {
			$overloaded_sites = array(
				//1026, // 2019.europe
			);

			if ( in_array( get_current_blog_id(), $overloaded_sites, true ) ) {
				$result = new WP_Error(
					'api_temporarily_disabled',
					'The REST API has been temporarily disabled on this site because of unexpected stability problems',
					array( 'status' => 503 )
				);
			}

			return $result;
		},
		10,
		3
	);
}
