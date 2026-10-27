<?php
/**
 * Personal data export and erasure for sponsor contacts, on Central.
 *
 * The person in a company record is its contact. Everything else, from domains and findings to the status and its
 * history, is about the company. Network super admins can see every record, like reviewers can.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\register_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', __NAMESPACE__ . '\register_eraser' );

/**
 * The meta keys that hold a company contact's personal data.
 *
 * @return string[] Labels, keyed by meta key.
 */
function get_contact_fields(): array {
	return array(
		'_sv_contact_first_name' => __( 'First Name', 'wordcamporg' ),
		'_sv_contact_last_name'  => __( 'Last Name', 'wordcamporg' ),
		'_sv_contact_email'      => __( 'Email Address', 'wordcamporg' ),
	);
}

/**
 * Registers the exporter on Central.
 *
 * @param array $exporters Registered exporters.
 * @return array
 */
function register_exporter( array $exporters ): array {
	if ( is_central() ) {
		$exporters['sponsor-vetting'] = array(
			'exporter_friendly_name' => __( 'Sponsor Vetting', 'wordcamporg' ),
			'callback'               => __NAMESPACE__ . '\export_personal_data',
		);
	}

	return $exporters;
}

/**
 * Registers the eraser on Central.
 *
 * @param array $erasers Registered erasers.
 * @return array
 */
function register_eraser( array $erasers ): array {
	if ( is_central() ) {
		$erasers['sponsor-vetting'] = array(
			'eraser_friendly_name' => __( 'Sponsor Vetting', 'wordcamporg' ),
			'callback'             => __NAMESPACE__ . '\erase_personal_data',
		);
	}

	return $erasers;
}

/**
 * Finds the companies an email address is the contact for.
 *
 * @param string $email The email address.
 * @return int[]
 */
function find_companies_by_contact( string $email ): array {
	$email = strtolower( trim( $email ) );

	if ( ! is_email( $email ) ) {
		return array();
	}

	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => COMPANY_POST_TYPE,
				'post_status'    => array_merge( array_keys( get_company_statuses() ), array( 'trash' ) ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_sv_contact_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Privacy requests are rare.
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Privacy requests are rare.
			)
		)
	);
}

/**
 * Exports the companies an email address is the contact for.
 *
 * @param string $email The requester's email address.
 * @return array `data` and `done`, as core expects.
 */
function export_personal_data( string $email ): array {
	$items = array();

	foreach ( find_companies_by_contact( $email ) as $company_id ) {
		$data = array(
			array(
				'name'  => __( 'Company', 'wordcamporg' ),
				'value' => get_the_title( $company_id ),
			),
		);

		foreach ( get_contact_fields() as $meta_key => $label ) {
			$data[] = array(
				'name'  => $label,
				'value' => (string) get_post_meta( $company_id, $meta_key, true ),
			);
		}

		$items[] = array(
			'group_id'    => 'sponsor-vetting',
			'group_label' => __( 'Sponsor Vetting', 'wordcamporg' ),
			'item_id'     => 'sponsor-vetting-' . $company_id,
			'data'        => $data,
		);
	}

	return array(
		'data' => $items,
		'done' => true,
	);
}

/**
 * Removes an email address's contact details from the companies it's the contact for.
 *
 * @param string $email The requester's email address.
 * @return array `items_removed`, `items_retained`, `messages` and `done`, as core expects.
 */
function erase_personal_data( string $email ): array {
	$company_ids = find_companies_by_contact( $email );

	foreach ( $company_ids as $company_id ) {
		foreach ( array_keys( get_contact_fields() ) as $meta_key ) {
			delete_post_meta( $company_id, $meta_key );
		}
	}

	return array(
		'items_removed'  => count( $company_ids ) > 0,
		'items_retained' => count( $company_ids ) > 0,
		'messages'       => $company_ids ? array( __( 'Sponsor contact details were removed. The companies’ vetting records are kept: they are about the companies, not the contact.', 'wordcamporg' ) ) : array(),
		'done'           => true,
	);
}
