<?php

defined( 'WPINC' ) || die();

wcorg_include_common_plugins();
wcorg_include_network_only_plugins();


/**
 * Load mu-plugins that should run on all networks.
 */
function wcorg_include_common_plugins() {
	if ( 'local' !== WORDCAMP_ENVIRONMENT ) {
		require_once dirname( __DIR__ ) . '/mu-plugins-private/wporg-mu-plugins.php';
		require_once dirname( __DIR__ ) . '/mu-plugins-private/wporg-sso.php';
	}
}

/**
 * Include mu-plugins that should only run on a specific network.
 */
function wcorg_include_network_only_plugins() {
	if ( EVENTS_NETWORK_ID === SITE_ID_CURRENT_SITE ) {
		$network_folder = 'events';
	} else {
		$network_folder = 'wordcamp';

		// @todo temporary. some of this should be moved into into wcorg_include_common_plugins(), but it all needs to
		// be audited and updated for the Events network first.
		// see https://github.com/WordPress/wordcamp.org/issues/906.
		wcorg_include_individual_mu_plugins();
		wcorg_include_mu_plugin_folders();
	}

	$muplugins = glob( __DIR__ . "/$network_folder/*.php" );

	foreach ( $muplugins as $plugin ) {
		if ( is_file( $plugin ) ) {
			require_once $plugin;
		}
	}
}

/**
 * Load individually-targeted files
 *
 * This is because the folder contains some .php files that we don't want to automatically include with glob().
 */
function wcorg_include_individual_mu_plugins() {
	// todo move some of this into wcorg_include_common_plugins() and wcorg_include_wordcamp_only_plugins() as
	// they've been audited/updated for events sites.
	// see https://github.com/WordPress/wordcamp.org/issues/906.

	$shortcodes = dirname( __DIR__ ) . '/mu-plugins-private/wordcamp-shortcodes/wc-shortcodes.php';

	require_once __DIR__ . '/wp-cli-commands/bootstrap.php';
	require_once __DIR__ . '/camptix-tweaks/camptix-tweaks.php';
	require_once __DIR__ . '/blocks/blocks.php';
	require_once __DIR__ . '/quickbooks/quickbooks.php';
	require_once __DIR__ . '/theme-templates/bootstrap.php';
	require_once __DIR__ . '/virtual-embeds/virtual-embeds.php';

	if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	} else {
		wp_die( 'Required files are missing. Please run <code>composer install</code> and try again.' );
	}

	if ( is_file( $shortcodes ) ) {
		require_once $shortcodes;
	}
}

/**
 * Load every mu-plugin in these folders
 */
function wcorg_include_mu_plugin_folders() {
	// todo move some of this into wcorg_include_common_plugins() and wcorg_include_wordcamp_only_plugins() as
	// they've been audited/updated for events sites.
	// see https://github.com/WordPress/wordcamp.org/issues/906.

	$include_folders = array(
		dirname( __DIR__ ) . '/mu-plugins-private',
		__DIR__ . '/jetpack-tweaks',
	);

	foreach ( $include_folders as $folder ) {
		$plugins = glob( $folder . '/*.php' );

		if ( is_array( $plugins ) ) {
			foreach ( $plugins as $plugin ) {
				if ( is_file( $plugin ) ) {
					require_once $plugin;
				}
			}
		}
	}
}
