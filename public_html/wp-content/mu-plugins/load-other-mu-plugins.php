<?php

defined( 'WPINC' ) || die();

wcorg_include_individual_mu_plugins();
wcorg_include_mu_plugin_folders();

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
	require_once __DIR__ . '/theme-templates/bootstrap.php';
	require_once __DIR__ . '/virtual-embeds/virtual-embeds.php';

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
