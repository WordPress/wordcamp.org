<?php

namespace WordCamp\CampTix_Tweaks;

use WP_Post;

defined( 'WPINC' ) || die();

add_filter( 'camptix_attendee_report_extra_columns',                   __NAMESPACE__ . '\add_profile_url_column'         );
add_filter( 'camptix_attendee_report_column_value_wporg_profile_url',  __NAMESPACE__ . '\get_profile_url_column_value', 10, 2 );

/**
 * Offer a WordPress.org profile URL column to the attendee export.
 *
 * `CampTix_Require_Login` already exports the raw username, but consumers can't reliably turn that into a
 * profile URL: profile URLs are keyed on `user_nicename`, not `user_login`.
 *
 * @param array $columns
 *
 * @return array
 */
function add_profile_url_column( $columns ) {
	$columns['wporg_profile_url'] = __( 'WordPress.org Profile URL', 'wordcamporg' );

	return $columns;
}

/**
 * Render the attendee's WordPress.org profile URL for the export.
 *
 * Derived on read rather than stored at purchase, so that it stays correct for tickets that were
 * bought before this column existed, and can't go stale if a nicename changes.
 *
 * @param string  $value    Unused; the export passes an empty default.
 * @param WP_Post $attendee
 *
 * @return string The profile URL, or an empty string when there is no account to link to.
 */
function get_profile_url_column_value( $value, $attendee ) {
	$username = get_post_meta( $attendee->ID, 'tix_username', true );

	if ( ! $username ) {
		return '';
	}

	/*
	 * Anything that doesn't resolve to a real account gets an empty cell. That covers the
	 * `CampTix_Require_Login::UNCONFIRMED_USERNAME` sentinel stored for tickets that haven't been claimed
	 * yet, as well as deleted accounts and bad data, without this file needing to know about any of them.
	 */
	$user = wcorg_get_user_by_canonical_names( $username );

	if ( ! $user ) {
		return '';
	}

	return 'https://wordpress.org/@' . strtolower( $user->user_nicename );
}
