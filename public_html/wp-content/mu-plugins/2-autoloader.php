<?php

namespace WordCamp\Autoloader;
defined( 'WPINC' ) or die();

spl_autoload_register( __NAMESPACE__ . '\autoload' );

/**
 * Autoload Utility classes
 *
 * @param string $class_name
 */
function autoload( $class_name ) {
	switch( $class_name ) {
		case false !== strpos( $class_name, 'WordCamp\Utilities' ):
			$file_name = str_replace( 'WordCamp\Utilities\\', '', $class_name );
			$file_name = str_replace( '_', '-', strtolower( $file_name ) );

			require_once( sprintf( '%s/utilities/class-%s.php', __DIR__, $file_name ) );
			break;

		case false !== strpos( $class_name, 'WordPressdotorg\MU_Plugins\Utilities' ):
			$file_name = str_replace( 'WordPressdotorg\MU_Plugins\Utilities\\', '', $class_name );
			$file_name = str_replace( '_', '-', strtolower( $file_name ) );

			require_once( sprintf(
				'%s/mu-plugins-private/wporg-mu-plugins/pub-sync/utilities/class-%s.php',
				WP_CONTENT_DIR,
				$file_name
			) );
			break;
	}
}
