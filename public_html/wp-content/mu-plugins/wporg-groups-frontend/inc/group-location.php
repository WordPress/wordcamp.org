<?php
/**
 * Group-level location data.
 *
 * A location belongs to the group site, rather than to any particular event.
 * Event venues and online access details remain in GatherPress.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Group_Location;

use WP_Error;

defined( 'WPINC' ) || die();

const TYPE_META_KEY    = 'wporg_group_location_type';
const CITY_META_KEY    = 'wporg_group_location_city';
const COUNTRY_META_KEY = 'wporg_group_location_country';

const TYPE_PHYSICAL = 'physical';
const TYPE_ONLINE   = 'online';

/**
 * Missing metadata is treated as an unspecified location.
 *
 * Whatever is stored is reported back verbatim, including a country code that no
 * longer resolves to a name. Collapsing that to `null` would leave the settings
 * form with no location selected, so the next save of any other field would post
 * `location: null` and silently wipe the city and country. Validation belongs on
 * the write path, in `normalize_location()`.
 */
function get_location( int $site_id = 0 ): ?array {
	$site_id = $site_id ?: get_current_blog_id();
	$type    = (string) get_site_meta( $site_id, TYPE_META_KEY, true );

	if ( TYPE_ONLINE === $type ) {
		return array( 'type' => TYPE_ONLINE );
	}

	if ( TYPE_PHYSICAL !== $type ) {
		return null;
	}

	$city         = trim( (string) get_site_meta( $site_id, CITY_META_KEY, true ) );
	$country_code = strtoupper( (string) get_site_meta( $site_id, COUNTRY_META_KEY, true ) );

	if ( '' === $city && '' === $country_code ) {
		return null;
	}

	return array(
		'type'        => TYPE_PHYSICAL,
		'city'        => $city,
		'countryCode' => $country_code,
	);
}

/**
 * Build localized country options for the group settings form.
 */
function get_country_options(): array {
	return array_values(
		array_map(
			static function ( array $country ): array {
				return array(
					'code' => $country['alpha2'],
					'name' => $country['name'],
				);
			},
			wcorg_get_countries()
		)
	);
}

/**
 * Normalize and validate a submitted location.
 *
 * Online locations contain only their type; physical locations require a city and recognized country.
 */
function normalize_location( array $location ): array|WP_Error {
	$type = sanitize_key( $location['type'] ?? '' );

	if ( TYPE_ONLINE === $type ) {
		return array( 'type' => TYPE_ONLINE );
	}

	if ( TYPE_PHYSICAL !== $type ) {
		return new WP_Error(
			'wporg_groups_invalid_location_type',
			__( 'Choose an in-person or online location.', 'wporg-groups-frontend' ),
			array( 'status' => 400 )
		);
	}

	$city         = trim( sanitize_text_field( $location['city'] ?? '' ) );
	$country_code = strtoupper( sanitize_text_field( $location['countryCode'] ?? '' ) );

	if ( '' === $city || '' === $country_code ) {
		return new WP_Error(
			'wporg_groups_incomplete_location',
			__( 'City and country are required for an in-person group.', 'wporg-groups-frontend' ),
			array( 'status' => 400 )
		);
	}

	if ( ! wcorg_get_country_name_from_code( $country_code ) ) {
		return new WP_Error(
			'wporg_groups_invalid_country',
			__( 'Choose a valid country.', 'wporg-groups-frontend' ),
			array( 'status' => 400 )
		);
	}

	return array(
		'type'        => TYPE_PHYSICAL,
		'city'        => $city,
		'countryCode' => $country_code,
	);
}

/**
 * Persist a normalized location for the current group.
 *
 * Saving an online location removes any stale city and country metadata.
 */
function save_location( array $location ): void {
	$site_id = get_current_blog_id();

	update_site_meta( $site_id, TYPE_META_KEY, $location['type'] );

	if ( TYPE_ONLINE === $location['type'] ) {
		delete_site_meta( $site_id, CITY_META_KEY );
		delete_site_meta( $site_id, COUNTRY_META_KEY );

		return;
	}

	update_site_meta( $site_id, CITY_META_KEY, $location['city'] );
	update_site_meta( $site_id, COUNTRY_META_KEY, $location['countryCode'] );
}

/**
 * Remove all location metadata for the current group.
 */
function clear_location(): void {
	$site_id = get_current_blog_id();

	delete_site_meta( $site_id, TYPE_META_KEY );
	delete_site_meta( $site_id, CITY_META_KEY );
	delete_site_meta( $site_id, COUNTRY_META_KEY );
}

/**
 * Build the public location label, or an empty string when unspecified.
 *
 * A stored country code that no longer resolves is dropped from the label rather
 * than shown as a bare code or an empty half of "City, ".
 */
function get_location_label(): string {
	$location = get_location();

	if ( null === $location ) {
		return '';
	}

	if ( TYPE_ONLINE === $location['type'] ) {
		return __( 'Online', 'wporg-groups-frontend' );
	}

	$city         = $location['city'];
	$country_name = (string) wcorg_get_country_name_from_code( $location['countryCode'] );

	if ( '' === $city || '' === $country_name ) {
		return $city . $country_name;
	}

	return sprintf(
		/* translators: 1: city name, 2: country name. */
		__( '%1$s, %2$s', 'wporg-groups-frontend' ),
		$city,
		$country_name
	);
}
