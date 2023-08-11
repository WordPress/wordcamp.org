<?php

namespace WordPressdotorg\MU_Plugins\Plugin_Tweaks\Stream;

defined( 'WPINC' ) || die();

/**
 * Actions and filters.
 */
add_filter( 'wp_stream_log_data', __NAMESPACE__ . '\include_user_name_in_creation_log' );
add_filter( 'wp_stream_is_record_excluded', __NAMESPACE__ . '\exclude_profile_updates_as_part_of_user_creation', 10, 2 );
add_filter( 'bbp_set_user_role', __NAMESPACE__ . '\log_forum_role_change', 10, 3 );

/**
 * Stream by default logs new user registrations as 'New user registration' which doesn't come up in search-by-username.
 *
 * Suffix the message with the user_login.
 *
 * @param array $record
 *
 * @return array
 */
function include_user_name_in_creation_log( $record ) {
	if (
		'users' === $record['connector'] &&
		'users' === $record['context'] &&
		'created' === $record['action']
	) {
		$user = get_user_by( 'id', $record['object_id'] );
		if ( $user && ! str_contains( $record['message'], '%s' ) ) {
			$record['message'] = 'New user registration: %s';
			$record['args'] = [ 'user_login' => $user->user_login ];
		}
	}

	return $record;
}

/**
 * Stream records 'profile updated' events during user registration, as we call `wp_update_user(). Avoid these.
 *
 * @param bool $exclude If this record should be excluded.
 * @param array $record The record to insert.
 * @return bool
 */
function exclude_profile_updates_as_part_of_user_creation( $exclude, $record ) {
	if (
		// Users are not logged in as part of registration.
		! is_user_logged_in() &&
		doing_action( 'profile_update' ) &&
		defined( 'WPORG_LOGIN_REGISTER_BLOGID' ) &&
		WPORG_LOGIN_REGISTER_BLOGID === get_current_blog_id() &&
		'users' === $record['connector'] &&
		'profiles' === $record['context'] &&
		'updated' === $record['action']
	) {
		$exclude = true;
	}

	return $exclude;
}

/**
 * Log the bbPress forum role being changed.
 */
function log_forum_role_change( $new_role, $user_id, $user ) {
	if ( $new_role ) {
		log( "%s's forum role set to %s", [ $user->user_login, $new_role ], $user_id, 'bbpress', 'role', 'updated' );
	}

	return $new_role;
}

/**
 * Helper Functions
 */

/**
 * Log audit log entries into Stream.
 *
 * This is a shortcut past the Stream Connectors, reaching in and calling the logging function directly..
 *
 * @see https://github.com/xwp/stream/blob/develop/classes/class-log.php#L57-L70 for args.
 */
function log( $message, $args, $object_id, $connector, $context, $action, $user_id = null ) {
	if (
		! function_exists( 'wp_stream_get_instance' ) ||
		! is_callable( [ wp_stream_get_instance()->log, 'log' ] )
	) {
		return false;
	}

	wp_stream_get_instance()->log->log( $connector, $message, $args, $object_id, $context, $action, $user_id );
}