<?php
/**
 * Notices for the plugin's admin screens: the organizer's vetting request and the import.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Queues a notice for the current user's next screen, since saving redirects before it can be shown.
 *
 * @param string $type    `success`, `warning` or `error`.
 * @param string $message The message.
 */
function add_notice( string $type, string $message ): void {
	$key     = 'sv_notices_' . get_current_user_id();
	$notices = (array) get_transient( $key );

	$notices[] = array(
		'type'    => $type,
		'message' => $message,
	);

	set_transient( $key, array_filter( $notices ), MINUTE_IN_SECONDS );
}

/**
 * Shows the current user's queued notices.
 */
function render_notices(): void {
	$key     = 'sv_notices_' . get_current_user_id();
	$notices = array_filter( (array) get_transient( $key ) );

	if ( ! $notices ) {
		return;
	}

	delete_transient( $key );

	foreach ( $notices as $notice ) {
		wp_admin_notice(
			esc_html( $notice['message'] ),
			array(
				'type'        => $notice['type'],
				'dismissible' => true,
			)
		);
	}
}
add_action( 'admin_notices', __NAMESPACE__ . '\render_notices' );
