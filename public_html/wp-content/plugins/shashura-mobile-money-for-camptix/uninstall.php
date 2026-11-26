<?php
/**
 * Uninstall: removes this plugin's settings, locks and temporary data.
 *
 * Payment records on CampTix attendees (_shashura_* meta) are kept on purpose: they are part
 * of the event's accounts. Use Tools → Erase Personal Data to remove a buyer's details.
 *
 * @package CampTix_Shashura
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove plugin settings, locks, and transients for the current site.
 */
function shasmomo_uninstall_site() {
	global $wpdb;

	// The settings live inside CampTix's own options.
	$options = get_option( 'camptix_options' );
	if ( is_array( $options ) ) {
		unset( $options['payment_options_shashura'] );
		if ( isset( $options['payment_methods'] ) && is_array( $options['payment_methods'] ) ) {
			unset( $options['payment_methods']['shashura'] );
		}
		update_option( 'camptix_options', $options );
	}

	// Order locks and transients (API token, rate limits and other short-lived data).
	$patterns = array(
		$wpdb->esc_like( 'shasmomo_lock_' ) . '%',
		$wpdb->esc_like( '_transient_shasmomo_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_shasmomo_' ) . '%',
	);
	foreach ( $patterns as $pattern ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of rows with a known prefix on uninstall.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
	}
}

if ( is_multisite() ) {
	// WP_Site_Query defaults to 100 results; paginate to reach every site without
	// loading a potentially enormous network into memory all at once.
	$shasmomo_page_size = 100;
	$shasmomo_offset    = 0;
	do {
		$shasmomo_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $shasmomo_page_size,
				'offset' => $shasmomo_offset,
			)
		);
		foreach ( $shasmomo_ids as $shasmomo_site_id ) {
			switch_to_blog( $shasmomo_site_id );
			try {
				shasmomo_uninstall_site();
			} finally {
				restore_current_blog();
			}
		}
		$shasmomo_count   = count( $shasmomo_ids );
		$shasmomo_offset += $shasmomo_count;
	} while ( $shasmomo_count === $shasmomo_page_size );
} else {
	shasmomo_uninstall_site();
}
