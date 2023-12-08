<?php
namespace WordPressdotorg\MU_Plugins\DB_User_Sessions;

add_filter( 'session_token_manager', function( $manager ) {
	if ( in_array( wp_get_environment_type(), [ 'production', 'staging' ], true ) ) {
		$manager = __NAMESPACE__ . '\Tokens';

		// The user sesions are global, not per-site.
		wp_cache_add_global_groups( 'user_sessions' );
	}

	return $manager;
} );

/*
Database schema:
CREATE TABLE `wporg_user_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `verifier` char(64) NOT NULL,
  `expiration` int(10) unsigned NOT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `login` int(10) unsigned NOT NULL,
  `session_meta` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id__verifier` (`user_id`,`verifier`),
  KEY `ip` (`ip`),
  KEY `login` (`login`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
*/
