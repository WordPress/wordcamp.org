<?php
namespace WordCamp\QuickBooks;

use Exception;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2AccessToken;
use QuickBooksOnline\API\Exception\SdkException;
use QuickBooksOnline\API\Exception\ServiceException;
use WP_Error;

defined( 'WPINC' ) || die();

/**
 * Class Client
 *
 * This acts as a wrapper around the QBO V3 PHP SDK, so it can catch exceptions and handle errors correctly.
 *
 * @package WordCamp\QuickBooks
 */
class Client {
	/**
	 * The URI that QBO will redirect to after an OAuth user authorization.
	 *
	 * This is hardcoded here since it has to be hardcoded in the QBO app.
	 */
	const OAUTH_REDIRECT_URI = 'https://wordcamp.org/wp-admin/admin-post.php?action=wordcamp-qbo-oauth&cmd=exchange';

	/**
	 * @var WP_Error|null
	 */
	public $error = null;

	/**
	 * @var DataService|null
	 */
	protected $data_service = null;

	/**
	 * Client constructor.
	 */
	public function __construct() {
		$this->error = new WP_Error();

		if ( ! class_exists( '\QuickBooksOnline\API\DataService\DataService' ) ) {
			$this->error->add(
				'missing_dependency',
				'The required library <code>QuickBooks V3 PHP SDK</code> is unavailable.'
			);

			return;
		}

		$config = $this->get_client_config();

		if ( empty( $config['ClientID'] ) || empty( $config['ClientSecret'] ) ) {
			$this->error->add(
				'missing_credentials',
				'The required credentials for connecting to the QBO API are unavailable.'
			);

			return;
		}

		try {
			$this->data_service = DataService::Configure( $config );
		} catch ( SdkException $exception ) {
			$this->add_error_from_exception( $exception );
		}

		if ( isset( $config['refreshTokenKey'] ) ) {
			try {
				/**
				 * The OAuth access token expires after an hour, so whenever we instantiate with saved token data, we
				 * need to refresh it with the refresh token to get a current access token. According to the SDK docs,
				 * the refresh token may change when this happens, so it needs to be re-saved during this process.
				 *
				 * See https://intuit.github.io/QuickBooks-V3-PHP-SDK/authorization.html#oauth-2-0-vs-1-0a-in-quickbooks-online
				 */
				$token = $this->data_service->getOAuth2LoginHelper()->refreshToken();
				self::save_oauth_token_data( $token );
			} catch ( SdkException | ServiceException $exception ) {
				$this->add_error_from_exception( $exception );

				if ( $this->error->get_error_messages( 'invalid_grant' ) ) {
					$this->error->add(
						'broken_oauth_connection',
						'The connection to QuickBooks has failed. Please try reconnecting.',
						$this->error->get_error_messages( 'invalid_grant' )
					);

					$this->error->remove( 'invalid_grant' );

					// The bad token data needs to be removed or the SDK will throw an exception when trying to reconnect.
					self::delete_oauth_token_data();
				}
			}
		}
	}

	/**
	 * Does the client have access to the SDK?
	 *
	 * @return bool
	 */
	public function has_sdk() {
		return $this->data_service instanceof DataService;
	}

	/**
	 * Configuration parameters for the SDK's DataService class.
	 *
	 * @return array
	 */
	protected function get_client_config() {
		/**
		 * Filter: Add configuration values for the QBO V3 PHP SDK.
		 *
		 * @param array $config {
		 *     See https://intuit.github.io/QuickBooks-V3-PHP-SDK/configuration.html
		 *
		 *     @type string $auth_mode
		 *     @type string RedirectURI
		 *     @type string $scope
		 *     @type string $ClientID
		 *     @type string $ClientSecret
		 *     @type string $baseUrl
		 *     @type string $accessTokenKey  This array key should only be added when the value is available.
		 *     @type string $refreshTokenKey This array key should only be added when the value is available.
		 *     @type string $QBORealmID      This array key should only be added when the value is available.
		 * }
		 */
		$config = apply_filters(
			'wordcamp_qbo_client_config',
			array(
				'auth_mode'    => 'oauth2',
				'RedirectURI'  => self::OAUTH_REDIRECT_URI,
				'scope'        => 'com.intuit.quickbooks.accounting',
				'ClientID'     => '',
				'ClientSecret' => '',
				'baseUrl'      => 'Development',
			)
		);

		return array_merge( $config, self::get_oauth_token_data() );
	}

	/**
	 * The option key for storing OAuth token data.
	 *
	 * Note that this uses the current WordCamp environment so that separate OAuth tokens can be generated and stored
	 * for development and production environments.
	 *
	 * @return string
	 */
	protected static function generate_oauth_option_key() {
		$environment = ( defined( 'WORDCAMP_ENVIRONMENT' ) ) ? WORDCAMP_ENVIRONMENT : 'development';

		return PLUGIN_PREFIX . '_oauth_' . $environment;
	}

	/**
	 * Retrieve stored OAuth token data from the database. Defaults to an empty array.
	 *
	 * @return array
	 */
	protected static function get_oauth_token_data() {
		$key = self::generate_oauth_option_key();

		return get_site_option( $key, array() );
	}

	/**
	 * Extract necessary data from an OAuth token object and store it to the database.
	 *
	 * @param OAuth2AccessToken $token
	 *
	 * @return bool
	 * @throws SdkException
	 */
	protected static function save_oauth_token_data( OAuth2AccessToken $token ) {
		$data = array(
			'accessTokenKey'  => $token->getAccessToken(),
			'refreshTokenKey' => $token->getRefreshToken(),
			'QBORealmID'      => $token->getRealmID(),
		);

		$key = self::generate_oauth_option_key();

		return update_site_option( $key, $data );
	}

	/**
	 * Remove stored OAuth token data from the database.
	 *
	 * This is public and static in case the stored data needs to be deleted externally as a reset.
	 *
	 * @return bool
	 */
	public static function delete_oauth_token_data() {
		$key = self::generate_oauth_option_key();

		return delete_site_option( $key );
	}

	/**
	 * Shortcut for importing an Exception into the client's WP_Error instance.
	 *
	 * @param Exception $exception
	 *
	 * @return void
	 */
	protected function add_error_from_exception( Exception $exception ) {
		$error_code    = $exception->getCode();
		$error_message = $exception->getMessage();

		if ( ! $error_code || is_numeric( $error_code ) ) {
			// Try to parse a useful error code from the message. Sometimes there is raw JSON included.
			if ( preg_match( '#Body: (\[[^\]]+\])#', $error_message, $matches ) ) {
				$body = json_decode( $matches[1], true );

				if ( isset( $body[0]['error'] ) ) {
					$error_code = $body[0]['error'];
				}
			}
		}

		$this->error->add( $error_code, $error_message );
	}

	/**
	 * Have any errors been generated?
	 *
	 * @return bool
	 */
	public function has_error() {
		return ! empty( $this->error->has_errors() );
	}

	/**
	 * Get the current token object.
	 *
	 * @return OAuth2AccessToken
	 * @throws Exception|SdkException
	 */
	protected function get_current_token() {
		if ( ! $this->has_sdk() ) {
			throw new Exception( "Can't get OAuth 2 Access Token Object. The SDK is not available." );
		}

		return $this->data_service->getOAuth2LoginHelper()->getAccessToken();
	}

	/**
	 * Tests whether the current token works.
	 *
	 * @return bool
	 */
	public function has_valid_token() {
		if ( ! $this->has_sdk() ) {
			return false;
		}

		// Test if the token works by attempting to retrieve token info not stored in the local database.
		try {
			// The `getAccessTokenExpiresAt` doc block says it returns a Date object, but it's actually a
			// formatted date string.
			$date_string      = $this->get_current_token()->getAccessTokenExpiresAt();
			$expire_timestamp = strtotime( $date_string );

			return time() < $expire_timestamp;
		} catch ( Exception | SdkException $exception ) {
			return false;
		}
	}

	/**
	 * The Intuit URL where the OAuth connection happens.
	 *
	 * @return string
	 */
	public function get_authorize_url() {
		if ( ! $this->has_sdk() ) {
			return '';
		}

		return $this->data_service->getOAuth2LoginHelper()->getAuthorizationCodeURL();
	}

	/**
	 * Send authorization data over to QBO and get back an OAuth token.
	 *
	 * Once a user has authorized a connection on the Intuit site, they are redirected back to our page, along with
	 * an authorization code and realm ID. These are then sent back to the OAuth server to exchange for the actual
	 * access token.
	 *
	 * @return void
	 */
	public function maybe_exchange_code_for_token() {
		$authorization_code = filter_input( INPUT_GET, 'code' );
		$realm_id           = filter_input( INPUT_GET, 'realmId' );

		if ( $this->has_sdk() && ! $this->has_valid_token() && $authorization_code && $realm_id ) {
			try {
				$token = $this->data_service->getOAuth2LoginHelper()->exchangeAuthorizationCodeForToken( $authorization_code, $realm_id );

				$this->data_service->updateOAuth2Token( $token );

				self::save_oauth_token_data( $token );
			} catch ( SdkException | ServiceException $exception ) {
				$this->add_error_from_exception( $exception );
			}
		}
	}

	/**
	 * Revoke the current valid token.
	 *
	 * @return void
	 */
	public function revoke_token() {
		if ( ! $this->has_sdk() ) {
			return;
		}

		try {
			$token = $this->data_service->getOAuth2LoginHelper()->getAccessToken();

			$this->data_service->getOAuth2LoginHelper()->revokeToken( $token->getRefreshToken() );

			self::delete_oauth_token_data();
		} catch ( SdkException $exception ) {
			$this->add_error_from_exception( $exception );
		}
	}

	/**
	 * The name of the company entity in QuickBooks that the OAuth connection is linked to.
	 *
	 * @return string
	 */
	public function get_company_name() {
		if ( ! $this->has_sdk() ) {
			return '';
		}

		try {
			$info = $this->data_service->getCompanyInfo();

			return $info->CompanyName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} catch ( SdkException $exception ) {
			return sprintf(
				'<code>SdkException: %s</code>',
				$exception->getCode()
			);
		}
	}

	/**
	 * How long until the OAuth connection will need to be re-authorized?
	 *
	 * @return string
	 */
	public function get_refresh_token_expiration() {
		if ( ! $this->has_sdk() ) {
			return '';
		}

		try {
			// The `getRefreshTokenExpiresAt` doc block says it returns an integer, but it's actually a
			// formatted date string.
			$token   = $this->get_current_token();
			$expires = strtotime( $token->getRefreshTokenExpiresAt() );

			return human_time_diff( time(), $expires );
		} catch ( Exception | SdkException $exception ) {
			return sprintf(
				'<code>SdkException: %s</code>',
				$exception->getCode()
			);
		}
	}
}
