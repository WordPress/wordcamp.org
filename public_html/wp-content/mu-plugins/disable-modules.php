<?php
namespace WordCamp\Jetpack_Tweaks\DisabledModules;

defined( 'WPINC' ) || die();

add_filter( 'jetpack_get_available_modules', __NAMESPACE__ . '\disable_modules' );
function disable_modules( $modules ) {
	// WordCamp infrastructure has monitoring in place which alerts those who can resolve downtime issues.
	unset( $modules['monitor'] );

	// Not supported on the WordCamp infrastructure.
	unset( $modules['waf'] );

	return $modules;
}
