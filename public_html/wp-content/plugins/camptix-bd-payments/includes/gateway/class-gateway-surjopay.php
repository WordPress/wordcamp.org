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

	/** @var array Gateway options */
	protected $options = array();

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
		$this->options = array_merge(
			[
				'username' => '',
				'password' => '',
				'prefix'   => 'WC',
				'sandbox'  => true,
			],
			$this->get_payment_options()
		);
		$this->setup_api_urls();

		if ( ! $this->gateway_enabled() ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'early_template_redirect' ), 5 ); // Before CampTix_Require_Login::block_unauthenticated_actions.
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 6 ); // Before CampTix_Require_Login::block_unauthenticated_actions.
		add_action( 'camptix_pre_attendee_timeout', array( $this, 'pre_attendee_timeout' ) );
	}

	/**
	 * Build API endpoint URLs based on sandbox/production mode
	 */
	private function setup_api_urls() {
		$is_sandbox = ! empty( $this->options['sandbox'] );

		$this->api_endpoint = $is_sandbox
			? 'https://sandbox.shurjopayment.com/api'
			: 'https://engine.shurjopayment.com/api';

		$this->url_auth     = $this->api_endpoint . '/get_token';
		$this->url_checkout = $this->api_endpoint . '/secret-pay';
		$this->url_verify   = $this->api_endpoint . '/verification';
	}

	/**
	 * Authenticate with shurjoPay and obtain a Bearer token
	 *
	 * @return bool True on success, false on failure.
	 */
	private function authenticate() {
		$username = $this->options['username'] ?? '';
		$password = $this->options['password'] ?? '';

		if ( empty( $this->api_endpoint ) || empty( $username ) || empty( $password ) ) {
			$this->log( 'Surjo Pay: Missing credentials' );
			return false;
		}

		$response = $this->api(
			'POST',
			$this->url_auth,
			array(
				'username' => $username,
				'password' => $password,
			)
		);

		if ( ! $response ) {
			$this->log( 'Surjo Pay: Authentication failed' );
			return false;
		}

		if ( empty( $response->token ) || empty( $response->store_id ) ) {
			$this->log( 'Surjo Pay: Invalid auth response', null, $this->prepare_transaction_for_log( (array) $response ) );
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

		if ( ! $this->verify_currency( 'BDT' ) ) {
			wp_die( esc_html__( 'The selected currency is not supported by this payment method.', 'bd-payments-camptix' ) );
		}

		$order = $this->get_order( $payment_token );
		if ( ! $order || empty( $order['items'] ) || empty( $order['total'] ) ) {
			$camptix->error( 'Invalid order.' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		$attendees = $this->get_attendees_by_payment_token( $payment_token );
		if ( empty( $attendees ) ) {
			$camptix->error( 'Invalid order.' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		// Authenticate with shurjoPay.
		if ( ! $this->authenticate() ) {
			$camptix->error( 'Payment gateway authentication failed.' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		// Generate a cryptographically secure unique order ID with prefix.
		$prefix   = $this->options['prefix'] ?? 'WC';
		$order_id = $prefix . bin2hex( random_bytes( 8 ) );

		// Build callback URLs.
		$urls = $this->build_callback_urls( $payment_token );

		// Build the payment request payload.
		$customer = $this->get_attendee_customer_info( $attendees[0]->ID );
		$payload  = $this->build_checkout_payload( $order, $order_id, $urls, $customer );

		// build_checkout_payload returns an empty array if required fields (e.g. phone) are missing.
		if ( empty( $payload ) ) {
			$camptix->error( __( 'A valid phone number is required to complete payment. Please update your registration.', 'bd-payments-camptix' ) );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		// Call shurjoPay checkout API.
		$response = $this->api(
			'POST',
			$this->url_checkout,
			$payload,
			array(
				'Authorization' => 'Bearer ' . $this->sp_token,
			)
		);

		if ( ! $response ) {
			$camptix->error( 'Payment gateway request failed.' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		if ( empty( $response->checkout_url ) ) {
			$this->log( 'Surjo Pay: No checkout URL in response', null, $this->prepare_transaction_for_log( (array) $response ) );
			$camptix->error( 'Payment gateway returned an invalid response.' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		// Validate the checkout URL host is a known shurjoPay domain before redirecting.
		$checkout_url = esc_url_raw( $response->checkout_url );
		if ( ! $this->is_allowed_https_host( $checkout_url, array( 'shurjopayment.com' ) ) ) {
			$this->log(
				'Surjo Pay: Unexpected redirect host.',
				$attendees[0]->ID,
				array( 'host' => wp_parse_url( $checkout_url, PHP_URL_HOST ) )
			);
			$camptix->error( 'Payment gateway returned an invalid redirect URL.' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		$gateway_order_id = $this->get_gateway_order_id_from_checkout_response( $response, $checkout_url );
		if ( ! $gateway_order_id ) {
			$this->log( 'Surjo Pay: No gateway order ID in checkout response', null, $this->prepare_transaction_for_log( (array) $response ) );
			$camptix->error( 'Payment gateway returned an invalid response.' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		if ( ! $this->surjopay_response_matches_order( $response, $order, $order_id ) ) {
			$this->log( 'Surjo Pay: Checkout response did not match local order.', null, $this->prepare_transaction_for_log( (array) $response ) );
			$camptix->error( 'Payment gateway returned an invalid response.' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		// Store shurjoPay and customer order IDs for return validation and timeout recovery.
		foreach ( $attendees as $attendee ) {
			update_post_meta( $attendee->ID, 'tix_surjopay_order_id', $gateway_order_id );
			update_post_meta( $attendee->ID, 'tix_surjopay_customer_order_id', $order_id );
		}

		$this->log(
			sprintf( 'Redirecting to Surjo Pay for order %s', $gateway_order_id ),
			$attendees[0]->ID,
			array(
				'order_id'          => $gateway_order_id,
				'customer_order_id' => $order_id,
				'amount'            => $order['total'],
			)
		);

		wp_redirect( $checkout_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Scheme and allowed domain are validated above.
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
	private function build_checkout_payload( $order, $order_id, $urls, $customer ) {
		$name  = $customer['name'] ?? '';
		$email = $customer['email'] ?? '';
		$phone = $customer['phone'] ?? '';

		// Phone is required — the phone field addon enforces this, but double-check here.
		if ( empty( $phone ) ) {
			return array();
		}

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
			'customer_post_code'   => '1209',
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
				! in_array( $_REQUEST['tix_action'], array( 'payment_return', 'payment_cancel' ), true )
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

		exit;
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

		if ( ! $this->payment_token_matches_order_id( $payment_token, $order_id ) ) {
			$this->log(
				sprintf( 'Surjo Pay: Refusing return for mismatched order %s', $order_id ),
				null,
				array( 'order_id' => $order_id )
			);
			return;
		}

		// Authenticate to get a fresh token for verification.
		if ( ! $this->authenticate() ) {
			$camptix->error( 'Payment verification authentication failed.' );
			return;
		}

		// Verify the payment with shurjoPay.
		$verified = $this->verify_payment( $order_id, $payment_token );

		if ( $verified ) {
			$this->log(
				sprintf( 'Surjo Pay: Payment verified for order %s', $order_id ),
				null,
				array( 'order_id' => $order_id )
			);

			$camptix->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
				array( 'transaction_details' => $this->prepare_surjopay_transaction_details( $verified ) )
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
				array(
					'transaction_details' => array(
						'order_id' => $order_id,
						'reason'   => 'verification_failed',
					),
				)
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

		if ( ! $payment_token || ! $order_id ) {
			return;
		}

		if ( ! $this->payment_token_matches_order_id( $payment_token, $order_id ) ) {
			$this->log(
				sprintf( 'Surjo Pay: Refusing cancel for mismatched order %s', $order_id ),
				null,
				array( 'order_id' => $order_id )
			);
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
	 * @param string $order_id      The shurjoPay order ID.
	 * @param string $payment_token CampTix payment token.
	 *
	 * @return object|false Verification response on success, false on failure.
	 */
	private function verify_payment( $order_id, $payment_token = '' ) {
		$response = $this->api(
			'POST',
			$this->url_verify,
			array(
				'order_id' => $order_id,
			),
			array(
				'Authorization' => 'Bearer ' . $this->sp_token,
			)
		);

		if ( ! $response || ! is_array( $response ) || empty( $response[0] ) ) {
			return false;
		}

		$payment = $response[0];

		if (
			isset( $payment->order_id ) &&
			! hash_equals( (string) $order_id, (string) $payment->order_id )
		) {
			$this->log(
				sprintf( 'Surjo Pay: Verification order mismatch for order %s', $order_id ),
				null,
				$this->prepare_transaction_for_log( (array) $payment )
			);
			return false;
		}

		// sp_code == '1000' means success.
		$sp_code = $payment->sp_code ?? '';

		if ( '1000' !== (string) $sp_code ) {
			$this->log(
				sprintf( 'Surjo Pay: Verification returned sp_code %s', $sp_code ),
				null,
				$this->prepare_transaction_for_log( (array) $payment )
			);
			return false;
		}

		if ( $payment_token && ! $this->surjopay_response_matches_order( $payment, $this->get_order( $payment_token ), $this->get_customer_order_id( $payment_token ) ) ) {
			$this->log(
				sprintf( 'Surjo Pay: Verification amount, currency, or customer order mismatch for order %s', $order_id ),
				null,
				$this->prepare_transaction_for_log( (array) $payment )
			);
			return false;
		}

		return $payment;
	}

	/**
	 * Prepare a shurjoPay verification response for transaction storage.
	 *
	 * The raw verification response can contain sensitive customer details and
	 * internal API tokens. This method allowlists only the fields that are safe
	 * to persist in post meta / CampTix logs, then runs the result through the
	 * base class redactor to strip any remaining sensitive keys.
	 *
	 * @param object|array $payment Verification response from shurjoPay.
	 *
	 * @return array
	 */
	private function prepare_surjopay_transaction_details( $payment ) {
		$payment = (array) $payment;

		$allowed_keys = array(
			'order_id',
			'customer_order_id',
			'sp_code',
			'sp_massage',
			'bank_status',
			'currency',
			'amount',
			'payable_amount',
			'discount_amount',
			'disc_percent',
			'received_amount',
			'recived_amount',
			'invoice_no',
			'method',
			'date_time',
		);

		return array_intersect_key(
			$this->prepare_transaction_for_log( $payment ),
			array_flip( $allowed_keys )
		);
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

		$payment_token = get_post_meta( $attendee_id, 'tix_payment_token', true );
		if ( ! $payment_token ) {
			return;
		}

		$verified = $this->verify_payment( $order_id, $payment_token );

		if ( $verified ) {
			$GLOBALS['camptix']->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
				array( 'transaction_details' => $this->prepare_surjopay_transaction_details( $verified ) ),
				false
			);
		}
	}

	/**
	 * Verify that a shurjoPay order belongs to the CampTix payment token.
	 *
	 * @param string $payment_token CampTix payment token.
	 * @param string $order_id      shurjoPay order ID.
	 *
	 * @return bool
	 */
	private function payment_token_matches_order_id( $payment_token, $order_id ) {
		$attendees = $this->get_attendees_by_payment_token( $payment_token );

		if ( empty( $attendees ) ) {
			return false;
		}

		foreach ( $attendees as $attendee ) {
			$saved_order_id = get_post_meta( $attendee->ID, 'tix_surjopay_order_id', true );

			if ( hash_equals( (string) $saved_order_id, (string) $order_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the shurjoPay order ID from the checkout response.
	 *
	 * @param object $response     Checkout response.
	 * @param string $checkout_url Checkout URL.
	 *
	 * @return string
	 */
	private function get_gateway_order_id_from_checkout_response( $response, $checkout_url ) {
		$order_id = $response->sp_order_id ?? $response->order_id ?? '';

		if ( ! $order_id ) {
			wp_parse_str( (string) wp_parse_url( $checkout_url, PHP_URL_QUERY ), $query_args );
			$order_id = $query_args['order_id'] ?? '';
		}

		return sanitize_text_field( (string) $order_id );
	}

	/**
	 * Get the customer order ID saved for a payment token.
	 *
	 * @param string $payment_token CampTix payment token.
	 *
	 * @return string
	 */
	private function get_customer_order_id( $payment_token ) {
		$attendees = $this->get_attendees_by_payment_token( $payment_token );

		if ( empty( $attendees ) ) {
			return '';
		}

		foreach ( $attendees as $attendee ) {
			$customer_order_id = get_post_meta( $attendee->ID, 'tix_surjopay_customer_order_id', true );

			if ( $customer_order_id ) {
				return (string) $customer_order_id;
			}
		}

		return '';
	}

	/**
	 * Validate that shurjoPay response details match the local CampTix order.
	 *
	 * @param object $response          shurjoPay response object.
	 * @param array  $order             Local CampTix order.
	 * @param string $customer_order_id Locally generated customer order ID.
	 *
	 * @return bool
	 */
	private function surjopay_response_matches_order( $response, $order, $customer_order_id ) {
		if ( empty( $order['total'] ) || ! isset( $response->amount, $response->currency, $response->customer_order_id ) ) {
			return false;
		}

		$expected_amount = (int) round( (float) $order['total'] * 100 );
		$actual_amount   = (int) round( (float) $response->amount * 100 );

		if ( $expected_amount !== $actual_amount ) {
			return false;
		}

		if ( 'BDT' !== strtoupper( (string) $response->currency ) ) {
			return false;
		}

		return hash_equals( (string) $customer_order_id, (string) $response->customer_order_id );
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
			array( $this, 'field_password' )
		);

		$this->add_settings_field_helper(
			'prefix',
			__( 'Order ID Prefix', 'bd-payments-camptix' ),
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

		$output['username'] = sanitize_text_field( $input['username'] ?? '' );
		$output['password'] = sanitize_text_field( $input['password'] ?? '' );
		$output['prefix']   = sanitize_text_field( $input['prefix'] ?? 'WC' );
		$output['sandbox']  = absint( $input['sandbox'] ?? 0 );

		return $output;
	}
}
