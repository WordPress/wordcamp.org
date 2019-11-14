<?php
namespace WordCamp\QuickBooks;

use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\SdkException;
use WP_Error;

defined( 'WPINC' ) || die();


class Client {

	public $error = null;


	public $data_service = null; // TODO make this protected.


	protected $login_helper = null;


	public function __construct() {
		$this->error = new WP_Error();

		if ( ! class_exists( '\QuickBooksOnline\API\DataService\DataService' ) ) {
			$this->error->add(
				'missing_dependency',
				'The required library <code>QuickBooks V3 PHP SDK</code> is unavailable.'
			);

			return;
		}

		$config = apply_filters( 'wordcamp_qbo_client_config', array(
			'auth_mode'       => 'oauth2',
			'ClientID'        => '',
			'ClientSecret'    => '',
			'RedirectURI'     => 'https://wordcamp.org/wp-admin/network/settings.php?page=quickbooks', // Hardcoded to match the app.
			'accessTokenKey'  => '',
			'refreshTokenKey' => '',
			'QBORealmID'      => '',
			'scope'           => 'com.intuit.quickbooks.accounting',
			'baseUrl'         => 'Development',
		) );

		try {
			$this->data_service = DataService::Configure( $config );
		} catch ( SdkException $e ) {
			$this->error->add(
				$e->getCode(),
				$e->getMessage()
			);
		}
	}


	public function has_error() {
		return ! empty( $this->error->get_error_messages() );
	}



}
