<?php
namespace CamptixBD\Gateway;

use CampTix_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Surjo Pay (shurjoPay) payment gateway for Bangladesh
 *
 * API: shurjoPay Payment Gateway v2.1
 * Docs: https://shurjopay.com.bd/frontend-developers/integration-howto
 */
class SurjoPay extends Base_Gateway {

	public $id                   = 'surjopay';
	public $name                 = 'Surjo Pay';
	public $description          = 'Surjo Pay payment gateway for Bangladesh.';
	public $supported_currencies = array( 'BDT' );

	/** @var string API base URL */
	private $api_endpoint;

	/** @var string Auth endpoint */
	private $url_auth;

	/** @var string Checkout endpoint */
	private $url_checkout;

	/** @var string Verification endpoint */
	private $url_verify;

	/** @var string Bearer token from authentication */
	private $sp_token;

	/** @var string Store ID from authentication */
	private $sp_store_id;

	/**
	 * Initialize hooks when gateway is enabled
	 */
	public function camptix_init() {
		$this->options = $this->get_payment_options();
		$this->setup_api_urls();

		if ( ! $this->gateway_enabled() ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'early_template_redirect', array( $this, 'early_template_redirect' ) );
	}

	/**
	 * Build API endpoint URLs based on sandbox/production mode
	 */
	private function setup_api_urls() {
		$is_sandbox = ! empty( $this->options['sandbox'] );

		$this->api_endpoint = $is_sandbox
			? 'https://sandbox.shurjopayment.com'
			: ( $this->options['api_endpoint'] ?? '' );

		$this->url_auth     = $this->api_endpoint . '/api/get_token';
		$this->url_checkout = $this->api_endpoint . '/api/secret-pay';
		$this->url_verify   = $this->api_endpoint . '/api/verification';
	}

	/**
	 * Authenticate with shurjoPay and obtain a Bearer token
	 *
	 * @return bool True on success, false on failure.
	 */
	private function authenticate() {
		$username = $this->options['username'] ?? '';
		$password = $this->options['password'] ?? '';

		if ( empty( $username ) || empty( $password ) ) {
			$this->log( 'Surjo Pay: Missing credentials' );
			return false;
		}

		$response = $this->api( 'POST', $this->url_auth, array(
			'username' => $username,
			'password' => $password,
		) );

		if ( ! $response ) {
			$this->log( 'Surjo Pay: Authentication failed' );
			return false;
		}

		if ( empty( $response->token ) || empty( $response->store_id ) ) {
			$this->log( 'Surjo Pay: Invalid auth response', null, (array) $response );
			return false;
		}

		$this->sp_token    = $response->token;
		$this->sp_store_id = $response->store_id;

		return true;
	}

	/**
	 * Start payment checkout
	 *
	 * @param string $payment_token The payment token from CampTix.
	 */
	public function payment_checkout( $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		if ( ! $this->verify_currency( $payment_token, 'BDT' ) ) {
			return;
		}

		$order = $this->get_order( $payment_token );
		if ( ! $order || empty( $order['total'] ) ) {
			$camptix->error( 'Invalid order.' );
			return;
		}

		// Authenticate with shurjoPay.
		if ( ! $this->authenticate() ) {
			$camptix->error( 'Payment gateway authentication failed.' );
			return;
		}

		// Generate a unique order ID with prefix.
		$prefix   = $this->options['prefix'] ?? 'WC';
		$order_id = $prefix . uniqid();

		// Build callback URLs.
		$urls = $this->build_callback_urls( $payment_token );

		// Build the payment request payload.
		$payload = $this->build_checkout_payload( $order, $order_id, $urls );

		// Call shurjoPay checkout API.
		$response = $this->api( 'POST', $this->url_checkout, $payload, array(
			'Authorization' => 'Bearer ' . $this->sp_token,
		) );

		if ( ! $response ) {
			$camptix->error( 'Payment gateway request failed.' );
			return;
		}

		if ( empty( $response->checkout_url ) ) {
			$this->log( 'Surjo Pay: No checkout URL in response', null, (array) $response );
			$camptix->error( 'Payment gateway returned an invalid response.' );
			return;
		}

		// Store order_id for timeout recovery.
		update_post_meta( $order['post_id'], 'tix_surjopay_order_id', $order_id );

		// Log the checkout attempt.
		$this->log(
			sprintf( 'Redirecting to Surjo Pay for order %s', $order_id ),
			$order['post_id'],
			array( 'order_id' => $order_id, 'amount' => $order['total'] )
		);

		wp_redirect( $response->checkout_url );
		exit;
	}

	/**
	 * Build the checkout API payload
	 *
	 * @param array  $order         Order details.
	 * @param string $order_id      Generated order ID.
	 * @param array  $urls          Callback URLs.
	 *
	 * @return array
	 */
	private function build_checkout_payload( $order, $order_id, $urls ) {
		// Get customer info from the first attendee.
		$first_item = $order['items'][0] ?? array();
		$attendee   = $first_item['attendee'] ?? null;

		$name    = $attendee ? trim( $attendee->first_name . ' ' . $attendee->last_name ) : '';
		$email   = $attendee ? $attendee->email : '';
		$phone   = $attendee ? ( get_post_meta( $attendee->ID, 'tix_phone', true ) ?: '01700000000' ) : '01700000000';

		return array(
			'token'                => $this->sp_token,
			'store_id'             => $this->sp_store_id,
			'prefix'               => $this->options['prefix'] ?? 'WC',
			'currency'             => 'BDT',
			'return_url'           => $urls['success_url'],
			'cancel_url'           => $urls['cancel_url'],
			'amount'               => (float) $order['total'],
			'discount_amount'      => 0,
			'disc_percent'         => 0,
			'order_id'             => $order_id,
			'customer_name'        => sanitize_text_field( $name ),
			'customer_phone'       => sanitize_text_field( $phone ),
			'customer_email'       => sanitize_email( $email ),
			'customer_address'     => 'Dhaka',
			'customer_city'        => 'Dhaka',
			'customer_state'       => 'Dhaka',
			'customer_postcode'    => '1209',
			'customer_country'     => 'Bangladesh',
			'shipping_address'     => 'N/A',
			'shipping_city'        => 'Dhaka',
			'shipping_country'     => 'Bangladesh',
			'received_person_name' => sanitize_text_field( $name ),
			'shipping_phone_number' => sanitize_text_field( $phone ),
		);
	}

	/**
	 * Handle POST-to-GET redirect for cross-domain compatibility
	 */
	public function early_template_redirect() {
		if (
			'POST' !== $_SERVER['REQUEST_METHOD'] ||
			! isset( $_REQUEST['tix_action'], $_REQUEST['tix_payment_method'] ) ||
			$this->id !== $_REQUEST['tix_payment_method'] ||
			! in_array( $_REQUEST['tix_action'], array( 'payment_return', 'payment_cancel' ) )
		) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'tix_action'         => sanitize_text_field( $_REQUEST['tix_action'] ),
					'tix_payment_token'  => sanitize_text_field( $_REQUEST['tix_payment_token'] ?? '' ),
					'tix_payment_method' => $this->id,
					'order_id'           => sanitize_text_field( $_REQUEST['order_id'] ?? '' ),
				),
				$GLOBALS['camptix']->get_tickets_url()
			)
		);

		wp_die();
	}

	/**
	 * Route return/cancel requests
	 */
	public function template_redirect() {
		if ( ( $_REQUEST['tix_payment_method'] ?? '' ) !== $this->id ) {
			return;
		}

		switch ( sanitize_text_field( $_GET['tix_action'] ?? '' ) ) {
			case 'payment_return':
				$this->payment_return();
				break;
			case 'payment_cancel':
				$this->payment_cancel();
				break;
		}
	}

	/**
	 * Handle successful payment return
	 */
	public function payment_return() {
		global $camptix;

		$payment_token = sanitize_text_field( trim( $_REQUEST['tix_payment_token'] ?? '' ) );
		$order_id      = sanitize_text_field( trim( $_REQUEST['order_id'] ?? '' ) );

		if ( ! $payment_token ) {
			$camptix->error( 'Missing payment token.' );
			return;
		}

		if ( ! $order_id ) {
			$camptix->error( 'Missing order ID.' );
			return;
		}

		// Authenticate to get a fresh token for verification.
		if ( ! $this->authenticate() ) {
			$camptix->error( 'Payment verification authentication failed.' );
			return;
		}

		// Verify the payment with shurjoPay.
		$verified = $this->verify_payment( $order_id );

		if ( $verified ) {
			$this->log(
				sprintf( 'Surjo Pay: Payment verified for order %s', $order_id ),
				null,
				array( 'order_id' => $order_id )
			);

			$camptix->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
				array( 'transaction_details' => $verified )
			);
		} else {
			$this->log(
				sprintf( 'Surjo Pay: Payment verification failed for order %s', $order_id ),
				null,
				array( 'order_id' => $order_id )
			);

			$camptix->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_FAILED,
				array( 'transaction_details' => array( 'order_id' => $order_id, 'reason' => 'verification_failed' ) )
			);
		}
	}

	/**
	 * Handle payment cancellation
	 */
	public function payment_cancel() {
		global $camptix;

		$payment_token = sanitize_text_field( trim( $_REQUEST['tix_payment_token'] ?? '' ) );
		$order_id      = sanitize_text_field( trim( $_REQUEST['order_id'] ?? '' ) );

		if ( ! $payment_token ) {
			return;
		}

		$this->log(
			sprintf( 'Surjo Pay: Payment cancelled for order %s', $order_id ),
			null,
			array( 'order_id' => $order_id )
		);

		$camptix->payment_result(
			$payment_token,
			CampTix_Plugin::PAYMENT_STATUS_CANCELLED,
			array( 'transaction_details' => array( 'order_id' => $order_id ) )
		);
	}

	/**
	 * Verify a payment with shurjoPay
	 *
	 * @param string $order_id The shurjoPay order ID.
	 *
	 * @return object|false Verification response on success, false on failure.
	 */
	private function verify_payment( $order_id ) {
		$response = $this->api( 'POST', $this->url_verify, array(
			'order_id' => $order_id,
		), array(
			'Authorization' => 'Bearer ' . $this->sp_token,
		) );

		if ( ! $response || ! is_array( $response ) || empty( $response[0] ) ) {
			return false;
		}

		$payment = $response[0];

		// sp_code == '1000' means success.
		if ( (string) $payment->sp_code !== '1000' ) {
			$this->log(
				sprintf( 'Surjo Pay: Verification returned sp_code %s', $payment->sp_code ),
				null,
				(array) $payment
			);
			return false;
		}

		return $payment;
	}

	/**
	 * Prevent a paid order from being marked as timeout
	 *
	 * @param int $attendee_id The attendee ID about to be timed out.
	 */
	public function pre_attendee_timeout( $attendee_id ) {
		$order_id = get_post_meta( $attendee_id, 'tix_surjopay_order_id', true );

		if ( ! $order_id ) {
			return;
		}

		if ( ! $this->authenticate() ) {
			return;
		}

		$verified = $this->verify_payment( $order_id );

		if ( $verified ) {
			$payment_token = get_post_meta( $attendee_id, 'tix_payment_token', true );

			$GLOBALS['camptix']->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
				array( 'transaction_details' => $verified )
			);
		}
	}

	/**
	 * Add phone to attendee object
	 *
	 * @param object $attendee      Attendee object.
	 * @param array  $attendee_info Attendee info.
	 * @param int    $current_count Current attendee index.
	 *
	 * @return object
	 */
	public function add_attendee_info( $attendee, $attendee_info, $current_count ) {
		if ( ! empty( $attendee_info['phone'] ) ) {
			$phone = sanitize_text_field( $attendee_info['phone'] );
			if ( '' !== $phone ) {
				$attendee->phone = $phone;
			}
		}

		return $attendee;
	}

	/**
	 * Register payment method settings fields
	 */
	public function payment_settings_fields() {
		$this->add_settings_field_helper(
			'username',
			__( 'Username', 'bd-payments-camptix' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'password',
			__( 'Password', 'bd-payments-camptix' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'prefix',
			__( 'Order ID Prefix', 'bd-payments-camptix' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'api_endpoint',
			__( 'API Endpoint', 'bd-payments-camptix' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'sandbox',
			__( 'Sandbox Mode', 'bd-payments-camptix' ),
			array( $this, 'field_yesno' )
		);
	}

	/**
	 * Validate and sanitize options on save
	 *
	 * @param array $input Raw input values.
	 *
	 * @return array Sanitized values.
	 */
	public function validate_options( $input ) {
		$output = $this->get_payment_options();

		$output['username']      = sanitize_text_field( $input['username'] ?? '' );
		$output['password']      = sanitize_text_field( $input['password'] ?? '' );
		$output['prefix']        = sanitize_text_field( $input['prefix'] ?? 'WC' );
		$output['api_endpoint']  = esc_url_raw( $input['api_endpoint'] ?? '' );
		$output['sandbox']       = absint( $input['sandbox'] ?? 0 );

		return $output;
	}
}
