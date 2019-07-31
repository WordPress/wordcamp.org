<?php
namespace WordCamp\Utilities;

use WP_Error;

defined( 'WPINC' ) || die();

/**
 * Class Meetup_OAuth2_Client
 *
 * Implements the "Server Flow with User Credentials" for Meetup's OAuth2 authorization scheme.
 *
 * @see https://www.meetup.com/meetup_api/auth/#oauth2servercredentials
 *
 * @todo Maybe make some of the credential strings like consumer key, etc, class parameters that map to private
 *       properties instead of constants. That way the client could be reused elsewhere in the Dotorg ecosystem with
 *       different credentials, instead of being tightly coupled to WordCamp.
 *
 * @package WordCamp\Utilities
 */
class Meetup_OAuth2_Client extends API_Client {
	/**
	 * @var string
	 */
	const CONSUMER_KEY = MEETUP_OAUTH_CONSUMER_KEY;

	/**
	 * @var string
	 */
	const CONSUMER_SECRET = MEETUP_OAUTH_CONSUMER_SECRET;

	/**
	 * @var string
	 */
	const REDIRECT_URI = MEETUP_OAUTH_CONSUMER_REDIRECT_URI;

	/**
	 * @var string
	 */
	const EMAIL = MEETUP_USER_EMAIL;

	/**
	 * @var string
	 */
	const PASSWORD = MEETUP_USER_PASSWORD;

	/**
	 * @var string
	 */
	const URL_AUTHORIZE = 'https://secure.meetup.com/oauth2/authorize';

	/**
	 * @var string
	 */
	const URL_ACCESS_TOKEN = 'https://secure.meetup.com/oauth2/access';

	/**
	 * @var string
	 */
	const URL_OAUTH_TOKEN = 'https://api.meetup.com/sessions';

	/**
	 * @var string
	 */
	const SITE_OPTION_KEY_ACCESS = 'meetup_access_token';

	/**
	 * @var string
	 */
	const SITE_OPTION_KEY_OAUTH = 'meetup_oauth_token';

	/**
	 * @var array
	 */
	protected $oauth_token = array();

	/**
	 * Meetup_OAuth2_Client constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			/**
			 * Response codes that should break the request loop.
			 *
			 * Meetup's Oauth2 documentation doesn't provide a schema for error codes, so for the time being, we're
			 * using the same ones as for the Meetup Client itself.
			 * See https://www.meetup.com/meetup_api/docs/#errors.
			 *
			 * `200` (ok) is not in the list, because it needs to be handled conditionally.
			 *  See API_Client::tenacious_remote_request.
			 *
			 * `400` (bad request) is not in the list, even though it seems like it _should_ indicate an unrecoverable
			 * error. In practice we've observed that it's common for a seemingly valid request to be rejected with
			 * a `400` response, but then get a `200` response if that exact same request is retried.
			 */
			'breaking_response_codes' => array(
				401, // Unauthorized (invalid key).
				429, // Too many requests (rate-limited).
				404, // Unable to find group
			),
		) );
	}

	/**
	 * Generate the headers for a request.
	 *
	 * @param string $access_token Optional. Providing an access token will add an extra header.
	 *
	 * @return array
	 */
	protected function get_headers( $access_token = '' ) {
		$headers = array(
			'Accept' => 'application/json',
		);

		if ( $access_token ) {
			$headers['Authorization'] = "Bearer $access_token";
		}

		return $headers;
	}

	/**
	 * Step 1 in "Server Flow with User Credentials".
	 *
	 * @see https://www.meetup.com/meetup_api/auth/#oauth2servercredentials-auth
	 *
	 * @return string
	 */
	protected function request_authorization_code() {
		$authorization_code = '';

		$request = array(
			'client_id'     => self::CONSUMER_KEY,
			'redirect_uri'  => self::REDIRECT_URI,
			'response_type' => 'anonymous_code',
			'scope'         => 'ageless',
		);

		$args = array(
			'headers' => $this->get_headers(),
			'body'    => $request,
		);

		$response = $this->tenacious_remote_post( self::URL_AUTHORIZE, $args );

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! empty( $body['code'] ) ) {
				$authorization_code = $body['code'];
			} else {
				$this->error->add(
					'unexpected_oauth_authorization_code_response',
					'The Meetup OAuth API response did not provide the expected data.'
				);
			}
		} else {
			$this->handle_error_response( $response );
		}

		return $authorization_code;
	}

	/**
	 * Step 2 in "Server Flow with User Credentials".
	 *
	 * @see https://www.meetup.com/meetup_api/auth/#oauth2servercredentials-access
	 *
	 * Also the step for refreshing an expired access token.
	 *
	 * @see https://www.meetup.com/meetup_api/auth/#oauth2server-refresh
	 *
	 * Note that this does not store the access token. See get_access_token().
	 *
	 * @param string $type The type of grant. Either 'anonymous_code' or 'refresh_token'.
	 * @param string $code The code/token used with the grant.
	 *
	 * @return array A successfully retrieved token will be an associative array with several keys.
	 */
	protected function request_access_token( $type, $code ) {
		$access_token = array();

		$request = array(
			'client_id'     => self::CONSUMER_KEY,
			'client_secret' => self::CONSUMER_SECRET,
			'redirect_uri'  => self::REDIRECT_URI,
			'grant_type'    => $type,
		);

		// Add the code to the request payload using the correct parameter for the grant type.
		switch( $type ) {
			case 'anonymous_code': // Request a new access token.
				$request['code'] = $code;
				break;
			case 'refresh_token': // Refresh an expired access token.
			default:
				$request[ $type ] = $code;
				break;
		}

		$args = array(
			'headers' => $this->get_headers(),
			'body'    => $request,
		);

		$response = $this->tenacious_remote_post( self::URL_ACCESS_TOKEN, $args );

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $this->is_valid_token( $body, 'access' ) ) {
				$access_token = $body;

				$access_token['timestamp'] = time();
			} else {
				$this->error->add(
					// Don't include the entire response in the error data, because it might include sensitive
					// data from the request payload.
					'unexpected_oauth_access_token_response',
					'The Meetup OAuth API response did not provide the expected data.'
				);
			}
		} else {
			$this->handle_error_response( $response );
		}

		return $access_token;
	}

	/**
	 * Get the current access token, either from the database or from a request.
	 *
	 * This stores a successfully retrieved token array to the database for repeated use, until it expires.
	 *
	 * @todo So far, I haven't been able to get this type of token to work again after it is used once to retrieve an
	 *       oauth token in Step 3. The documentation makes it sound like this token should be good for up to two
	 *       weeks (if the 'ageless' scope is set) so I'm not sure what's going wrong. In the mean time, the caching
	 *       for this token is commented out. A new token will always be retrieved.
	 *
	 * @return string
	 */
	protected function get_access_token() {
		$access_token  = '';
		$needs_caching = false;

		//$token = get_site_option( self::SITE_OPTION_KEY_ACCESS, array() );
		$token = array();

		if ( ! $this->is_valid_token( $token, 'access' ) ) {
			$token         = $this->request_access_token( 'anonymous_code', $this->request_authorization_code() );
			$needs_caching = true;
		} elseif ( $this->is_valid_token( $token, 'access' ) && $this->is_expired_token( $token ) ) {
			$token         = $this->request_access_token( 'refresh_token', $token['refresh_token'] );
			$needs_caching = true;
		}

		if ( $this->is_valid_token( $token, 'access' ) ) {
			$access_token = $token['access_token'];

			if ( $needs_caching ) {
				//update_site_option( self::SITE_OPTION_KEY_ACCESS, $token );
			}
		}

		return $access_token;
	}

	/**
	 * Step 3 in "Server Flow with User Credentials".
	 *
	 * Technically in the documentation, this token is also called an "access token", but here we're calling it the
	 * "oauth token" to differentiate it from the other access token. Also, why are two separate access tokens
	 * necessary??
	 *
	 * @see https://www.meetup.com/meetup_api/auth/#oauth2servercredentials-accesspro
	 *
	 * Note that this does not store the oauth token. See get_oauth_token().
	 *
	 * @return array A successfully retrieved token will be an associative array with several keys.
	 */
	protected function request_oauth_token() {
		$oauth_token = array();

		$access_token = $this->get_access_token();

		if ( $access_token ) {
			$request = array(
				'email'    => self::EMAIL,
				'password' => self::PASSWORD,
				'scope'    => 'ageless',
			);

			$args = array(
				'headers' => $this->get_headers( $access_token ),
				'body'    => $request,
			);

			// @todo There aren't separate request "types" here because there's no documentation for refreshing the
			//       oauth token, even though the token object it sends includes a `refresh_token` string.

			$response = $this->tenacious_remote_post( self::URL_OAUTH_TOKEN, $args );

			if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( $this->is_valid_token( $body, 'oauth' ) ) {
					$oauth_token = $body;

					$oauth_token['timestamp'] = time();
				} else {
					$this->error->add(
						// Don't include the entire response in the error data, because it might include sensitive
						// data from the request payload.
						'unexpected_oauth_token_response',
						'The Meetup OAuth API response did not provide the expected data.'
					);
				}
			} else {
				$this->handle_error_response( $response );
			}
		} else {
			$this->error->add(
				'no_access_token',
				'Could not retrieve a valid Meetup OAuth access token.'
			);
		}

		return $oauth_token;
	}

	/**
	 * Get the token needed to make requests to the Meetup API.
	 *
	 * This encompasses all three of the steps in the "Server Flow with User Credentials" flow, so it's the only
	 * method that should be called directly.
	 *
	 * This stores a successfully retrieved token array to the database for repeated use, until it expires.
	 *
	 * @return string
	 */
	public function get_oauth_token() {
		if ( $this->oauth_token ) {
			return $this->oauth_token['oauth_token'];
		}

		$oauth_token   = '';
		$needs_caching = false;

		$token = get_site_option( self::SITE_OPTION_KEY_OAUTH, array() );

		if ( ! $this->is_valid_token( $token, 'oauth' ) || $this->is_expired_token( $token ) ) {
			$token         = $this->request_oauth_token();
			$needs_caching = true;
		}

		if ( $this->is_valid_token( $token, 'oauth' ) ) {
			$this->oauth_token = $token;

			if ( $needs_caching ) {
				update_site_option( self::SITE_OPTION_KEY_OAUTH, $token );
			}

			$oauth_token = $this->oauth_token['oauth_token'];
		}

		return $oauth_token;
	}

	/**
	 * Check if a token array has all the required keys.
	 *
	 * @param array  $token The token array to check.
	 * @param string $type  The type of token. Either 'access' or 'oauth'.
	 *
	 * @return bool
	 */
	protected function is_valid_token( $token, $type ) {
		$valid_types = array( 'access', 'oauth' );

		if ( ! is_array( $token ) || ! in_array( $type, $valid_types, true ) ) {
			return false;
		}

		switch ( $type ) {
			case 'access':
				$required_properties = array(
					'access_token'  => '',
					'refresh_token' => '',
					'expires_in'    => '',
				);
				break;
			case 'oauth':
			default:
				$required_properties = array(
					'oauth_token'   => '',
					'refresh_token' => '',
					'expires_in'    => '',
				);
				break;
		}

		$missing_properties = array_diff_key( $required_properties, $token );

		return empty( $missing_properties );
	}

	/**
	 * Check if a token has expired since it was retrieved from the OAuth API.
	 *
	 * @param array $token
	 *
	 * @return bool
	 */
	protected function is_expired_token( array $token ) {
		return absint( $token['timestamp'] ) + absint( $token['expires_in'] ) <= time();
	}

	/**
	 * Extract error information from an API response and add it to our error handler.
	 *
	 * Make sure you don't include the full $response in the error as data, as that could expose sensitive information
	 * from the request payload.
	 *
	 * @param array|WP_Error $response
	 *
	 * @return void
	 */
	public function handle_error_response( $response ) {
		if ( parent::handle_error_response( $response ) ) {
			return;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$data          = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['errors'] ) ) {
			foreach ( $data['errors'] as $error ) {
				$this->error->add(
					$error['code'],
					$error['message']
				);
			}
		} elseif ( isset( $data['error'] ) ) {
			$this->error->add(
				'oauth_error',
				$data['error']
			);
		} elseif ( isset( $data['code'] ) && isset( $data['details'] ) ) {
			$this->error->add(
				$data['code'],
				$data['details']
			);
		} elseif ( $response_code ) {
			$this->error->add(
				'http_response_code',
				sprintf( 'HTTP Status: %d', absint( $response_code ) )
			);
		} else {
			$this->error->add(
				'unknown_error',
				'There was an unknown error.'
			);
		}
	}
}
