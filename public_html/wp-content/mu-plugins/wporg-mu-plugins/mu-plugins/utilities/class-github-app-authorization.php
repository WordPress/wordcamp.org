<?php
namespace WordPressdotorg\MU_Plugins\Utilities;
use Exception;

/**
 * GitHub Authorize-as-App.
 *
 * @package WordPressdotorg\MU_Plugins\Utilities
 */
class Github_App_Authorization {

	/**
	 * How long to request a token for.
	 *
	 * @var int
	 */
	public $expiry = 600; // 10 minutes.

	protected $app_id      = '';
	protected $key         = '';
	protected $user_agent  = '';

	public function __construct( $app_id, $key, $user_agent = '' ) {
		$this->app_id     = (int) $app_id;
		$this->key        = $key;
		$this->user_agent = $user_agent ?: "WordPress.org GitHub App {$this->app_id}; (+https://wordpress.org/)";
	}

	/**
	 * Wrapper for wp_remote_request() which uses this apps authorization.
	 *
	 * NOTE: Some customizations are available for the $url.
	 *  - May skip including the api.github.com prefix, just the path is needed.
	 *  - May use {ORG} within the URL which will be replaced with the authorized Organization.
	 *
	 * @see wp_remote_get() for paramters.
	 */
	public function request( $url, $args = [] ) {
		$args['headers'] ??= [];
		$args['headers']['Authorization'] = $this->get_authorization_header();

		if ( ! str_starts_with( $url, 'https://' ) ) {
			$url = 'https://api.github.com/' . ltrim( $url, '/' );
		}

		// Support some dynamic rewrites of the URL, to avoid hard-coding of the account names.
		// ie. /orgs/{ORG}/.. => /orgs/WordPress/..
		$url = str_replace( '{ORG}', $this->get_authorized_account(), $url );

		// Validate that the URL is expected.
		if ( 'api.github.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			// If the URL is not to the GitHub API, then we don't need to do anything else.
			return new WP_Error( 'not_allowed', 'Only requests to the GitHub API are allowed.' );
		}

		return wp_remote_request( $url, $args );
	}

	/**
	 * Get the `Authorization: ...` header value for the app.
	 */
	public function get_authorization_header() {
		$details = $this->get_app_install_token_details();

		// Upon failure, just return an empty header, as GitHub will accept that at the lower rate limit temporarily.
		return $details ? 'BEARER ' . $details['token'] : '';
	}

	/**
	 * Fetch the Organization it's authorized as.
	 *
	 * NOTE: We only support authorizing against a singular org/account here.
	 */
	public function get_authorized_account() {
		return $this->get_app_install_token_details()['account'] ?? false;
	}

	/**
	 * Fetch an App Authorization token for accessing Github Resources.
	 */
	protected function get_app_install_token_details() {
		$transient_name = __CLASS__ . ':' . $this->app_id . '_app_install_details';
		$details        = get_site_transient( $transient_name );
		if ( $details ) {
			return $details;
		}

		$jwt_token = $this->get_jwt_app_token();
		if ( ! $jwt_token ) {
			return false;
		}

		$installs = wp_remote_get(
			'https://api.github.com/app/installations',
			array(
				'user-agent' => $this->user_agent,
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
				'user-agent' => $this->user_agent,
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

		$details = [
			'token'   => $token,
			'account' => $installs[0]->account->login,
		];

		// Cache the details for 1 minute less than what it's valid for.
		set_site_transient( $transient_name, $details, $token_exp - time() - MINUTE_IN_SECONDS );

		return $details;
	}

	/**
	 * Generate a JWT Authorization token for the Github /app API endpoints.
	 */
	protected function get_jwt_app_token() {
		$transient_name = __CLASS__ . ':' . $this->app_id . '_app_token';
		$token          = get_site_transient( $transient_name );
		if ( $token ) {
			return $token;
		}

		$key = defined( $this->key ) ? constant( $this->key ) : $this->key;
		if ( ! str_contains( $key, 'BEGIN RSA PRIVATE KEY' ) ) {
			$key = base64_decode( $key );
		}

		try {
			$jwt = new \Ahc\Jwt\JWT( openssl_pkey_get_private( $key ), 'RS256' );
		} catch( Exception $e ) {
			return false;
		}

		$token = $jwt->encode( array(
			'iat' => time(),
			'exp' => time() + $this->expiry,
			'iss' => $this->app_id,
		) );

		// Cache it for 1 minute less than the expiry.
		set_site_transient( $transient_name, $token, $this->expiry - MINUTE_IN_SECONDS );

		return $token;
	}
}
