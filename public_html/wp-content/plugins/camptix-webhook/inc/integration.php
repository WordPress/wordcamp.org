<?php
/**
 * Camptix Webhook Integration
 */

namespace CampTix\Webhook\Integration;

/**
 * Register hooks.
 */
function bootstrap() {
	add_action( 'load-tix_ticket_page_camptix_options', __NAMESPACE__ . '\\test_webhook_action' );

	add_filter( 'camptix_webhook_attendee_data', __NAMESPACE__ . '\\add_attendees_admin_flag', 10, 2 );
	add_filter( 'camptix_webhook_attendee_data', __NAMESPACE__ . '\\add_attendees_meta', 10, 2 );
	add_filter( 'camptix_webhook_attendee_data', __NAMESPACE__ . '\\add_attendees_questions_answered', 10, 2 );
}

/**
 * Test webhook action.
 *
 * This hook only run on camptix Tickets > setup page.
 */
function test_webhook_action() {

	if ( ! isset( $_GET['test_webhook'] ) || '1' !== $_GET['test_webhook'] ) {
		return;
	}

	/** @var CampTix_Plugin $camptix */
	global $camptix;

	$camptix_options = $camptix->get_options();

	$webhook_url = isset( $camptix_options['webhook-url'] ) ? $camptix_options['webhook-url'] : '';

	// Get attendee data.
	$attendee_data = array(
		'timestamp' => time(),
		'status' => 'publish',
		'is_new_entry' => true,
		'tix_email' => 'camptix-webhook-test@wordcamp.org',
		'tix_first_name' => 'Camptix Webhook',
		'tix_last_name' => 'Test',
		'tix_ticket_id' => '0000',
		'tix_coupon' => 'Coupon_XXX',
	);

	// Post ID: -1 will invalid when getting post object. We use this to indicate that this is a test webhook.
	$attendee_data = apply_filters( 'camptix_webhook_attendee_data', $attendee_data, -1 );

	// Prepare webhook data.
	$response = wp_remote_post(
		$webhook_url,
		array(
			'body' => wp_json_encode( $attendee_data ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		add_settings_error(
			'camptix-webhook',
			'error',
			__( 'Webhook test failed: ', 'wordcamporg' ) . $response->get_error_message(),
			'error'
		);
		return;
	}

	add_settings_error(
		'camptix-webhook',
		'success',
		__( 'Webhook test success', 'wordcamporg' ),
		'success'
	);
}

/**
 * Add attendees admin flag to attendee data.
 *
 * @param array $attendee_data Array of attendee data.
 * @param int   $post_id Post ID.
 * @return array
 */
function add_attendees_admin_flag( $attendee_data, $post_id ): array {
	// Post ID: -1 will invalid when getting post object. We use this to indicate that this is a test webhook.
	if ( -1 === $post_id ) {
		$admin_flag                      = array( 'volunteer', 'speaker', 'organiser' );
		$attendee_data['tix_admin_flag'] = $admin_flag[ array_rand( $admin_flag ) ];

		return $attendee_data;
	}

	// Admin flag meta could be more than 1.
	$attendee_data['tix_admin_flag'] = get_post_meta( $post_id, 'camptix-admin-flag', false );

	return $attendee_data;
}

/**
 * Add attendees meta to attendee data.
 *
 * @param array $attendee_data Array of attendee data.
 * @param int   $post_id Post ID.
 * @return array
 */
function add_attendees_meta( $attendee_data, $post_id ): array {
	$allowed_meta = array(
		'tix_accommodations',
		'tix_allergy',
		'tix_coupon',
		'tix_first_time_attending_wp_event',
	);

	// Post ID: -1 will invalid when getting post object. We use this to indicate that this is a test webhook.
	if ( -1 === $post_id ) {
		$attendee_data['tix_accommodations']                = 'yes';
		$attendee_data['tix_allergy']                       = 'no';
		$attendee_data['tix_coupon']                        = 'test';
		$attendee_data['tix_first_time_attending_wp_event'] = 'test';

		return $attendee_data;
	}

	foreach ( $allowed_meta as $meta_key ) {
		$attendee_data[ $meta_key ] = get_post_meta( $post_id, $meta_key, true );
	}

	return $attendee_data;
}

/**
 * Add attendees questions answered to attendee data.
 *
 * @param array $attendee_data Array of attendee data.
 * @param int   $post_id Post ID.
 * @return array
 */
function add_attendees_questions_answered( $attendee_data, $post_id ): array {
	// Post ID: -1 will invalid when getting post object. We use this to indicate that this is a test webhook.
	if ( -1 === $post_id ) {
		$attendee_data['answered'] = 'N/A';

		return $attendee_data;
	}

	global $camptix;
	$ticket_id = intval( get_post_meta( $post_id, 'tix_ticket_id', true ) );
	$questions = $camptix->get_sorted_questions( $ticket_id );
	$answers   = get_post_meta( $post_id, 'tix_questions', true );

	$rows = array();
	foreach ( $questions as $question ) {
		if ( isset( $answers[ $question->ID ] ) ) {
			$answer = $answers[ $question->ID ];
			if ( is_array( $answer ) ) {
				$answer = implode( ', ', $answer );
			}
			$rows[] = array( $question->post_title, $answer );
		}
	}

	$attendee_data['answered'] = $rows;

	return $attendee_data;
}
