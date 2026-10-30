<?php

namespace CampTix\Badge_Generator;
use WP_Http_Proxy;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

defined( 'WPINC' ) || die();

const GRAVATAR_PROFILE_CACHE_KEY = 'cbg_gravatar_profile_';

/**
 * Get the hash Gravatar uses to identify an email address
 *
 * @param string $email
 *
 * @return string An empty string when the email is empty.
 */
function get_gravatar_hash( $email ) {
	$email = strtolower( trim( (string) $email ) );

	return '' === $email ? '' : hash( 'sha256', $email );
}

/**
 * Check if badges should pull details from attendees' public Gravatar profiles
 *
 * @return bool
 */
function use_gravatar_profiles() {
	return (bool) get_option( 'cbg_use_gravatar_profiles', true );
}

/**
 * Get the badge details from an attendee's public Gravatar profile
 *
 * Call prefetch_gravatar_profiles() first when looping over many attendees, so that this reads from the cache
 * instead of making one request per attendee.
 *
 * @param string $email
 *
 * @return array The `location`, `company`, and `job_title` fields, which may be empty strings. An empty array
 *               when the attendee has no public profile.
 */
function get_gravatar_profile( $email ) {
	$hash = get_gravatar_hash( $email );

	if ( ! $hash ) {
		return array();
	}

	$profile = get_site_transient( GRAVATAR_PROFILE_CACHE_KEY . $hash );

	if ( false === $profile ) {
		prefetch_gravatar_profiles( array( $hash ) );
		$profile = get_site_transient( GRAVATAR_PROFILE_CACHE_KEY . $hash );
	}

	return is_array( $profile ) ? $profile : array();
}

/**
 * Fetch and cache the public Gravatar profiles of many attendees in parallel
 *
 * @param array $hashes Gravatar hashes, from get_gravatar_hash().
 */
function prefetch_gravatar_profiles( $hashes ) {
	$urls = array();

	foreach ( array_filter( $hashes ) as $hash ) {
		if ( false === get_site_transient( GRAVATAR_PROFILE_CACHE_KEY . $hash ) ) {
			$urls[ $hash ] = 'https://api.gravatar.com/v3/profiles/' . $hash;
		}
	}

	foreach ( remote_get_multiple( $urls ) as $hash => $response ) {
		$profile = parse_gravatar_profile_response( $response['status'], $response['body'] );

		if ( null === $profile ) {
			// Don't cache failures for long, so a temporary outage or rate limit doesn't blank the badges.
			set_site_transient( GRAVATAR_PROFILE_CACHE_KEY . $hash, array(), 5 * MINUTE_IN_SECONDS );
		} else {
			set_site_transient( GRAVATAR_PROFILE_CACHE_KEY . $hash, $profile, 12 * HOUR_IN_SECONDS );
		}
	}
}

/**
 * Extract the badge details from a Gravatar profile API response
 *
 * @param int    $status
 * @param string $body
 *
 * @return array|null The profile fields, an empty array when there's no profile, or `null` when the request failed.
 */
function parse_gravatar_profile_response( $status, $body ) {
	if ( 404 === $status ) {
		return array();
	}

	$data = 200 === $status ? json_decode( $body, true ) : null;

	if ( ! is_array( $data ) ) {
		return null;
	}

	$profile = array();

	foreach ( array( 'location', 'company', 'job_title' ) as $field ) {
		$profile[ $field ] = isset( $data[ $field ] ) && is_string( $data[ $field ] ) ? trim( $data[ $field ] ) : '';
	}

	return $profile;
}

/**
 * Download many attendees' Gravatar images in parallel
 *
 * @param array $emails Email addresses, keyed by any unique ID.
 * @param int   $size   Image width and height in pixels.
 *
 * @return array Image binary data, keyed by the same IDs. Attendees without a Gravatar are left out, as are
 *               downloads that still failed after a retry.
 */
function download_gravatar_images( $emails, $size = 500 ) {
	$urls = array();

	foreach ( $emails as $id => $email ) {
		$hash = get_gravatar_hash( $email );

		if ( $hash ) {
			$urls[ $id ] = sprintf( 'https://gravatar.com/avatar/%s?s=%d&d=404&r=g', $hash, $size );
		}
	}

	$images = array();

	// Retry once, because one timed-out request would otherwise leave someone's badge without a photo.
	for ( $attempt = 1; $attempt <= 2 && $urls; $attempt++ ) {
		foreach ( remote_get_multiple( $urls ) as $id => $response ) {
			if ( 200 === $response['status'] && $response['body'] ) {
				$images[ $id ] = $response['body'];
			}

			if ( 200 === $response['status'] || 404 === $response['status'] ) {
				unset( $urls[ $id ] );
			}
		}
	}

	return $images;
}

/**
 * Make many GET requests in parallel
 *
 * WP doesn't offer a parallel version of wp_remote_get(), so this uses Requests directly. That skips the
 * `http_request_args` and `pre_http_request` filters, so it applies WP's proxy settings itself.
 *
 * Requests go out in small batches with a pause between them, so Gravatar isn't flooded. That keeps to about 10
 * requests a second, the same rate as the original badge script.
 *
 * Responses are yielded as each batch finishes, so callers can cache them right away. A request that's cut off
 * part way through a large event then keeps what it fetched, and the next one picks up from there.
 *
 * @param array $urls       URLs keyed by any unique ID.
 * @param int   $batch_size How many requests to have in flight at once.
 *
 * @return \Generator Each response's `status` (0 when the request failed) and `body`, keyed by the same IDs.
 */
function remote_get_multiple( $urls, $batch_size = 5 ) {
	$options = array(
		'timeout'   => 10,
		'useragent' => 'WordCamp.org CampTix Badge Generator; ' . home_url( '/' ),
	);

	$proxy = new WP_Http_Proxy();

	if ( $proxy->is_enabled() ) {
		$options['proxy'] = array( $proxy->host() . ':' . $proxy->port() );

		if ( $proxy->use_authentication() ) {
			$options['proxy'][] = $proxy->username();
			$options['proxy'][] = $proxy->password();
		}
	}

	foreach ( array_chunk( $urls, $batch_size, true ) as $index => $batch ) {
		if ( $index > 0 ) {
			usleep( 500000 );
		}

		$requests = array();

		foreach ( $batch as $id => $url ) {
			$requests[ $id ] = array( 'url' => $url );
		}

		foreach ( Requests::request_multiple( $requests, $options ) as $id => $response ) {
			$is_response = $response instanceof Response;

			yield $id => array(
				'status' => $is_response ? (int) $response->status_code : 0,
				'body'   => $is_response ? $response->body : '',
			);
		}
	}
}
