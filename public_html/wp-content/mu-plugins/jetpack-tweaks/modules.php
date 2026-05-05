<?php

namespace WordCamp\Jetpack_Tweaks\Modules;

defined( 'WPINC' ) || die();

add_filter( 'jetpack_get_available_modules', __NAMESPACE__ . '\disable_modules' );
add_filter( 'jetpack_get_default_modules', __NAMESPACE__ . '\default_jetpack_modules' );
add_filter( 'jetpack_get_module', __NAMESPACE__ . '\force_load_subscriptions_module', 10, 2 );
add_filter( 'my_jetpack_red_bubble_notification_slugs', __NAMESPACE__ . '\disable_install_nags', 200 );
add_filter( 'jetpack_start_enable_sso', '__return_false' );

/**
 * Disable Jetpack Modules which are not applicable to WordCamp.org.
 *
 * @param array $modules The Jetpack modules.
 * @return array
 */
function disable_modules( $modules ) {
	// WordCamp infrastructure has monitoring in place which alerts those who can resolve downtime issues.
	unset( $modules['monitor'] );

	// Not supported on the WordCamp infrastructure.
	unset( $modules['waf'] );

	return $modules;
}

/**
 * Determine which Jetpack modules should be automatically activated when new sites are created
 */
function default_jetpack_modules( $modules ) {
	// Disable some default modules.
	$modules = array_diff(
		$modules,
		array(
			'widget-visibility', // better performance without.
			'sitemaps', // Core generates basic sitemaps.
		)
	);

	// Add new default modules.
	array_push(
		$modules,
		'contact-form',
		'copy-post',
		'custom-css',
		'image-cdn',
		'sharedaddy',
		'shortcodes',
		'subscriptions'
	);

	$modules = array_unique( $modules );

	return $modules;
}

/**
 * Force load the Subscriptions module, even without a user connection.
 */
function force_load_subscriptions_module( $module, $module_slug ) {
	if ( 'subscriptions' === $module_slug ) {
		$module['requires_user_connection'] = false;
	}

	return $module;
}

/**
 * Disable the install-plugin nags.
 */
function disable_install_nags( $slugs ) {
	unset( $slugs['jetpack_complete--plugins_needing_installed_activated'] );

	return $slugs;
}