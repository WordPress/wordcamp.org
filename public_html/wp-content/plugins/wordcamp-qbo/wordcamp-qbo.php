<?php
/**
 * Plugin Name: WordCamp.org QBO Integration
 */

// todo use wcorg_redundant_remote_get for all the calls in this file

use WordCamp\Quickbooks;
use WordCamp\Logger;

class WordCamp_QBO {
	const REMOTE_REQUEST_TIMEOUT = 45; // seconds

	private static $app_token;
	private static $consumer_key;
	private static $consumer_secret;
	private static $hmac_key;

	private static $sandbox_mode;
	private static $account;
	private static $api_base_url;
	private static $options;
	private static $categories_map;

	public static function load_options() {
		if ( isset( self::$options ) ) {
			return self::$options;
		}

		self::$options = wp_parse_args( get_option( 'wordcamp-qbo', array() ), array(
			'auth' => array(),
		) );
	}

	/**
	 * Runs immediately.
	 */
	public static function load() {
		add_action( 'plugins_loaded', array( __CLASS__, 'plugins_loaded' ) );
		add_action( 'wcqbo_prime_classes_cache', array( __CLASS__, 'prime_classes_cache' ) );

		if ( ! wp_next_scheduled( 'wcqbo_prime_classes_cache' ) ) {
			wp_schedule_event( time(), 'hourly', 'wcqbo_prime_classes_cache' );
		}
	}

	/**
	 * Runs during plugins_loaded.
	 */
	public static function plugins_loaded() {
		self::$sandbox_mode = WORDCAMP_ENVIRONMENT !== 'production';

		$init_options = wp_parse_args( apply_filters( 'wordcamp_qbo_options', array() ), array(
			'app_token'       => '',
			'consumer_key'    => '',
			'consumer_secret' => '',
			'hmac_key'        => '',

			'categories_map'  => array(),
		) );

		foreach ( $init_options as $key => $value ) {
			self::$$key = $value;
		}

		// There's no point in doing anything if we don't have the secrets.
		if ( empty( self::$consumer_key ) ) {
			return;
		}

		self::$api_base_url = sprintf(
			'https://%squickbooks.api.intuit.com',
			 self::$sandbox_mode ? 'sandbox-' : ''
		);

		self::$account = apply_filters( 'wordcamp_qbo_account', array(
			'value' => '61',
			'name'  => 'Checking-JPM',
		) );

		add_filter( 'rest_api_init', array( __CLASS__, 'rest_api_init' ) );
	}

	/**
	 * Runs during rest_api_init.
	 */
	public static function rest_api_init() {
		register_rest_route(
			'wordcamp-qbo/v1',
			'/expense',
			array(
				'methods'             => 'GET, POST',
				'callback'            => array( __CLASS__, 'rest_callback_expense' ),
				'permission_callback' => array( __CLASS__, 'is_valid_request' ),
			)
		);

		register_rest_route(
			'wordcamp-qbo/v1',
			'/invoice',
			array(
				'methods'             => 'GET, POST',
				'callback'            => array( __CLASS__, 'rest_callback_invoice' ),
				'permission_callback' => array( __CLASS__, 'is_valid_request' ),
			)
		);

		register_rest_route(
			'wordcamp-qbo/v1',
			'/invoice_pdf',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_callback_invoice_pdf' ),
				'permission_callback' => array( __CLASS__, 'is_valid_request' ),
			)
		);

		register_rest_route(
			'wordcamp-qbo/v1',
			'/paid_invoices',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_callback_paid_invoices' ),
				'permission_callback' => array( __CLASS__, 'is_valid_request' ),
			)
		);
	}

	/**
	 * REST: /expense
	 *
	 * @param WP_REST_Request $request
	 */
	public static function rest_callback_expense( $request ) {
		self::load_options();
		$oauth_header = self::qbo_client()->get_oauth_header();
		$realm_id     = self::qbo_client()->get_realm_id();

		$amount = floatval( $request->get_param( 'amount' ) );
		if ( ! $amount ) {
			return new WP_Error( 'error', 'An amount was not given.' );
		}

		$description = $request->get_param( 'description' );
		if ( empty( $description ) ) {
			return new WP_Error( 'error', 'The expense description can not be empty.' );
		}

		$category = $request->get_param( 'category' );
		if ( empty( $category ) || ! array_key_exists( $category, self::$categories_map ) ) {
			return new WP_Error( 'error', 'The category you have picked is invalid.' );
		}

		$date = $request->get_param( 'date' );
		if ( empty( $date ) ) {
			return new WP_Error( 'error', 'The expense date can not be empty.' );
		}

		$date = absint( $date );

		$class = $request->get_param( 'class' );
		if ( empty( $class ) ) {
			return new WP_Error( 'error', 'You need to set a class.' );
		}

		$classes = self::_get_classes();
		if ( ! array_key_exists( $class, $classes ) ) {
			return new WP_Error( 'error', 'Unknown class.' );
		}

		$class = array(
			'value' => $class,
			'name'  => $classes[ $class ],
		);

		$payload = array(
			'AccountRef'  => self::$account,
			'TxnDate'     => gmdate( 'Y-m-d', $date ),
			'PaymentType' => 'Cash',
			'Line'        => array(
				array(
					'Id'                            => 1,
					'Description'                   => $description,
					'Amount'                        => $amount,
					'DetailType'                    => 'AccountBasedExpenseLineDetail',
					'AccountBasedExpenseLineDetail' => array(
						'ClassRef'   => $class,
						'AccountRef' => self::$categories_map[ $category ],
					),
				),
			),
		);

		if ( $request->get_param('id') ) {
			$payload['Id'] = absint( $request->get_param('id') );

			$request_url = esc_url_raw( sprintf(
				'%s/v3/company/%d/purchase/%d',
				self::$api_base_url,
				rawurlencode( $realm_id ),
				$payload['Id']
			) );

			$response = wp_remote_get( $request_url, array(
				'timeout' => self::REMOTE_REQUEST_TIMEOUT,
				'headers' => array(
					'Authorization' => $oauth_header,
					'Accept'        => 'application/json',
				),
			) );
			Logger\log( 'remote_request_sync_token', compact( 'response' ) );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) {
				return new WP_Error( 'error', 'Could not find purchase to update.' );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! isset( $body['Purchase']['SyncToken'] ) ) {
				return new WP_Error( 'error', 'Could not decode purchase for update.' );
			}

			$payload['SyncToken'] = $body['Purchase']['SyncToken'];
			unset( $response );
		}

		$payload     = json_encode( $payload );
		$request_url = esc_url_raw( sprintf(
			'%s/v3/company/%d/purchase',
			self::$api_base_url,
			rawurlencode( $realm_id )
		) );

		$response = wp_remote_post( $request_url, array(
			'timeout' => self::REMOTE_REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => $oauth_header,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			'body'    => $payload,
		) );
		Logger\log( 'remote_request_create_expense', compact( 'payload', 'response' ) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) {
			return new WP_Error( 'error', 'Could not create purchase.' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body ) ) {
			return new WP_Error( 'error', 'Could not decode create purchase result.' );
		}

		return array(
			'transaction_id' => intval( $body['Purchase']['Id'] ),
		);
	}

	/**
	 * Update the cached QBO classes ("communities").
	 *
	 * These are stored in an option rather than a transient, because:
	 *
	 * 1) The user shouldn't have to wait for an external HTTP request to complete before the page loads.
	 * 2) The connection is QBO is not reliable enough. For example, it expires every 180 days, and needs to be
	 *    manually reconnected. When the transient expires during that timeframe, it cannot be renewed until the
	 *    connection is re-established, and any functionality relying on this would be broken.
	 */
	public static function prime_classes_cache() {
		/*
		 * This isn't strictly needed right now, but it's future-proofing for when we eventually remove
		 * `wordcamp-qbo-client` and network-activate `wordcamp-qbo`.
		 */
		if ( ! is_main_site() ) {
			return;
		}

		self::load_options();
		$oauth_header = self::qbo_client()->get_oauth_header();
		$realm_id     = self::qbo_client()->get_realm_id();

		$args = array(
			'query'        => 'SELECT * FROM Class MAXRESULTS 1000',
			'minorversion' => 4,
		);

		$request_url = esc_url_raw( sprintf(
			'%s/v3/company/%d/query',
			self::$api_base_url,
			rawurlencode( $realm_id )
		) );

		$response = wp_remote_get(
			esc_url_raw( add_query_arg( $args, $request_url ) ),
			array(
				'timeout' => self::REMOTE_REQUEST_TIMEOUT,
				'headers' => array(
					'Authorization' => $oauth_header,
					'Accept'        => 'application/json',
				),
			)
		);
		Logger\log( 'remote_request', compact( 'args', 'response' ) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body ) ) {
			return;
		}

		$classes = array();
		foreach ( $body['QueryResponse']['Class'] as $class ) {
			$classes[ $class['Id'] ] = $class['Name'];
		}

		if ( empty ( $class ) ) {
			return;
		}

		asort( $classes );

		update_site_option( 'wordcamp_qbo_classes', $classes );
	}

	/**
	 * REST: /invoice
	 *
	 * Creates a new Invoice in QuickBooks and sends it to the Customer
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return int|WP_Error The invoice ID on success, or a WP_Error on failure
	 */
	public static function rest_callback_invoice( $request ) {
		$invoice_id = self::create_invoice(
			$request->get_param( 'wordcamp_name'     ),
			$request->get_param( 'sponsor'           ),
			$request->get_param( 'currency_code'     ),
			$request->get_param( 'qbo_class_id'      ),
			$request->get_param( 'sponsorship_level' ),
			$request->get_param( 'amount'            ),
			$request->get_param( 'description'       ),
			$request->get_param( 'statement_memo'    )
		);

		if ( is_wp_error( $invoice_id ) ) {
			return $invoice_id;
		}

		$invoice_sent = self::send_invoice( $invoice_id );

		if ( is_wp_error( $invoice_sent ) ) {
			self::notify_invoice_failed_to_send( $invoice_id, $invoice_sent );
		}

		return $invoice_id;
	}

	/**
	 * Creates an Invoice in QuickBooks
	 *
	 * @param string $wordcamp_name
	 * @param array  $sponsor
	 * @param string $currency_code
	 * @param int    $class_id
	 * @param string $sponsorship_level
	 * @param float  $amount
	 * @param string $description
	 * @param string $statement_memo
	 *
	 * @return int|WP_Error Invoice ID on success; error on failure
	 */
	protected static function create_invoice( $wordcamp_name, $sponsor, $currency_code, $class_id, $sponsorship_level, $amount, $description, $statement_memo ) {
		$qbo_request = self::build_qbo_create_invoice_request(
			$wordcamp_name,
			$sponsor,
			$currency_code,
			$class_id,
			$sponsorship_level,
			$amount,
			$description,
			$sponsor['email-address'],
			$statement_memo
		);

		if ( is_wp_error( $qbo_request ) ) {
			return $qbo_request;
		}

		$response = wp_remote_post( $qbo_request['url'], $qbo_request['args'] );
		Logger\log( 'remote_request', compact( 'qbo_request', 'response' ) );

		if ( is_wp_error( $response ) ) {
			$result = $response;
		} elseif ( 200 != wp_remote_retrieve_response_code( $response ) ) {
			$result = new WP_Error( 'invalid_http_code', 'Invalid HTTP response code', $response );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['Invoice']['Id'] ) ) {
				$result = absint( $body['Invoice']['Id'] );
			} else {
				$result = new WP_Error( 'empty_body', 'Could not decode invoice result.', $response );
			}
		}

		return $result;
	}

	/**
	 * Build the request to create an invoice in QuickBooks
	 *
	 * @param string $wordcamp_name
	 * @param array  $sponsor
	 * @param string $currency_code
	 * @param int    $class_id
	 * @param string $sponsorship_level
	 * @param float  $amount
	 * @param string $description
	 * @param string $customer_email
	 * @param string $statement_memo
	 *
	 * @return array|WP_Error
	 */
	protected static function build_qbo_create_invoice_request( $wordcamp_name, $sponsor, $currency_code, $class_id, $sponsorship_level, $amount, $description, $customer_email, $statement_memo ) {
		$customer_id = self::probably_get_customer_id( $sponsor, $currency_code );

		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		$wordcamp_name     = sanitize_text_field( $wordcamp_name     );
		$class_id          = sanitize_text_field( $class_id          );
		$sponsorship_level = sanitize_text_field( $sponsorship_level );
		$amount            = floatval( $amount                       );
		$description       = trim( sanitize_text_field( $description ) );
		$statement_memo    = sanitize_text_field( $statement_memo    );

		$sponsor = array_map( 'sanitize_text_field', $sponsor );

		$line_description = $wordcamp_name;
		if ( $sponsorship_level ) {
			$line_description .= " - $sponsorship_level";
		}

		/*
		 * The currency code only needs to be sanitized, not validated, because QBO will reject the invoice if
		 * an invalid code is passed. We don't have to worry about an invoice being assigned the the home currency
		 * by accident.
		 */
		$currency_code = sanitize_text_field( $currency_code );

		/*
		 * QBO sandboxes will send invoices to whatever e-mail address you assign them, rather than sending them
		 * to the sandbox owner. So to avoid sending sandbox e-mails to real sponsor addresses, we use a fake
		 * address instead.
		 */
		if ( self::$sandbox_mode ) {
			$customer_email = 'jane.doe@example.org';
		} else {
			$customer_email = is_email( $customer_email );
		}

		foreach ( array( 'amount', 'customer_id', 'customer_email' ) as $field ) {
			if ( empty( $$field ) ) {
				return new WP_Error( 'required_field_empty', "$field cannot be empty." );
			}
		}

		self::load_options();
		$oauth_header = self::qbo_client()->get_oauth_header();
		$realm_id     = self::qbo_client()->get_realm_id();

		// Note: This has a character limit when combined with $description; see $customer_memo
		$payment_instructions = trim( str_replace( "\t", '', "
			Please indicate the invoice number in the memo field when making your payment.

			To pay via credit card, please fill out the payment form at https://central.wordcamp.org/sponsorship-payment/
			An additional 2.9% to cover processing fees on credit card payments is highly appreciated but not required.

			For International Wire Transfers:
			Beneficiary Name: WordPress Community Support, PBC
			Banking Address: 132 Hawthorne St, San Francisco, CA 94107-1308, USA
			Bank Name: JPMorgan Chase Bank, N.A.
			Bank Address: 270 Park Ave, New York, NY 10017, USA
			Bank Routing and Transit Number: 021000021
			SWIFT Code: CHASUS33
			Account Number: 157120285

			For ACH/USA Domestic Direct Deposit:
			Bank Routing & Transit Number: 322271627
			Account Number: 157120285

			Please remit checks (USD only) to: WordPress Community Support, PBC, P.O. Box 101768, Pasadena, CA 91189-1768"
		) );

		/*
		 * The API limits CustomerMemo to 1,000 characters. We use 995 to allow for newlines between the two
		 * values and a bit of safety.
		 *
		 * The payment instructions are more important than the description, so the description should be
		 * sacrificed to make room for the complete instructions.
		 */
		$description_limit = abs( 995 - strlen( $payment_instructions ) );
		$customer_memo     = sprintf(
			"%s\n\n%s",
			substr( $description, 0, $description_limit ),
			$payment_instructions
		);

		$payload = array(
			'PrivateNote' => $statement_memo,

			'CustomField' => array(
				// WPCS Tax ID
				array(
					'DefinitionId' => '1',
					'Type'         => 'StringType',
					'StringValue'  => '81-0896291',
				),

				// Sponsor VAT ID
				array(
					'DefinitionId' => '2',
					'Type'         => 'StringType',
					'StringValue'  => $sponsor['vat-number'],
				),
			),

			'Line' => array(
				array(
					'Amount'              => $amount,
					'Description'         => $line_description,
					'DetailType'          => 'SalesItemLineDetail',

					'SalesItemLineDetail' => array(
						'ItemRef'   => array(
							'value' => '20', // Sponsorship
						),

						'ClassRef'  => array(
							'value' => $class_id,
						),

						'UnitPrice' => $amount,
						'Qty'       => 1,
					),
				),
			),

			'CustomerRef'  => array(
				'value' => $customer_id,
			),

			'CustomerMemo' => array(
				'value' => $customer_memo,
			),

			'SalesTermRef' => array(
				'value' => 1, // Due on receipt
			),

			'BillEmail'    => array(
				'Address' => $customer_email,
			),
		);

		/*
		 * QuickBooks doesn't have a CustomerCurrency row for the home currency, so a CurrencyRef is only used
		 * for foreign currencies.
		 *
		 * QBO will automatically activate a valid currency for our Company when we create an invoice using it
		 * for the first time, so we don't need any code to automatically activate them.
		 */
		if ( 'USD' != $currency_code ) {
			$payload['CurrencyRef'] = array(
				'value' => $currency_code,
			);
		}

		$request_url = sprintf(
			'%s/v3/company/%d/invoice',
			self::$api_base_url,
			rawurlencode( $realm_id )
		);

		$payload = wp_json_encode( $payload );

		$args = array(
			'timeout' => self::REMOTE_REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => $oauth_header,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			'body'    => $payload,
		);

		return array(
			'url'  => $request_url,
			'args' => $args,
		);
	}

	/**
	 * Email a QuickBooks invoice to the Customer
	 *
	 * @param int $invoice_id
	 *
	 * @return bool|WP_Error true on success; WP_Error on failure
	 */
	protected static function send_invoice( $invoice_id ) {
		$qbo_request = self::build_qbo_send_invoice_request( $invoice_id );
		$response    = wp_remote_post( $qbo_request['url'], $qbo_request['args'] );
		Logger\log( 'remote_request', compact( 'qbo_request', 'response' ) );

		if ( is_wp_error( $response ) ) {
			$result = $response;
		} elseif ( 200 != wp_remote_retrieve_response_code( $response ) ) {
			$result = new WP_Error( 'invalid_http_code', 'Invalid HTTP response code', $response );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['Invoice']['EmailStatus'] ) && 'EmailSent' === $body['Invoice']['EmailStatus'] ) {
				$result = true;
			} else {
				$result = new WP_Error( 'empty_body', 'Could not decode invoice result.', $response );
			}
		}

		return $result;
	}

	/**
	 * Build a request to send an Invoice via QuickBook's API
	 *
	 * @param int $invoice_id
	 *
	 * @return array
	 */
	protected static function build_qbo_send_invoice_request( $invoice_id ) {
		self::load_options();
		$oauth_header = self::qbo_client()->get_oauth_header();
		$realm_id     = self::qbo_client()->get_realm_id();

		$request_url = sprintf(
			'%s/v3/company/%d/invoice/%s/send',
			self::$api_base_url,
			rawurlencode( $realm_id ),
			rawurlencode( absint( $invoice_id ) )
		);

		$args = array(
			'timeout' => self::REMOTE_REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => $oauth_header,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/octet-stream',
			),
			'body'    => '',
		);

		return array(
			'url'  => $request_url,
			'args' => $args,
		);
	}

	/**
	 * REST: /invoice_pdf
	 *
	 * Saves a PDF copy of the invoice and returns the filename
	 *
	 * Note: The function that eventually ends up using the file should delete it once it's done with it.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return string|WP_Error The filename on success, or a WP_Error on failure
	 */
	public static function rest_callback_invoice_pdf( $request ) {
		$qbo_request = self::build_qbo_get_invoice_pdf_request( $request->get_param( 'invoice_id' ) );
		$response    = wp_remote_get( $qbo_request['url'], $qbo_request['args'] );

		if ( is_wp_error( $response ) ) {
			$result = $response;
		} elseif ( 200 != wp_remote_retrieve_response_code( $response ) ) {
			$result = new WP_Error( 'invalid_http_code', 'Invalid HTTP response code', $response );
		} else {
			$body             = wp_remote_retrieve_body( $response );
			$valid_pdf_header = '%PDF-' === substr( $body, 0, 5 );
			$valid_pdf_footer = '%%EOF' === substr( $body, strlen( $body ) - 7, 5 );

			if ( $valid_pdf_header && $valid_pdf_footer ) {
				$response['body'] = '[valid pdf body removed]'; // because the binary contents aren't printable

				$filename = sprintf(
					'%sWPCS-invoice-%d.pdf',
					get_temp_dir(),
					$request->get_param( 'invoice_id' )
				);

				if ( file_put_contents( $filename, $body ) ) {
					$result = array( 'filename' => $filename );
				} else {
					$result = new WP_Error( 'write_error', 'Failed writing PDF to disk.', compact( 'filename', 'body' ) );
				}
			} else {
				$result = new WP_Error( 'invalid_body', 'Response body was not a PDF.', $response );
			}
		}

		Logger\log( 'remote_request', compact( 'qbo_request', 'response' ) ); // call after processing response, because body is removed if pdf is valid

		return $result;
	}

	/**
	 * Build a request to send an Invoice via QuickBook's API
	 *
	 * @param int $invoice_id
	 *
	 * @return array
	 */
	protected static function build_qbo_get_invoice_pdf_request( $invoice_id ) {
		self::load_options();
		$oauth_header = self::qbo_client()->get_oauth_header();
		$realm_id     = self::qbo_client()->get_realm_id();

		$request_url = sprintf(
			'%s/v3/company/%d/invoice/%d/pdf',
			self::$api_base_url,
			rawurlencode( $realm_id ),
			rawurlencode( $invoice_id )
		);

		$args = array(
			'timeout' => self::REMOTE_REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => $oauth_header,
				'Accept'        => 'application/pdf',
				'Content-Type'  => 'application/pdf',
			),
			'body'    => '',
		);

		return array(
			'url'  => $request_url,
			'args' => $args,
		);
	}

	/**
	 * Notify Central that an invoice was created but couldn't be sent to the sponsor
	 *
	 * @param int      $invoice_id
	 * @param WP_Error $error
	 *
	 * @return bool
	 */
	protected static function notify_invoice_failed_to_send( $invoice_id, $error ) {
		$message = sprintf( "
			QuickBooks invoice $invoice_id was created, but an error occurred while trying to send it to the sponsor.

			This may be an indication of a bug on WordCamp.org, so please ask your friendly neighborhood developers to investigate.

			The invoice will probably need to be sent manually in QuickBooks, but let the developers investigate first, and then go from there.

			Debugging information for the developers:

			%s",
			print_r( $error, true )
		);
		$message = str_replace( "\t", '', $message );

		return wp_mail( 'support@wordcamp.org', "QuickBooks invoice $invoice_id failed to send", $message );
	}

	/**
	 * Get a Customer ID, either by finding an existing one, or creating a new one
	 *
	 * @param string $sponsor
	 * @param string $currency_code
	 *
	 * @return int|WP_Error The customer ID if success; a WP_Error if failure
	 */
	protected static function probably_get_customer_id( $sponsor, $currency_code ) {
		$customer_id = self::get_customer( $sponsor['company-name'], $currency_code );

		if ( is_wp_error( $customer_id ) || ! $customer_id ) {
			$customer_id = self::create_customer( $sponsor, $currency_code );
		}

		return $customer_id;
	}

	/**
	 * Fetch a Customer record from QBO
	 *
	 * @param string $customer_name
	 * @param string $currency_code
	 *
	 * @return int|false|WP_Error A customer ID as integer, if one was found; false if no match was found; a WP_Error if an error occurred.
	 */
	protected static function get_customer( $customer_name, $currency_code ) {
		$qbo_request = self::build_qbo_get_customer_request( $customer_name );

		if ( is_wp_error( $qbo_request ) ) {
			return $qbo_request;
		}

		$response = wp_remote_get( $qbo_request['url'], $qbo_request['args'] );
		Logger\log( 'remote_request', compact( 'qbo_request', 'response' ) );

		if ( is_wp_error( $response ) ) {
			$result = $response;
		} elseif ( 200 != wp_remote_retrieve_response_code( $response ) ) {
			$result = new WP_Error( 'invalid_http_code', 'Invalid HTTP response code', $response );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['QueryResponse']['Customer'][0]['Id'] ) ) {
				$result = self::pluck_customer_id_by_currency( $body['QueryResponse']['Customer'], $currency_code );
			} elseif ( isset( $body['QueryResponse'] ) && 0 === count( $body['QueryResponse'] ) ) {
				$result = false;
			} else {
				$result = new WP_Error( 'invalid_response_body', 'Could not extract information from response.', $response );
			}
		}

		return $result;
	}

	/**
	 * Build a request to fetch a Customer from QuickBook's API
	 *
	 * @param string $customer_name
	 *
	 * @return array|WP_Error
	 */
	protected static function build_qbo_get_customer_request( $customer_name ) {
		global $wpdb;

		$customer_name = sanitize_text_field( $customer_name );

		self::load_options();
		$oauth_header = self::qbo_client()->get_oauth_header();
		$realm_id     = self::qbo_client()->get_realm_id();

		$request_url = sprintf(
			'%s/v3/company/%d/query',
			self::$api_base_url,
			rawurlencode( $realm_id )
		);

		$request_url_query = array(
			'query' => $wpdb->prepare(
				"SELECT * FROM Customer WHERE CompanyName = '%s'",
				str_replace( ':', '-', $customer_name )
			),
		);

		$args = array(
			'timeout' => self::REMOTE_REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => $oauth_header,
				'Accept'        => 'application/json',
			),
		);

		$request_url_query = array_map( 'rawurlencode', $request_url_query ); // has to be done after get_oauth_header(), or oauth_signature won't be generated correctly
		$request_url       = add_query_arg( $request_url_query, $request_url );

		return array(
			'url'  => $request_url,
			'args' => $args,
		);
	}

	/**
	 * Pluck a Customer out of an array based on their currency
	 *
	 * QuickBook's API doesn't allow you to filter query results based on a CurrencyRef, so we have to do it
	 * manually.
	 *
	 * @param array  $customers
	 * @param string $currency_code
	 *
	 * @return int|false A customer ID on success, or false on failure
	 */
	protected static function pluck_customer_id_by_currency( $customers, $currency_code ) {
		$customer_id = false;

		foreach ( $customers as $customer ) {
			if ( $customer['CurrencyRef']['value'] === $currency_code ) {
				$customer_id = absint( $customer['Id'] );
				break;
			}
		}

		return $customer_id;
	}

	/**
	 * Create a customer in QuickBooks for a corresponding Sponsor in WordCamp.org
	 *
	 * @param array  $sponsor
	 * @param string $currency_code
	 *
	 * @return int|WP_Error The customer ID if success; a WP_Error if failure
	 */
	protected static function create_customer( $sponsor, $currency_code ) {
		$qbo_request = self::build_qbo_create_customer_request( $sponsor, $currency_code );

		if ( is_wp_error( $qbo_request ) ) {
			return $qbo_request;
		}

		$response = wp_remote_post( $qbo_request['url'], $qbo_request['args'] );
		Logger\log( 'remote_request', compact( 'qbo_request', 'response' ) );

		if ( is_wp_error( $response ) ) {
			$result = $response;
		} elseif ( 200 != wp_remote_retrieve_response_code( $response ) ) {
			$result = new WP_Error( 'invalid_http_code', 'Invalid HTTP response code', $response );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['Customer']['Id'] ) ) {
				$result = absint( $body['Customer']['Id'] );
			} else {
				$result = new WP_Error( 'invalid_response_body', 'Could not extract customer ID from response.', $response );
			}
		}

		return $result;
	}

	/**
	 * Build a request to create a Customer via QuickBook's API
	 *
	 * @param array  $sponsor
	 * @param string $currency_code
	 *
	 * @return array|WP_Error
	 */
	protected static function build_qbo_create_customer_request( $sponsor, $currency_code ) {
		self::load_options();
		$oauth_header = self::qbo_client()->get_oauth_header();
		$realm_id     = self::qbo_client()->get_realm_id();

		$sponsor                  = array_map( 'sanitize_text_field', $sponsor );
		$sponsor['email-address'] = is_email( $sponsor['email-address'] );
		$sponsor['first-name']    = str_replace( ':', '-', $sponsor['first-name']   );
		$sponsor['last-name']     = str_replace( ':', '-', $sponsor['last-name']    );
		$sponsor['company-name']  = str_replace( ':', '-', $sponsor['company-name'] );
		$currency_code            = sanitize_text_field( $currency_code );

		if ( empty( $sponsor['company-name'] ) || empty( $sponsor['email-address'] ) ) {
			return new WP_Error( 'required_fields_missing', 'Required fields are missing.', $sponsor );
		}

		$payload = array(
			'BillAddr' => array(
				'Line1'                  => $sponsor['address1'],
				'City'                   => $sponsor['city'],
				'Country'                => $sponsor['country'],
				'CountrySubDivisionCode' => $sponsor['state'],
				'PostalCode'             => $sponsor['zip-code'],
			),

			'CurrencyRef' => array(
				'value' => $currency_code,
			),

			'PreferredDeliveryMethod' => 'Email',

			'GivenName'               => $sponsor['first-name'],
			'FamilyName'              => $sponsor['last-name'],
			'CompanyName'             => $sponsor['company-name'],
			'DisplayName'             => sprintf( '%s - %s', $sponsor['company-name'], $currency_code ),
			'PrintOnCheckName'        => $sponsor['company-name'],

			'PrimaryPhone'            => array(
				'FreeFormNumber' => $sponsor['phone-number'],
			),

			'PrimaryEmailAddr'        => array(
				'Address' => $sponsor['email-address'],
			),
		);

		if ( isset( $sponsor['address2'] ) ) {
			$payload['BillAddr']['Line2'] = $sponsor['address2'];
		}

		$request_url = sprintf(
			'%s/v3/company/%d/customer',
			self::$api_base_url,
			rawurlencode( $realm_id )
		);

		$payload = wp_json_encode( $payload );

		$args = array(
			'timeout' => self::REMOTE_REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => $oauth_header,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			'body'    => $payload,
		);

		return array(
			'url'  => $request_url,
			'args' => $args,
		);
	}

	/**
	 * Returns the subset of the given invoices that have been paid
	 *
	 * For example, if QBO invoice IDs 15, 18, 29, and 54 are passed, and 15 and 29 are marked as paid in QBO,
	 * then this endpoint will return 15 and 29.
	 *
	 * @param WP_REST_Request $wordcamp_request
	 *
	 * @return array|WP_Error
	 */
	public static function rest_callback_paid_invoices( $wordcamp_request ) {
		$qbo_request = self::build_qbo_paid_invoices_request( $wordcamp_request->get_param( 'invoice_ids' ) );

		if ( is_wp_error( $qbo_request ) ) {
			return $qbo_request;
		}

		$response = wp_remote_get( $qbo_request['url'], $qbo_request['args'] );
		Logger\log( 'remote_request', compact( 'qbo_request', 'response' ) );

		if ( is_wp_error( $response ) ) {
			$result = $response;
		} elseif ( 200 != wp_remote_retrieve_response_code( $response ) ) {
			$result = new WP_Error( 'invalid_http_code', 'Invalid HTTP response code', $response );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['QueryResponse']['Invoice'][0]['Id'] ) ) {
				$result = wp_list_pluck( $body['QueryResponse']['Invoice'], 'Id' );
				$result = array_map( 'absint', $result );
			} elseif ( isset( $body['QueryResponse'] ) && 0 === count( $body['QueryResponse'] ) ) {
				$result = array();  // no invoices have been paid
			} else {
				$result = new WP_Error( 'invalid_response', 'Could not extract invoice IDs from response.', $response );
			}
		}

		return $result;
	}

	/**
	 * Build a request to check QuickBook's API for paid invoices
	 *
	 * @param array $sent_invoice_ids
	 *
	 * @return array|WP_Error
	 */
	protected static function build_qbo_paid_invoices_request( $sent_invoice_ids ) {
		global $wpdb;

		self::load_options();
		$oauth_header = self::qbo_client()->get_oauth_header();
		$realm_id     = self::qbo_client()->get_realm_id();

		$request_url = sprintf(
			'%s/v3/company/%d/query',
			self::$api_base_url,
			rawurlencode( $realm_id )
		);

		$sent_invoice_ids = array_map( 'absint', $sent_invoice_ids );    // Invoice IDs are initially cast as integers for validation, and then converted back to strings, because that's what QBO expects.

		if ( empty( $sent_invoice_ids ) ) {
			return new WP_Error( 'no_ids', 'No Invoice IDs were given.' );
		}

		$invoice_id_placeholders = implode( ', ', array_fill( 0, count( $sent_invoice_ids ), '%s' ) );

		$request_url_query = array(
			'query' => $wpdb->prepare( "
				SELECT Id, Balance
				FROM Invoice
				WHERE
					Id IN ( $invoice_id_placeholders ) AND
					Balance = '0'", // QBO doesn't have an explicit status parameter, it just considers an invoice paid if the balance is 0.
				$sent_invoice_ids
			),
		);

		$args = array(
			'timeout' => self::REMOTE_REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => $oauth_header,
				'Accept'        => 'application/json',
			),
		);

		$request_url_query = array_map( 'rawurlencode', $request_url_query );   // The URL query parameters have to be encoded after the OAuth header is generated so that the signature will be generated correctly.
		$request_url       = add_query_arg( $request_url_query, $request_url );

		return array(
			'url'  => $request_url,
			'args' => $args,
		);
	}

	/**
	 * Verify an HMAC signature for an API request.
	 *
	 * @param WP_REST_Request $request The REST API request.
	 *
	 * @return bool True if valid, false if invalid.
	 */
	public static function is_valid_request( $request ) {
		if ( ! $request->get_header( 'authorization' ) ) {
			return false;
		}

		if ( ! preg_match( '#^wordcamp-qbo-hmac (.+)$#', $request->get_header( 'authorization' ), $matches ) ) {
			return false;
		}

		$given_hmac  = $matches[1];
		$request_url = esc_url_raw( home_url( parse_url( home_url( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) ) );
		$payload     = json_encode( array(
			strtolower( $request->get_method() ),
			strtolower( $request_url ),
			$request->get_body(),
			$request->get_query_params(),
		) );

		return hash_equals( hash_hmac( 'sha256', $payload, self::$hmac_key ), $given_hmac );
	}

	/**
	 * An instance of our client wrapper for the QBO V3 PHP SDK, which handles OAuth2 and REST requests.
	 *
	 * @return Quickbooks\Client
	 */
	protected static function qbo_client() {
		static $client;

		if ( ! isset( $client ) ) {
			$client = new Quickbooks\Client();
		}

		return $client;
	}
}

WordCamp_QBO::load();
