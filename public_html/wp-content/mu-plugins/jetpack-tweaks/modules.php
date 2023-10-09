<?php

namespace WordCamp\Jetpack_Tweaks\Modules;

defined( 'WPINC' ) || die();

add_filter( 'jetpack_get_available_modules', __NAMESPACE__ . '\disable_modules' );
add_filter( 'jetpack_get_default_modules', __NAMESPACE__ . '\default_jetpack_modules' );


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
