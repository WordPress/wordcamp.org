<?php
namespace CamptixBD\Gateway;

use CampTix_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSLCommerz gateway
 */
class SSLCommerz extends Base_Gateway {

	public $id                   = 'sslcommerz';
	public $name                 = 'SSLCommerz';
	public $description          = 'SSLCommerz payment gateway for Bangladesh.';
	public $supported_currencies = [ 'BDT' ];
	public $options;

	/**
	 * Whether this request has SSLCommerz-signed transaction data restored from
	 * the temporary POST cookie. Only browser-return actions (payment_return,
	 * payment_failed, payment_cancel) require this; IPN (payment_notify) verifies
	 * its own hash independently.
	 *
	 * @var bool
	 */
	private $has_validated_gateway_data = false;

	/**
	 * Initialize gateway options and hooks.
	 *
	 * @return void
	 */
	public function camptix_init() {
		$this->options = array_merge(
			[
				'merchant_id'    => '',
				'store_password' => '',
				'sandbox'        => true,
			],
			$this->get_payment_options()
		);

		if ( $this->gateway_enabled() ) {
			add_filter( 'camptix_form_register_complete_attendee_object', [ $this, 'add_attendee_info' ], 10, 3 );
			add_action( 'template_redirect', [ $this, 'template_redirect' ], 6 ); // Before CampTix_Require_Login::block_unauthenticated_actions.
			add_action( 'template_redirect', [ $this, 'early_template_redirect' ], 5 ); // Before CampTix_Require_Login::block_unauthenticated_actions.

			// Catch any attendee timeouts that actually paid.
			add_action( 'camptix_pre_attendee_timeout', array( $this, 'pre_attendee_timeout' ) );
		}
	}

	/**
	 * If the phone number is passed, add this to the attendee object
	 *
	 * @param [type] $attendee
	 * @param [type] $attendee_info
	 * @param [type] $current_count
	 */
	public function add_attendee_info( $attendee, $attendee_info, $current_count ) {
		if ( ! empty( $attendee_info['phone'] ) ) {
			$phone = preg_replace( '/[^0-9+\-\(\)\s]/', '', (string) $attendee_info['phone'] );
			$phone = trim( preg_replace( '/\s+/', ' ', $phone ) );

			if ( '' !== $phone ) {
				$attendee->phone = $phone;
			}
		}

		return $attendee;
	}

	/**
	 * Process the payment
	 *
	 * @param  string $payment_token
	 *
	 * @return false|string|void
	 */
	public function payment_checkout( $payment_token ) {
		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) ) {
			return false;
		}

		if ( ! $this->verify_currency( 'BDT' ) ) {
			wp_die( esc_html__( 'The selected currency is not supported by this payment method.', 'bd-payments-camptix' ) );
		}

		$order = $this->get_order( $payment_token );
		if ( empty( $order ) || empty( $order['items'] ) ) {
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		$return_url = add_query_arg(
			array(
				'tix_action'         => 'payment_return',
				'tix_payment_token'  => $payment_token,
				'tix_payment_method' => $this->id,
			),
			$camptix->get_tickets_url()
		);

		$cancel_url = add_query_arg(
			array(
				'tix_action'         => 'payment_cancel',
				'tix_payment_token'  => $payment_token,
				'tix_payment_method' => $this->id,
			),
			$camptix->get_tickets_url()
		);

		$notify_url = add_query_arg(
			array(
				'tix_action'         => 'payment_notify',
				'tix_payment_token'  => $payment_token,
				'tix_payment_method' => $this->id,
			),
			$camptix->get_tickets_url()
		);

		$fail_url = add_query_arg(
			array(
				'tix_action'         => 'payment_failed',
				'tix_payment_token'  => $payment_token,
				'tix_payment_method' => $this->id,
			),
			$camptix->get_tickets_url()
		);

		$attendees = $this->get_attendees_by_payment_token( $payment_token );
		if ( empty( $attendees ) ) {
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		// Take the first attendee as the customer because
		// we need the name and phone number for the gateway.
		$attendee = reset( $attendees );
		$customer = $this->get_attendee_customer_info( $attendee->ID );
		$email    = $customer['email'];
		$name     = $customer['name'];
		$phone    = $customer['phone'];

		// Build the payment description with the event name and
		// ticket names with quantity.
		$description = $camptix->email_template_shortcode_event_name([]);

		foreach ( $order['items'] as $ticket ) {
			$description .= ' | ' . $ticket['name'] . ' x' . $ticket['quantity'];
		}

		$product_name = CampTix_Plugin::substr_bytes( $description, 0, 255 );
		$num_of_item  = array_sum( wp_list_pluck( $order['items'], 'quantity' ) );

		$args = [
			'store_id'         => $this->options['merchant_id'],
			'tran_id'          => $payment_token,
			'success_url'      => $return_url,
			'fail_url'         => $fail_url,
			'emi_option'       => 0,
			'cancel_url'       => $cancel_url,
			'ipn_url'          => $notify_url,
			'total_amount'     => $order['total'],
			'currency'         => $this->camptix_options['currency'],
			'store_passwd'     => $this->options['store_password'],
			'desc'             => $description,
			'cus_name'         => $name,
			'cus_email'        => $email,
			'cus_phone'        => $phone,
			'cus_add1'         => 'Dhaka',
			'cus_city'         => 'Dhaka',
			'cus_state'        => 'Dhaka',
			'cus_postcode'     => '1209',
			'cus_country'      => 'Bangladesh',
			'shipping_method'  => 'NO',
			'num_of_item'      => $num_of_item,
			'product_name'     => $product_name,
			'product_category' => 'event ticket',
			'product_profile'  => 'non-physical-goods',
		];

		$response = $this->api( 'POST', '/gwprocess/v4/api.php', $args );

		$response_data    = (array) $response;
		$status           = strtoupper( (string) ( $response_data['status'] ?? '' ) );
		$gateway_page_url = esc_url_raw( $response_data['GatewayPageURL'] ?? '' );

		if ( 'SUCCESS' === $status && ! empty( $gateway_page_url ) ) {
			if ( ! $this->is_allowed_https_host( $gateway_page_url, array( 'sandbox.sslcommerz.com', 'securepay.sslcommerz.com' ) ) ) {
				$camptix->log( 'SSLCommerz unexpected redirect host.', null, array( 'url' => $gateway_page_url ) );
				return CampTix_Plugin::PAYMENT_STATUS_FAILED;
			}

			// Store the sessionkey for future reference (timeout).
			if ( ! empty( $response->sessionkey ) ) {
				update_post_meta( $attendee->ID, '_sslcommerz_session_key', $response->sessionkey );
			}

			wp_redirect( $gateway_page_url );
			exit;
		}

		$camptix->log( 'SSLCommerz session initiation failed.', null, $this->prepare_transaction_for_log( $response_data ) );

		return CampTix_Plugin::PAYMENT_STATUS_FAILED;
	}

	/**
	 * Add payment settings field
	 *
	 * @return void
	 */
	public function payment_settings_fields() {
		$this->add_settings_field_helper( 'merchant_id', __( 'Store ID', 'bd-payments-camptix' ), [ $this, 'field_text' ] );
		$this->add_settings_field_helper( 'store_password', __( 'Store Password', 'bd-payments-camptix' ), [ $this, 'field_password' ] );
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode',  'bd-payments-camptix' ), [ $this, 'field_yesno' ] );
	}

	/**
	 * Validate payment settings fields
	 *
	 * @param  array $input
	 *
	 * @return array
	 */
	public function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['merchant_id'] ) ) {
			$output['merchant_id'] = $input['merchant_id'];
		}

		if ( isset( $input['store_password'] ) ) {
			$output['store_password'] = $input['store_password'];
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		return $output;
	}

	/**
	 * Monitor for return-from-gateway earlier in the request.
	 *
	 * SSLCommerz redirects back with a cross-domain POST request, which will result in the request
	 * not being authenticated on the WordCamp.org side, and thus blocked by the Require Login add-on.
	 *
	 * The lack of cookies is a browser security feature, and while we can work around this, we really shouldn't.
	 * Instead, this validates the returned POST data and if valid, submits a local GET redirect in place of it.
	 * The POST data (transaction/error details) are saved in a temporary cookie for use on the GET request.
	 *
	 * Without this, upon completing a payment, users will simply land on the ticket page without any indication
	 * they've got a ticket.
	 *
	 * Alternative implementation if this fails: Output a <form> that auto-submits a POST from same-origin.
	 *
	 * NOTE: payment_notify IPN is not covered here, as it's an unauthenticated server-to-server request, and
	 * thus not blocked by Require Login.
	 */
	public function early_template_redirect() {
		/*
		 * Undo the POST => Cookie behaviour on the redirect.
		 * Read the next section first.
		 *
		 * If the request has the returned POST data in the temporary cookie, extract it and merge it into the request.
		 *
		 * See early_template_redirect() for more details.
		 */
		if (
			empty( $_POST ) &&
			isset( $_COOKIE[ $this->id . '_postdata' ] )
		) {
			// Retrieve the temporary cookie with the POST'd transaction data.
			$transaction_data = json_decode( wp_unslash( $_COOKIE[ $this->id . '_postdata' ] ), true );

			if (
				is_array( $transaction_data ) &&
				'GET' === $_SERVER['REQUEST_METHOD'] &&
				$this->ipn_hash_verify( $this->options['store_password'], $transaction_data )
			) {
				// Merge the POST data into the request so that payment_notify() can use it.
				$_REQUEST = array_merge( $_REQUEST, $transaction_data );
				$_POST    = array_merge( $_POST, $transaction_data );

				// Mark the request as carrying validated gateway data so template_redirect()
				// knows it is safe to dispatch browser-return actions.
				$this->has_validated_gateway_data = true;
			}

			// Clear the temporary cookie.
			setcookie( $this->id . '_postdata', '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}

		// Only proceed if this is a return from the gateway with POST data.
		if (
			'POST' !== $_SERVER['REQUEST_METHOD'] ||
			! isset( $_REQUEST['tix_action'], $_REQUEST['tix_payment_method'] ) ||
			$this->id !== $_REQUEST['tix_payment_method'] ||
			! in_array( $_REQUEST['tix_action'], [ 'payment_return', 'payment_failed', 'payment_cancel' ], true )
		) {
			return;
		}

		// Set a temporary cookie with the POST'd transaction data, which we'll use on the GET request.
		if ( $this->ipn_hash_verify( $this->options['store_password'], $_POST ) ) {
			$cookie_data = wp_json_encode( $_POST );
			setcookie( $this->id . '_postdata', $cookie_data, time() + 300, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'tix_action'         => sanitize_text_field( $_REQUEST['tix_action'] ?? '' ),
					'tix_payment_token'  => sanitize_text_field( $_REQUEST['tix_payment_token'] ?? '' ),
					'tix_payment_method' => $this->id,
				],
				$GLOBALS['camptix']->get_tickets_url()
			)
		);

		exit;
	}

	/**
	 * Monitor for IPN and payment return
	 *
	 * @return void
	 */
	public function template_redirect() {
		if ( ( $_REQUEST['tix_payment_method'] ?? '' ) !== $this->id ) {
			return;
		}

		$action = sanitize_text_field( $_GET['tix_action'] ?? '' );

		// Browser-return actions (payment_return, payment_failed, payment_cancel) must
		// only run when the request carries validated SSLCommerz-signed data restored
		// from the temporary cookie set by early_template_redirect(). Without this
		// guard anyone could trigger the cancel/fail path with a crafted GET request.
		// IPN (payment_notify) performs its own hash verification and is not gated here.
		if (
			in_array( $action, array( 'payment_return', 'payment_failed', 'payment_cancel' ), true ) &&
			! $this->has_validated_gateway_data
		) {
			return;
		}

		switch ( $action ) {
			case 'payment_return':
				// Payment return is handled as a notification, so fall through to that case.
			case 'payment_notify':
				$this->payment_notify();
				break;
			case 'payment_cancel':
				$this->payment_cancel();
				break;
			case 'payment_failed':
				$this->payment_failed();
				break;
		}
	}

	/**
	 * Process payment return step (IPN or interactively).
	 *
	 * @return mixed
	 */
	public function payment_notify() {
		global $camptix;

		$payment_token  = sanitize_text_field( trim( $_REQUEST['tix_payment_token'] ?? '' ) );
		$transaction_id = sanitize_text_field( $_REQUEST['tran_id'] ?? '' );
		$val_id         = sanitize_text_field( $_REQUEST['val_id'] ?? '' );

		// The payment transaction data is always in the POST data.
		$transaction_data = $_POST;

		$payment_data = [
			'transaction_id'      => $transaction_id,
			'val_id'              => $val_id,
			'transaction_details' => $this->prepare_transaction_for_log( $transaction_data ),
		];

		if ( $this->ipn_hash_verify( $this->options['store_password'], $transaction_data ) ) {

			// Bind the signed POST body to the URL-supplied payment_token. The IPN signature
			// only covers fields named in verify_key, which does not include tix_payment_token,
			// so without this check an attacker could replay a single signed payload against
			// any other order of equal price by changing only the URL's tix_payment_token.
			$signed_tran_id = $transaction_data['tran_id'] ?? '';
			if ( ! hash_equals( (string) $payment_token, (string) $signed_tran_id ) ) {
				$payment_data['transaction_details']['TRAN_ID_MISMATCH'] = 'Signed tran_id does not match the URL payment_token';

				$camptix->log( 'SSLCommerz transaction ID mismatch.', null, $payment_data );
				return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
			}

			if ( $this->verify_transaction( $val_id, $payment_token ) ) {
				return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );
			} else {
				// Keep a note in the transaction details for why it failed.
				$payment_data['transaction_details']['IPN_VERIFICATION_FAILED'] = 'IPN Verification failed';

				return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
			}
		}

		$camptix->log( 'SSLCommerz IPN hash verification failed.', null, $payment_data );
		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
	}

	/**
	 * Cancel the payment
	 *
	 * @return mixed
	 */
	public function payment_cancel() {
		global $camptix;

		$payment_token = sanitize_text_field( trim( $_REQUEST['tix_payment_token'] ?? '' ) );
		if ( ! $payment_token ) {
			return $camptix->error( 'empty token' );
		}

		$transaction_id      = sanitize_text_field( $_REQUEST['tran_id'] ?? '' );
		$transaction_details = $this->prepare_transaction_for_log( $_POST );

		return $camptix->payment_result(
			$payment_token,
			CampTix_Plugin::PAYMENT_STATUS_CANCELLED,
			compact( 'transaction_id', 'transaction_details' )
		);
	}

	/**
	 * Fail the payment
	 *
	 * @return mixed
	 */
	public function payment_failed() {
		global $camptix;

		$payment_token = sanitize_text_field( trim( $_REQUEST['tix_payment_token'] ?? '' ) );
		if ( ! $payment_token ) {
			return $camptix->error( 'empty token' );
		}

		$transaction_id      = sanitize_text_field( $_REQUEST['tran_id'] ?? '' );
		$transaction_details = $this->prepare_transaction_for_log( $_POST );

		return $camptix->payment_result(
			$payment_token,
			CampTix_Plugin::PAYMENT_STATUS_FAILED,
			compact( 'transaction_id', 'transaction_details' )
		);
	}

	/**
	 * Verify the transaction
	 *
	 * @param string $val_id        The validation ID.
	 * @param string $payment_token The payment token.
	 *
	 * @return boolean
	 */
	public function verify_transaction( $val_id, $payment_token ) {
		global $camptix;

		$response = $this->api(
			'GET',
			'/validator/api/validationserverAPI.php',
			[
				'val_id'       => $val_id,
				'store_id'     => $this->options['merchant_id'],
				'store_passwd' => $this->options['store_password'],
				'format'       => 'json',
			]
		);
		if ( ! $response ) {
			return false;
		}

		// The validator API call only knows about val_id; nothing ties it to $payment_token
		// unless we cross-check the returned tran_id ourselves. Without this, a valid val_id
		// from one transaction could be presented for a different equal-priced order.
		if ( ! hash_equals( (string) $payment_token, (string) ( $response->tran_id ?? '' ) ) ) {
			return false;
		}

		$order = $this->get_order( $payment_token );

		if ( in_array( $response->status, [ 'VALID', 'VALIDATED' ], true ) && $this->sslcommerz_response_matches_order( $response, $order ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Prevent a paid order from being marked as timeout.
	 *
	 * @param int $attendee_id The attendee ID that is about to be timed out.
	 * @return void
	 */
	public function pre_attendee_timeout( $attendee_id ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		// precheck the attendee is in draft.
		if ( 'draft' !== get_post_field( 'post_status', $attendee_id ) ) {
			return;
		}

		// Get the session ID.
		$session_key   = get_post_meta( $attendee_id, '_sslcommerz_session_key', true );
		$payment_token = get_post_meta( $attendee_id, 'tix_payment_token', true );
		if ( ! $session_key || ! $payment_token ) {
			return;
		}

		$response = $this->api(
			'GET',
			'/validator/api/merchantTransIDvalidationAPI.php',
			[
				'sessionkey'   => $session_key,
				'store_id'     => $this->options['merchant_id'],
				'store_passwd' => $this->options['store_password'],
			]
		);
		if ( ! $response ) {
			return;
		}

		// If the transaction wasn't successful, bail out.
		if ( ! in_array( $response->status, [ 'VALID', 'VALIDATED' ], true ) ) {
			return;
		}

		// Bind the validator response to this attendee's payment_token. The session_key was
		// stored against this attendee, so the response should be for the same transaction —
		// but cross-checking tran_id makes that an assertion rather than an assumption.
		if ( ! hash_equals( (string) $payment_token, (string) ( $response->tran_id ?? '' ) ) ) {
			return;
		}

		// If the order details don't match, bail out.
		$order = $this->get_order( $payment_token );
		if ( ! $this->sslcommerz_response_matches_order( $response, $order ) ) {
			return;
		}

		// Order was successful, mark as paid.
		$camptix->log( 'SSLCommerz checkout timed out, but order succeeded.', $attendee_id, $response );

		$payment_data = [
			'transaction_id'      => $response->tran_id,
			'transaction_details' => $this->prepare_transaction_for_log( (array) $response ),
		];

		$camptix->payment_result(
			$payment_token,
			CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
			$payment_data,
			false /* non-interactive */
		);
	}


	/**
	 * Make an API call
	 *
	 * @param string $method  HTTP method (GET | POST).
	 * @param string $url     Full API URL (endpoint path will be appended to base).
	 * @param array  $args    Request body for POST, or query args for GET.
	 * @param array  $headers Additional headers.
	 *
	 * @return false|object
	 */
	protected function api( $method, $url, $args = [], $headers = [] ) {
		global $camptix;

		$base_url = $this->options['sandbox'] ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
		$full_url = $base_url . $url;

		$request_args = [
			'method'  => strtoupper( $method ),
			'timeout' => 30,
		];

		if ( 'POST' === strtoupper( $method ) ) {
			$request_args['body'] = $args;
		} elseif ( ! empty( $args ) ) {
			$full_url = add_query_arg( $args, $full_url );
		}

		$response = wp_remote_request( $full_url, $request_args );

		if ( is_wp_error( $response ) ) {
			$camptix->log( 'SSLCommerz API error: ' . $response->get_error_message() );
			return false;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! $result ) {
			$camptix->log( 'SSLCommerz API error: Not JSON', null, wp_remote_retrieve_body( $response ) );
			return false;
		}

		return $result;
	}

	/**
	 * Validate that the SSLCommerz response matches the local CampTix order.
	 *
	 * @param object $response SSLCommerz validation response.
	 * @param array  $order    Local CampTix order.
	 *
	 * @return bool
	 */
	private function sslcommerz_response_matches_order( $response, $order ) {
		if ( empty( $order['total'] ) ) {
			return false;
		}

		$response_amount = $response->currency_amount ?? $response->amount ?? null;
		if ( null === $response_amount ) {
			return false;
		}

		$expected_amount = (int) round( (float) $order['total'] * 100 );
		$actual_amount   = (int) round( (float) $response_amount * 100 );
		if ( $expected_amount !== $actual_amount ) {
			return false;
		}

		if ( 'BDT' !== strtoupper( (string) ( $response->currency_type ?? '' ) ) ) {
			return false;
		}

		if ( 'BDT' !== strtoupper( (string) ( $response->currency ?? '' ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Verify IPN hash
	 *
	 * @param  string $store_passwd The store password.
	 * @param  array  $data         The data to validate.
	 *
	 * @return boolean
	 */
	protected function ipn_hash_verify( $store_passwd, $data ) {
		if ( ! isset( $data['verify_sign'], $data['verify_key'] ) ) {
			return false;
		}

		$pre_define_key = explode( ',', $data['verify_key'] );
		$new_data       = array();

		if ( ! empty( $pre_define_key ) ) {
			foreach ( $pre_define_key as $value ) {
				if ( isset( $data[ $value ] ) ) {
					$new_data[ $value ] = $data[ $value ];
				}
			}
		}

		// Add MD5 of store password.
		$new_data['store_passwd'] = md5( $store_passwd );

		// Sort the key as before.
		ksort( $new_data );

		$hash_string = '';
		foreach ( $new_data as $key => $value ) {
			$hash_string .= $key . '=' . $value .'&';
		}

		$hash_string = rtrim( $hash_string, '&' );
		$hash_string = md5( $hash_string );

		return hash_equals( $hash_string, $data['verify_sign'] );
	}

	/**
	 * Prepare transaction data for logging.
	 *
	 * @param array $data The transaction data.
	 * @return array The sanitized transaction data for logging.
	 */
	protected function prepare_transaction_for_log( $data ) {
		$data = parent::prepare_transaction_for_log( $data );

		// Remove falsey stuff.
		$data = array_filter( $data );

		unset(
			$data['pass'],
			$data['key'],
			$data['store_id'],
			$data['sessionkey'],
			$data['val_id'],
			$data['value_a'],
			$data['value_b'],
			$data['value_c'],
			$data['value_d'],
			$data['verify_sign'],
			$data['verify_sign_sha2'],
			$data['verify_key']
		);

		return $data;
	}
}
