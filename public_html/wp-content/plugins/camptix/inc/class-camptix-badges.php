<?php

namespace Camptix\Profile_Badges;

use WordPressdotorg\Profiles;

/**
 * Process submission and returns appropriate message
 *
 * @return string
 */
function process_badges() {

	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'badge-submission' ) ) {
		return __( 'Invalid request', 'wordcamporg' );
	}

	$usernames = sanitize_text_field( $_POST['usernames'] );
	$operation = sanitize_text_field( $_POST['operation'] );
	$badge = sanitize_text_field( $_POST['badge_name'] );

	$valid_operations = [ 'add', 'remove' ];
	$valid_badges = [ 'wordcamp-volunteer' ];

	if ( ! in_array( $operation, $valid_operations ) ) {
		return sprintf( __( 'Invalid badge operation used, valid commands are: %s', 'wordcamporg' ), implode( ',', $valid_operations ) );
	}

	if ( ! in_array( $badge, $valid_badges ) ) {
		return __( 'Invalid badge', 'wordcamporg' );
	}

	if ( empty( $usernames ) ) {
		return __( 'You must supply a list of usernames', 'wordcamporg' );
	}

	$users = explode( "\n", $usernames );

	Profiles\badge_api( $operation, $badge, $users );

	// Badge_api doesn't return anything apart from a success message, so lets guess how many items were updated.
	$count = count( $users );

	return sprintf( _n( '%s badge updated', '%s badges updated', $count, 'wordcamporg' ), number_format_i18n( $count ) );
}

/**
 * Outputs manage badge admin screen, and allows processing of submit.
 */
function menu_badges() {
	if ( isset( $_GET['badge-submit'] ) && ( 1 == $_GET['badge-submit'] ) ) {
		$output = process_badges();
		wp_admin_notice( $output );
	}

	// If adding more badges, make sure to add them to the validation check in `process_badges`.
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Profile Badge Management', 'wordcamporg' ); ?></h1>
		<p><?php esc_html_e( 'This tool allows a limited number of badges to be managed on wordpress.org profiles', 'wordcamporg' ); ?></p>
	</div>
	<form method="post" action="<?php echo esc_url( add_query_arg( 'badge-submit', '1' ) ); ?>">
		<div>
			<select name="badge_name">
				<option value="wordcamp-volunteer"><?php esc_html_e( 'WordCamp Volunteer', 'wordcamporg' ); ?></option>
			</select>
			<select name="operation">
				<option value="add"><?php esc_html_e( 'Add', 'wordcamporg' ); ?></option>
				<option value="remove"><?php esc_html_e( 'Remove', 'wordcamporg' ); ?></option>
			</select>
		</div>
		<div class="wrap">
			<textarea name="usernames" cols="50" rows="20" placeholder="<?php esc_attr_e( 'Input usernames, 1 per row', 'wordcamporg' ); ?>"></textarea>
		</div>
		<input type="hidden" name="action" value="badge_submission" />
		<?php wp_nonce_field( 'badge-submission' ); ?>
		<div><?php submit_button( __( 'Submit', 'wordcamporg' ) ); ?></div>
	</form>
	<?php
}
