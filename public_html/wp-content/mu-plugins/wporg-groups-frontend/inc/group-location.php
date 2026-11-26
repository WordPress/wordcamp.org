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

use GatherPress\Core\Geocoding;
use WordCamp\Logger;
use WP_Error;

use function WordCamp\Groups\Archive\get_group_sites;

defined( 'WPINC' ) || die();

const TYPE_META_KEY        = 'wporg_group_location_type';
const CITY_META_KEY        = 'wporg_group_location_city';
const COUNTRY_META_KEY     = 'wporg_group_location_country';
const COORDINATES_META_KEY = 'wporg_group_location_coordinates';

const TYPE_PHYSICAL = 'physical';
const TYPE_ONLINE   = 'online';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'wc-groups geocode-locations', __NAMESPACE__ . '\cli_geocode_locations' );
}

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
			__( 'Choose an in-person or online location.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	$city         = trim( sanitize_text_field( $location['city'] ?? '' ) );
	$country_code = strtoupper( sanitize_text_field( $location['countryCode'] ?? '' ) );

	if ( '' === $city || '' === $country_code ) {
		return new WP_Error(
			'wporg_groups_incomplete_location',
			__( 'City and country are required for an in-person group.', 'wordcamporg' ),
			array( 'status' => 400 )
		);
	}

	if ( ! wcorg_get_country_name_from_code( $country_code ) ) {
		return new WP_Error(
			'wporg_groups_invalid_country',
			__( 'Choose a valid country.', 'wordcamporg' ),
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
	$site_id  = get_current_blog_id();
	$previous = get_location( $site_id );

	update_site_meta( $site_id, TYPE_META_KEY, $location['type'] );

	if ( TYPE_ONLINE === $location['type'] ) {
		delete_site_meta( $site_id, CITY_META_KEY );
		delete_site_meta( $site_id, COUNTRY_META_KEY );
		delete_site_meta( $site_id, COORDINATES_META_KEY );

		return;
	}

	update_site_meta( $site_id, CITY_META_KEY, $location['city'] );
	update_site_meta( $site_id, COUNTRY_META_KEY, $location['countryCode'] );

	if ( $previous !== $location ) {
		geocode_location( $site_id, $location );
	}
}

/**
 * Remove all location metadata for the current group.
 */
function clear_location(): void {
	$site_id = get_current_blog_id();

	delete_site_meta( $site_id, TYPE_META_KEY );
	delete_site_meta( $site_id, CITY_META_KEY );
	delete_site_meta( $site_id, COUNTRY_META_KEY );
	delete_site_meta( $site_id, COORDINATES_META_KEY );
}

/**
 * Where an in-person group's city is, as `latitude` and `longitude`, or null if it hasn't been found.
 */
function get_coordinates( int $site_id = 0 ): ?array {
	$coordinates = get_site_meta( $site_id ?: get_current_blog_id(), COORDINATES_META_KEY, true );

	return is_array( $coordinates ) ? $coordinates : null;
}

/**
 * Look up where an in-person group's city is, for its marker on the groups list map, and return whether it was found.
 */
function geocode_location( int $site_id, array $location ): bool {
	$country = (string) wcorg_get_country_name_from_code( $location['countryCode'] );
	$result  = null;

	// GatherPress's geocoder, which already places the groups' venues.
	if ( '' !== $country && class_exists( Geocoding::class ) ) {
		$result = Geocoding::get_instance()->geocode_to_result( $location['city'] . ', ' . $country );
	}

	// The country check keeps a city name shared by several countries in the right one.
	if ( ! is_array( $result ) || '' === $result['latitude'] || strtoupper( $result['country_code'] ) !== $location['countryCode'] ) {
		delete_site_meta( $site_id, COORDINATES_META_KEY );
		Logger\log( 'geocoding_failure', compact( 'site_id', 'result' ) );

		return false;
	}

	update_site_meta(
		$site_id,
		COORDINATES_META_KEY,
		array(
			'latitude'  => round( (float) $result['latitude'], 4 ),
			'longitude' => round( (float) $result['longitude'], 4 ),
		)
	);

	return true;
}

/**
 * Find the city of every in-person group that has no coordinates yet.
 *
 * ## EXAMPLES
 *
 *     wp wc-groups geocode-locations --url=events.wordpress.org/group/
 *
 * @when after_wp_load
 */
function cli_geocode_locations(): void {
	foreach ( get_group_sites() as $site ) {
		$site_id  = (int) $site->blog_id;
		$location = get_location( $site_id );

		if ( TYPE_PHYSICAL !== ( $location['type'] ?? '' ) || get_coordinates( $site_id ) ) {
			continue;
		}

		$label = sprintf( '%d: %s, %s', $site_id, $location['city'], $location['countryCode'] );

		if ( geocode_location( $site_id, $location ) ) {
			\WP_CLI::log( "Found $label" );
		} else {
			\WP_CLI::warning( "Not found $label" );
		}
	}

	\WP_CLI::success( 'Done.' );
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
		return __( 'Online', 'wordcamporg' );
	}

	$city         = $location['city'];
	$country_name = (string) wcorg_get_country_name_from_code( $location['countryCode'] );

	if ( '' === $city || '' === $country_name ) {
		return $city . $country_name;
	}

	return sprintf(
		/* translators: 1: city name, 2: country name. */
		__( '%1$s, %2$s', 'wordcamporg' ),
		$city,
		$country_name
	);
}
