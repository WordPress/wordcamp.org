<?php
/**
 * Personal data export and erasure for applications.
 *
 * @package WordCamp\Open_Horizons
 */

declare( strict_types = 1 );

namespace WordCamp\Open_Horizons;

defined( 'WPINC' ) || die();

add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\register_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', __NAMESPACE__ . '\register_eraser' );

/**
 * Registers the exporter.
 *
 * @param array $exporters Registered exporters.
 * @return array
 */
function register_exporter( array $exporters ): array {
	$exporters['open-horizons'] = array(
		'exporter_friendly_name' => __( 'Open Horizons Scholarship Applications', 'wordcamporg' ),
		'callback'               => __NAMESPACE__ . '\export_personal_data',
	);

	return $exporters;
}

/**
 * Registers the eraser.
 *
 * @param array $erasers Registered erasers.
 * @return array
 */
function register_eraser( array $erasers ): array {
	$erasers['open-horizons'] = array(
		'eraser_friendly_name' => __( 'Open Horizons Scholarship Applications', 'wordcamporg' ),
		'callback'             => __NAMESPACE__ . '\erase_personal_data',
	);

	return $erasers;
}

/**
 * Exports the applications made with an email address, or by its account.
 *
 * @param string $email The requester's email address.
 * @return array `data` and `done`, as core expects.
 */
function export_personal_data( string $email ): array {
	$items = array();

	foreach ( find_applications( array( 'email' => $email ) ) as $post_id ) {
		$data = array(
			array(
				'name'  => __( 'Event', 'wordcamporg' ),
				'value' => get_the_title( (int) get_post_meta( $post_id, '_oh_event_id', true ) ),
			),
			array(
				'name'  => __( 'Status', 'wordcamporg' ),
				'value' => get_statuses()[ get_post_status( $post_id ) ] ?? '',
			),
		);

		$sections = array(
			array( get_fields(), (array) get_post_meta( $post_id, '_oh_application', true ) ),
			array( get_identity_fields(), (array) get_post_meta( $post_id, '_oh_identity', true ) ),
		);

		foreach ( $sections as list( $fields, $answers ) ) {
			foreach ( $fields as $key => $field ) {
				if ( isset( $answers[ $key ] ) ) {
					$data[] = array(
						'name'  => $field['label'],
						'value' => format_answer( $field, $answers[ $key ] ),
					);
				}
			}
		}

		$items[] = array(
			'group_id'    => 'open-horizons',
			'group_label' => __( 'Open Horizons Scholarship Applications', 'wordcamporg' ),
			'item_id'     => 'open-horizons-' . $post_id,
			'data'        => $data,
		);
	}

	return array(
		'data' => $items,
		'done' => true,
	);
}

/**
 * Anonymizes the applications made with an email address, or by its account.
 *
 * @param string $email The requester's email address.
 * @return array `items_removed`, `items_retained`, `messages` and `done`, as core expects.
 */
function erase_personal_data( string $email ): array {
	$post_ids = find_applications( array( 'email' => $email ) );

	foreach ( $post_ids as $post_id ) {
		anonymize( $post_id );
	}

	return array(
		'items_removed'  => count( $post_ids ) > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

/**
 * Removes everything about the applicant from an application.
 *
 * Keeps what isn't about them: the event, the status, and what was paid, which the program's yearly numbers and
 * its accounting rely on.
 *
 * @param int $post_id The application.
 */
function anonymize( int $post_id ): void {
	$personal = array(
		'_oh_applicant_user_id',
		'_oh_applicant_email',
		'_oh_application',
		'_oh_identity',
		'_oh_identity_consent',
		'_oh_reviewer',
		'_oh_second_reviewer',
		'_oh_rationale',
		'_oh_freescout_links',
		'_oh_note',
		// Core derives slugs from the title, which held the applicant's name.
		'_wp_desired_post_slug',
		'_wp_old_slug',
	);

	foreach ( $personal as $meta_key ) {
		delete_post_meta( $post_id, $meta_key );
	}

	wp_update_post(
		array(
			'ID'         => $post_id,
			'post_title' => __( 'Anonymized application', 'wordcamporg' ),
			'post_name'  => 'anonymized-' . $post_id,
		)
	);

	update_post_meta( $post_id, '_oh_anonymized', time() );
}
