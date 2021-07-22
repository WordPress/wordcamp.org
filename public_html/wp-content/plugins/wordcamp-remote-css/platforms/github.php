<?php

namespace WordCamp\RemoteCSS\Github;
use const WordCamp\RemoteCSS\USER_AGENT;
use WP_Error;

const API_HOSTNAME = 'api.github.com';

defined( 'WPINC' ) || die();

/*
 * @todo -- Once another platform has been added and you can see the similarities, this should probably be
 * refactored to extend an abstract class or implement an interface.
 */

add_filter( 'wcrcss_trusted_remote_hostnames', __NAMESPACE__ . '\whitelist_trusted_hostnames' );
add_filter( 'wcrcss_validate_remote_css_url',  __NAMESPACE__ . '\convert_to_api_urls'         );
add_filter( 'pre_http_request',                __NAMESPACE__ . '\authenticate_requests', 10, 3 );
add_filter( 'wcrcss_unsafe_remote_css',        __NAMESPACE__ . '\decode_api_response',  10, 2 );

/**
 * Add GitHub's hostnames to the whitelist of trusted hostnames
 *
 * @param array $hostnames
 *
 * @return array
 */
function whitelist_trusted_hostnames( $hostnames ) {
	return array_merge( $hostnames, array( 'github.com', 'raw.githubusercontent.com', API_HOSTNAME ) );
}

/**
 * Convert various GitHub URLs to their API equivalents
 *
 * We need to use the API to request the the file contents, because github.com shows the file embedded in an HTML
 * page, and raw.githubusercontent.come is cached and will often respond with stale content.
 *
 * @param string $remote_css_url
 *
 * @return string
 */
function convert_to_api_urls( $remote_css_url ) {
	$owner      = null;
	$repository = null;
	$file_path  = null;
	$parsed_url = wp_parse_url( $remote_css_url );
	$path       = explode( '/', $parsed_url['path'] );

	if ( 'github.com' === $parsed_url['host'] ) {
		$owner      = $path[1];
		$repository = $path[2];
		$file_path  = implode( '/', array_slice( $path, 5 ) );
	} elseif ( 'raw.githubusercontent.com' === $parsed_url['host'] ) {
		$owner      = $path[1];
		$repository = $path[2];
		$file_path  = implode( '/', array_slice( $path, 4 ) );
	}

	if ( $owner && $repository && $file_path ) {
		$remote_css_url = sprintf(
			'https://%s/repos/%s/%s/contents/%s',
			API_HOSTNAME,
			$owner,
			$repository,
			$file_path
		);
	}

	return $remote_css_url;
}

/**
 * Add authentication parameters to GitHub API requests
 *
 * This allows us to make 12.5k requests per hour, instead of just 60.
 *
 * @action pre_http_request
 *
 * @param false|array|WP_Error $preempt      See `pre_http_request`.
 * @param array                $request_args
 * @param string               $request_url
 *
 * @return false|array|WP_Error
 */
function authenticate_requests( $preempt, $request_args, $request_url ) {
	$parsed_url = wp_parse_url( $request_url );

	if ( ! should_authenticate_url( $parsed_url, $request_args ) ) {
		return $preempt;
	}

	if ( isset( $parsed_url['query'] ) ) {
		parse_str( $parsed_url['query'], $request_query_params );
	} else {
		$request_query_params = array();
	}

	$has_authentication_params = isset( $request_args['headers']['Authorization'] );

	if ( ! $has_authentication_params ) {
		$request_args['headers']['Authorization'] = get_authorization_header();

		$preempt = wp_remote_get( $request_url, $request_args );
	}

	return $preempt;
}

/**
 * Determine if the given URL should have authentication credentials added to it.
 *
 * SECURITY: Make sure we're only authorizing the requests we're intending to, to avoid the possibility of
 * the keys being used for another purpose. That's not likely, but it's better to err on the side of caution.
 *
 * @param array $request_url_parts
 * @param array $request_args
 *
 * @return bool
 */
function should_authenticate_url( $request_url_parts, $request_args ) {
	$authenticate = true;

	if ( ! isset( $request_url_parts['host'], $request_args['method'], $request_url_parts['path'] ) ) {
		return false;
	}

	if ( API_HOSTNAME !== $request_url_parts['host'] || 'GET' !== $request_args['method'] ) {
		$authenticate = false;
	}

	if ( '/repos' !== substr( $request_url_parts['path'], 0, 6 ) ) {
		$authenticate = false;
	}

	if ( '.css' !== substr( $request_url_parts['path'], strlen( $request_url_parts['path'] ) - 4 ) ) {
		$authenticate = false;
	}

	return $authenticate;
}

/**
 * Decode the file contents from GitHub's API response
 *
 * @param string $response_body
 * @param string $remote_css_url
 *
 * @return string
 */
function decode_api_response( $response_body, $remote_css_url ) {
	if ( false !== strpos( $remote_css_url, API_HOSTNAME ) ) {
		$response_body = json_decode( $response_body );
		$response_body = base64_decode( $response_body->content );
	}

	return $response_body;
}

/**
 * Fetch an Authorization token for an authenticated Github API request.
 */
function get_authorization_header() {
	$token = get_app_install_token();
	// Upon failure, just return an empty header, as GitHub will accept that at the lower rate limit temporarily.
	return $token ? 'BEARER ' . $token : '';
}

/**
 * Fetch an App Authorization token for accessing Github Resources.
 */
function get_app_install_token() {
	$token = get_site_transient( __NAMESPACE__ . 'app_install_token' );
	if ( $token ) {
		return $token;
	}

	$jwt_token = get_jwt_app_token();
	if ( ! $jwt_token ) {
		return false;
	}

	$installs = wp_remote_get(
		'https://' . API_HOSTNAME . '/app/installations',
		array(
			'user-agent' => USER_AGENT,
			'headers'    => array(
				'Accept'        => 'application/vnd.github.machine-man-preview+json',
				'Authorization' => 'BEARER ' . $jwt_token,
			),
		)
	);

	$installs = is_wp_error( $installs ) ? false : json_decode( wp_remote_retrieve_body( $installs ) );

	if ( ! $installs || empty( $installs[0]->access_tokens_url ) ) {
		return false;
	}

	$access_token = wp_remote_post(
		$installs[0]->access_tokens_url,
		array(
			'user-agent' => USER_AGENT,
			'headers'    => array(
				'Accept'        => 'application/vnd.github.machine-man-preview+json',
				'Authorization' => 'BEARER ' . $jwt_token,
			),
		)
	);

	$access_token = is_wp_error( $access_token ) ? false : json_decode( wp_remote_retrieve_body( $access_token ) );
	if ( ! $access_token || empty( $access_token->token ) ) {
		return false;
	}

	$token     = $access_token->token;
	$token_exp = strtotime( $access_token->expires_at );

	// Cache the token for 1 minute less than what it's valid for.
	set_site_transient( __NAMESPACE__ . 'app_install_token', $token, $token_exp - time() - MINUTE_IN_SECONDS );

	return $token;
}

/**
 * Generate a JWT Authorization token for the Github /app API endpoints.
 */
function get_jwt_app_token() {
	$token = get_site_transient( __NAMESPACE__ . 'app_token' );
	if ( $token ) {
		return $token;
	}

	// Verify the configuration variables are available.
	if ( ! defined( 'REMOTE_CSS_GITHUB_APP_ID' ) || ! defined( 'REMOTE_CSS_GITHUB_APP_PRIV_KEY' ) ) {
		return false;
	}

	$key = openssl_pkey_get_private( base64_decode( REMOTE_CSS_GITHUB_APP_PRIV_KEY ) );
	$jwt = new \Ahc\Jwt\JWT( $key, 'RS256' );

	$token = $jwt->encode( array(
		'iat' => time(),
		'exp' => time() + 10 * MINUTE_IN_SECONDS,
		'iss' => REMOTE_CSS_GITHUB_APP_ID,
	) );

	// Cache it for 9 mins (It's valid for 10min).
	set_site_transient( __NAMESPACE__ . 'app_token', $token, 9 * MINUTE_IN_SECONDS );

	return $token;
}
