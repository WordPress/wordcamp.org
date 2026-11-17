<?php
/**
 * Notices queued for the current user: shown on their next admin screen, or by the block editor after it saves.
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
 * Takes the current user's queued notices, so each is shown once.
 *
 * @return array[] Each notice's `type` and `message`.
 */
function take_notices(): array {
	$key     = 'sv_notices_' . get_current_user_id();
	$notices = array_values( array_filter( (array) get_transient( $key ) ) );

	if ( $notices ) {
		delete_transient( $key );
	}

	return $notices;
}

/**
 * Shows the current user's queued notices.
 *
 * Not on block editor screens, which hide them: the meta-box save loads one as it redirects, before the vetting box's
 * redraw can take the save's notices.
 */
function render_notices(): void {
	if ( get_current_screen()?->is_block_editor() ) {
		return;
	}

	foreach ( take_notices() as $notice ) {
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
