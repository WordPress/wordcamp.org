<?php
namespace WordCamp\QuickBooks;

use QuickBooksOnline\API\DataService\DataService;
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
	const OAUTH_REDIRECT_URI = 'https://wordcamp.org/wp-admin/network/settings.php?page=quickbooks';

	/**
	 * @var WP_Error|null
	 */
	public $error = null;

	/**
	 * @var DataService|null
	 */
	public $data_service = null; // TODO make this protected.

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

		/**
		 * Filter: Add configuration values for the QBO V3 PHP SDK.
		 *
		 * @param array $config {
		 *     See https://intuit.github.io/QuickBooks-V3-PHP-SDK/configuration.html
		 *
		 *     @type string $auth_mode
		 *     @type string $ClientID
		 *     @type string $ClientSecret
		 *     @type string RedirectURI
		 *     @type string $scope
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
				'ClientID'     => '',
				'ClientSecret' => '',
				'RedirectURI'  => self::OAUTH_REDIRECT_URI,
				'scope'        => 'com.intuit.quickbooks.accounting',
				'baseUrl'      => 'Development',
			)
		);

		try {
			$this->data_service = DataService::Configure( $config );
		} catch ( SdkException $e ) {
			$this->error->add( $e->getCode(), $e->getMessage() );
		}

		if ( isset( $config['refreshTokenKey'] ) ) {
			try {
				$this->data_service->getOAuth2LoginHelper()->refreshToken();
			} catch ( ServiceException $e ) {
				$this->error->add( $e->getCode(), $e->getMessage() );
			}
		}
	}

	/**
	 * Have any errors been generated?
	 *
	 * @return bool
	 */
	public function has_error() {
		return ! empty( $this->error->get_error_messages() );
	}

	/**
	 * Tests whether the current token works.
	 *
	 * @return bool
	 */
	public function has_valid_token() {
		// Test if the token works by attempting to retrieve token info not stored in the local database.
		try {
			$token = $this->data_service->getOAuth2LoginHelper()->getAccessToken()->getAccessTokenExpiresAt();
		} catch ( SdkException $e ) {
			return false;
		}

		return true;
	}

	/**
	 * The Intuit URL where the OAuth connection happens.
	 *
	 * @return string
	 */
	public function get_authorize_url() {
		return $this->data_service->getOAuth2LoginHelper()->getAuthorizationCodeURL();
	}

	/**
	 * The access token data used to make requests to the API.
	 *
	 * Once a user has authorized a connection on the Intuit site, they are redirected back to our page, along with
	 * an authorization code and realm ID. These are then sent back to the OAuth server to exchange for the actual
	 * access token. See WordCamp\QuickBooks\Admin\maybe_request_token().
	 *
	 * This function returns the three parts of the access token object that we need to store in the database, in an
	 * array format that can feed directly into the `save_oauth_token` function.
	 *
	 * @param string $code
	 * @param string $realm_id
	 *
	 * @return array|WP_Error
	 */
	public function exchange_code_for_token( $code, $realm_id ) {
		try {
			$token = $this->data_service->getOAuth2LoginHelper()->exchangeAuthorizationCodeForToken( $code, $realm_id );

			$this->data_service->updateOAuth2Token( $token );

			return array(
				$token->getAccessToken(),
				$token->getRefreshToken(),
				$token->getRealmID(),
			);
		} catch ( SdkException | ServiceException $e ) {
			$this->error->add( $e->getCode(), $e->getMessage() );

			return $this->error;
		}
	}

	/**
	 * The name of the company entity in QuickBooks that the OAuth connection is linked to.
	 *
	 * @return string
	 */
	public function get_company_name() {
		try {
			$info = $this->data_service->getCompanyInfo();

			return $info->CompanyName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} catch ( SdkException $e ) {
			return sprintf(
				'<code>%s</code>',
				$e->getCode()
			);
		}
	}

	/**
	 * How long until the OAuth connection will need to be re-authorized?
	 *
	 * @return string
	 */
	public function get_refresh_token_expiration() {
		try {
			$token   = $this->data_service->getOAuth2LoginHelper()->getAccessToken();
			$expires = strtotime( $token->getRefreshTokenExpiresAt() );

			return human_time_diff( time(), $expires );
		} catch ( SdkException $e ) {
			return sprintf(
				'<code>%s</code>',
				$e->getCode()
			);
		}
	}

	/**
	 * Revoke the current valid token. The OAuth connection will have to be re-established.
	 *
	 * @return bool|WP_Error
	 */
	public function revoke_token() {
		try {
			$token = $this->data_service->getOAuth2LoginHelper()->getAccessToken();

			return $this->data_service->getOAuth2LoginHelper()->revokeToken( $token->getRefreshToken() );
		} catch ( SdkException $e ) {
			$this->error->add( $e->getCode(), $e->getMessage() );

			return $this->error;
		}
	}
}
