<?php
namespace WordPressdotorg\MU_Plugins\Utilities;

/**
 * Simple HelpScout client.
 *
 * @package WordPressdotorg\MU_Plugins\Utilities
 */
class HelpScout {
	const API_BASE        = 'https://api.helpscout.net';
	const DEFAULT_VERSION = 2;

	/**
	 * The HTTP timeout for the HelpScout API.
	 *
	 * @var int
	 */
	public $timeout = 15;

	public    $name           = '';
	protected $app_id         = '';
	protected $app_secret     = '';
	protected $webhook_secret = '';

	/**
	 * Fetch an instance of the HelpScout API.
	 */
	public static function instance( $app_id = false, $secret = false, $webhook_secret = false ) {
		static $instances = [];

		if ( ! $app_id && ! $secret && ! $webhook_secret ) {
			$app_id = 'wordpress';
		}

		return $instances[ $app_id ] ?? ( $instances[ $app_id ] = new self( $app_id, $secret, $webhook_secret ) );
	}

	protected function __construct( $app_id, $secret = false, $webhook_secret = false ) {
		$name = '';
		if ( 'wordpress' === $app_id && defined( 'HELPSCOUT_APP_ID' ) ) {
			$name           = 'wordpress';
			$app_id         = HELPSCOUT_APP_ID;
			$secret         = HELPSCOUT_APP_SECRET;
			$webhook_secret = HELPSCOUT_WEBHOOK_SECRET_KEY;
		} elseif ( 'foundation' === $app_id && defined( 'HELPSCOUT_FOUNDATION_APP_ID' ) ) {
			$name           = 'foundation';
			$app_id         = HELPSCOUT_FOUNDATION_APP_ID;
			$secret         = HELPSCOUT_FOUNDATION_APP_SECRET;
			$webhook_secret = HELPSCOUT_FOUNDATION_WEBHOOK_SECRET_KEY;
		}

		$this->name           = $name;
		$this->app_id         = $app_id;
		$this->app_secret     = $secret;
		$this->webhook_secret = $webhook_secret;
	}

	/**
	 * Validate whether the webhook payload provided came from Helpscout.
	 *
	 * @param $data      string The raw JSON payload.
	 * @param $signature string The signature provided by Helpscout.
	 * @return bool
	 */
	public function validate_webhook_signature( $data, $signature ) {
		if ( ! $this->webhook_secret || ! $signature ) {
			return false;
		}

		$calculated = base64_encode( hash_hmac( 'sha1', $data, $this->webhook_secret, true ) );

		return hash_equals( $signature, $calculated );
	}

	/**
	 * Retrieve the mailbox ID for an inbox.
	 *
	 * @param string $mailbox The mailbox. Accepts 'plugins', 'data', 'jobs', 'openverse', 'photos', 'themes', etc.
	 * @return int The numeric mailbox ID.
	 */
	public static function get_mailbox_id( $mailbox ) {
		$define = 'HELPSCOUT_' . strtoupper( $mailbox ) . '_MAILBOXID';
		if ( ! defined( $define ) ) {
			return false;
		}

		return constant( $define );
	}

	/**
	 * Retrieve a GET API endpoint.
	 *
	 * @param string $url  API Endpoint.
	 * @param array  $args Optional. The API args.
	 * @return bool|object False on failure, results on success.
	 */
	public function get( $url, $args = null ) {
		return $this->api( $url, $args, 'GET' );
	}

	/**
	 * Retrieve a POST API endpoint.
	 *
	 * @param string $url  API Endpoint.
	 * @param array  $args Optional. The API args.
	 * @return bool|object False on failure, results on success.
	 */
	public function post( $url, $args = null ) {
		return $this->api( $url, $args, 'POST' );
	}

	/**
	 * Retrieve a GET API and recurse pages.
	 *
	 * @param string $url  API Endpoint.
	 * @param array  $args Optional. The API args.
	 * @return bool|object False on failure, results on success.
	 */
	public function get_paged( $url, $args = null ) {
		$api      = $this->get( $url, $args );
		$response = clone $api;

		while ( ! empty( $api->_links->next->href ) ) {
			$api = $this->get( $api->_links->next->href );

			if ( is_array( $api->_embedded ) ) {
				
			} else {
				foreach ( $api->_embedded as $field => $value ) {
					$response->_embedded->$field = array_merge( $response->_embedded->$field, $value );
				}
			}
		}

		unset( $response->page, $response->_links );

		return $response;
	}

	/**
	 * Call a HelpScout API endpoint.
	 *
	 * @param string $url    The API endpoint to request.
	 * @param array  $args   Optional. Any parameters to pass to the API.
	 * @param string $method Optional. The HTTP method for the request. 'GET' or 'POST'. Default 'GET'.
	 * @return bool|object False on failure, results on success.
	 */
	public function api( $url, $args = null, $method = 'GET' ) {
		// Support static calls for back-compat.
		if ( ! isset( $this ) ) {
			return self::instance()->api( $url, $args, $method ) ?? false;
		}

		// Prepend API URL host-less URLs.
		if ( ! str_starts_with( $url, self::API_BASE ) ) {
			// Prepend API version when not specified.
			if ( ! preg_match( '!^/v\d{1}!', $url ) ) {
				$url = '/v' . self::DEFAULT_VERSION . '/' . ltrim( $url, '/' );
			}

			$url = self::API_BASE . '/' . ltrim( $url, '/' );
		}

		// $args passed as GET paramters.
		if ( 'GET' === $method && $args ) {
			$url = add_query_arg( $args, $url );
		}

		$body    = null;
		$headers = [
			'Accept'        => 'application/json',
			'Authorization' => $this->get_auth_string(),
		];

		// Always send POST/PUT/PATCH requests as JSON.
		if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) && $args ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = wp_json_encode( $args );
		}

		$request = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $headers,
				'timeout' => $this->timeout,
				'body'    => $body,
			)
		);

		return json_decode( wp_remote_retrieve_body( $request ) );
	}

	/**
	 * Fetch an Authorization token for accessing HelpScout Resources.
	 */
	protected function get_auth_string() {
		$cache_key = __CLASS__ . $this->app_id . 'get_auth_token';
		$token     = get_site_transient( $cache_key );
		if ( $token && is_array( $token ) && $token['exp'] > time() ) {
			return 'BEARER ' . $token['token'];
		}

		$request = wp_remote_post(
			self::API_BASE . '/v2/oauth2/token',
			array(
				'timeout' => $this->timeout,
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->app_id,
					'client_secret' => $this->app_secret,
				)
			)
		);

		$response = is_wp_error( $request ) ? false : json_decode( wp_remote_retrieve_body( $request ) );

		if ( ! $response || empty( $response->access_token ) ) {
			return false;
		}

		// Cache the token for 1 minute less than what it's valid for.
		$token  = $response->access_token;
		$expiry = $response->expires_in - MINUTE_IN_SECONDS;

		set_site_transient(
			$cache_key,
			[
				'exp' => time() + $expiry,
				'token' => $token
			],
			$expiry
		);

		return 'BEARER ' . $token;
	}

}

