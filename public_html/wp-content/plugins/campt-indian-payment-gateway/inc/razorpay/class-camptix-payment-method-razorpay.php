<?php
/**
 * CampTix RazorPay Payment Method
 *
 * This class handles all Instamojo integration for CampTix
 *
 * @category       Class
 * @package        Camptix Razorpay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // End if().


// Load Razorpay sdk.
require_once CAMPTIX_MULTI_DIR . 'inc/razorpay/lib/razorpay-php/Razorpay.php';

use Razorpay\Api\Api;

class CampTix_Payment_Method_RazorPay extends CampTix_Payment_Method {
	/**
	 * Payment gateway id.
	 *
	 * @since 1.0
	 * @var string
	 */
	public $id = 'camptix_razorpay';

	/**
	 * Payment gateway label
	 *
	 * @since 1.0
	 * @var string
	 */
	public $name = 'Razorpay';

	/**
	 * Payment gateway description
	 *
	 * @since 1.0
	 * @var string
	 */
	public $description = 'Razorpay Indian payment gateway.';

	/**
	 * Supported currencies
	 *
	 * @since 1.0
	 * @var array
	 */
	public $supported_currencies = array( 'INR' );


	/**
	 * Supported features
	 *
	 * @since 1.0
	 * @var array
	 */
	public $supported_features = array(
		'refund-single' => false,
		'refund-all'    => false,
	);

	/**
	 * We can have an array to store our options.
	 * Use $this->get_payment_options() to retrieve them.
	 */
	protected $options = array();

	/**
	 * This is to Initiate te CampTix options
	 *
	 * @since 1.0
	 */
	public function camptix_init() {
		$this->options = wp_parse_args(
			$this->get_payment_options(),
			array(
				'razorpay_popup_title' => '',
				'live_key_id'          => '',
				'live_key_secret'      => '',
				'test_key_id'          => '',
				'test_key_secret'      => '',
				'sandbox'              => true,
			)
		);

		// Apply hooks only when payment gateway enable.
		if ( $this->is_gateway_enable() ) {
			add_action( 'template_redirect', array( $this, 'template_redirect' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
			add_filter( 'camptix_indian_payments_localize_vars', array( $this, 'add_localize_vars' ) );
		}
	}


	/**
	 * Load scripts and style
	 *
	 * @since  1.0
	 * @access public
	 */
	public function enqueue() {

		if ( ! isset( $_GET['tix_action'] ) || ( 'attendee_info' !== $_GET['tix_action'] ) ) {
			return;
		}

		wp_register_script( 'camptix-indian-payments-main-razorpayjs', CAMPTIX_MULTI_URL . 'assets/js/camptix-multi-popup-razorpay.js', array( 'jquery' ), false, CAMPTIX_INDIAN_PAYMENTS_VERSION );
		wp_enqueue_script( 'camptix-indian-payments-main-razorpayjs' );

		$data = apply_filters(
			'camptix_indian_payments_localize_vars',
			array(
				'errors' => array(
					'phone' => __( 'Please fill in all required fields.', 'campt-indian-payment-gateway' ),
				),
			)
		);

		wp_localize_script( 'camptix-indian-payments-main-razorpayjs', 'camptix_inr_vars', $data );


		wp_register_script( 'razorpay-js', 'https://checkout.razorpay.com/v1/checkout-new.js' );
		wp_enqueue_script( 'razorpay-js' );
	}

	/**
	 * Add localize params
	 *
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @param array $localize
	 *
	 * @return array
	 */
	public function add_localize_vars( $localize ) {
		// Bailout.
		if ( ! isset( $_GET['tix_action'] ) || ( 'attendee_info' !== $_GET['tix_action'] ) ) {
			return $localize;
		}

		$merchant = $this->get_merchant_credentials();

		$data = array(
			'merchant_key_id' => esc_js( $merchant['key_id'] ),
			'gateway_id'      => $this->id,
			'popup'           => array(
				'color' => esc_js( apply_filters( 'camptix_razorpay_popup_color', '' ) ),

				// Ideal logo size: https://i.imgur.com/n5tjHFD.png
				'image' => esc_js( apply_filters( 'camptix_razorpay_popup_logo_image', '' ) ),
			),
		);

		return array_merge( $localize, $data );
	}


	/**
	 * Get mechant credentials
	 *
	 * @since  1.0
	 * @access public
	 * @return array
	 */
	public function get_merchant_credentials() {
		$merchant = array(
			'key_id'     => $this->options['test_key_id'],
			'key_secret' => $this->options['test_key_secret'],
		);

		if ( ! $this->options['sandbox'] ) {
			$merchant = array(
				'key_id'     => $this->options['live_key_id'],
				'key_secret' => $this->options['live_key_secret'],
			);
		}

		return $merchant;
	}

	/**
	 * Process payment gateway actions.
	 *
	 * @since  1.0
	 * @access public
	 */
	function template_redirect() {
		if ( isset( $_GET['tix_action'] ) ) {

			if ( isset( $_REQUEST['tix_payment_method'] ) && $this->id === $_REQUEST['tix_payment_method'] ) {
				if ( 'payment_cancel' == $_GET['tix_action'] ) {
					// $this->payment_cancel();
				}

				if ( 'payment_return' == $_GET['tix_action'] ) {
					$this->payment_return();
				}
			}
		}// End if().
	}

	/**
	 * Add settings.
	 *
	 * @since  1.0
	 * @access public
	 */
	public function payment_settings_fields() {
		$this->add_settings_field_helper(
			'razorpay_popup_title',
			__( 'Razorpay Popup Title', 'campt-indian-payment-gateway' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'live_key_id',
			__( 'Live Key ID', 'campt-indian-payment-gateway' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'live_key_secret',
			__( 'Live Key Secret', 'campt-indian-payment-gateway' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'test_key_id',
			__( 'Test Key ID', 'campt-indian-payment-gateway' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'test_key_secret',
			__( 'Test Key Secret', 'campt-indian-payment-gateway' ),
			array( $this, 'field_text' )
		);

		$this->add_settings_field_helper(
			'sandbox',
			__( 'Sandbox Mode', 'campt-indian-payment-gateway' ),
			array( $this, 'field_yesno' ),
			__( 'The RazorPay Sandbox is a way to test payments without using real accounts and transactions. When enabled it will use sandbox merchant details instead of the ones defined above.', 'campt-indian-payment-gateway' )
		);
	}

	/**
	 * Validate options
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	public function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['razorpay_popup_title'] ) ) {
			$output['razorpay_popup_title'] = wp_kses_post( $input['razorpay_popup_title'] );
		}

		if ( isset( $input['live_key_id'] ) ) {
			$output['live_key_id'] = $input['live_key_id'];
		}

		if ( isset( $input['live_key_secret'] ) ) {
			$output['live_key_secret'] = $input['live_key_secret'];
		}

		if ( isset( $input['test_key_id'] ) ) {
			$output['test_key_id'] = $input['test_key_id'];
		}

		if ( isset( $input['test_key_secret'] ) ) {
			$output['test_key_secret'] = $input['test_key_secret'];
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		return $output;
	}


	/**
	 * CampTix Payment CheckOut : Generate & Submit the payment form.
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @param string $payment_token
	 *
	 * @return void
	 */
	public function payment_checkout( $payment_token ) {
		/* @var  CampTix_Plugin $camptix */
		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) ) {
			return;
		}

		$info   = $this->get_order( $payment_token );
		$amount = (int) round( $info['total'] * 100 );

		/*
		 * Create the Razorpay order here, rather than while rendering the attendee
		 * form, so that it can be tied to this CampTix order. `notes` and `receipt`
		 * carry the binding on Razorpay's side; the post meta written below carries
		 * it on ours. payment_return() completes the order only when the two agree.
		 */
		try {
			$api            = $this->get_razjorpay_api();
			$razorpay_order = $api->order->create(
				array(
					'receipt'         => $payment_token, // An md5, so 32 of the 40 characters Razorpay allows.
					'amount'          => $amount,
					'currency'        => 'INR',
					'payment_capture' => true,
					'notes'           => array(
						'site_id'       => get_current_blog_id(),
						'payment_token' => $payment_token,
					),
				)
			);
		} catch ( Exception $e ) {
			$this->log( 'Could not create the Razorpay order.', $info['attendee_id'], array( 'error' => $e->getMessage() ) );

			wp_send_json_error( array(
				'message' => __( 'We could not start the payment. Please try again.', 'campt-indian-payment-gateway' ),
			) );
		}

		$this->bind_razorpay_order( $payment_token, $razorpay_order['id'], $amount );

		$return_url = add_query_arg( array(
			'tix_action'         => 'payment_return',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$extra_info = array(
			'fullname'          => trim( get_post_meta( $info['attendee_id'], 'tix_first_name', true ) . ' ' . get_post_meta( $info['attendee_id'], 'tix_last_name', true ) ),
			'email'             => get_post_meta( $info['attendee_id'], 'tix_email', true ),
			'phone'             => get_post_meta( $info['attendee_id'], 'tix_phone', true ),
			'razorpay_order_id' => $razorpay_order['id'],
			'total_in_paisa'    => $amount,
			'return_url'        => $return_url,
			'popup_title'       => $this->options['razorpay_popup_title'],
		);

		wp_send_json_success( array_merge( $info, $extra_info ) );
	}

	/**
	 * Get every attendee post that belongs to a CampTix payment token.
	 *
	 * @since  1.9
	 * @access protected
	 *
	 * @param string $payment_token
	 *
	 * @return WP_Post[]
	 */
	protected function get_attendees_for_token( $payment_token ) {
		return get_posts(
			array(
				'posts_per_page' => -1,
				'post_type'      => 'tix_attendee',
				'post_status'    => array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed' ),
				'meta_query'     => array(
					array(
						'key'     => 'tix_payment_token',
						'compare' => '=',
						'value'   => $payment_token,
						'type'    => 'CHAR',
					),
				),
			)
		);
	}

	/**
	 * Record which Razorpay order, and for how much, this CampTix order was sent to pay.
	 *
	 * Written to every attendee on the token so that the return path does not depend
	 * on picking the same "first" attendee that checkout did.
	 *
	 * @since  1.9
	 * @access protected
	 *
	 * @param string $payment_token
	 * @param string $razorpay_order_id
	 * @param int    $amount            The amount sent to Razorpay, in paise.
	 *
	 * @return void
	 */
	protected function bind_razorpay_order( $payment_token, $razorpay_order_id, $amount ) {
		foreach ( $this->get_attendees_for_token( $payment_token ) as $attendee ) {
			update_post_meta( $attendee->ID, '_razorpay_order_id', $razorpay_order_id );
			update_post_meta( $attendee->ID, '_razorpay_amount', $amount );
		}
	}

	/**
	 * Process payment return.
	 *
	 * @since  0.2
	 * @access public
	 */
	function payment_return() {
		/* @var  CampTix_Plugin $camptix */
		global $camptix;

		// Set logs.
		$this->log( sprintf( 'Running payment_return. Request data attached.' ), null, $_REQUEST );

		$payment_token       = sanitize_text_field( wp_unslash( $_REQUEST['tix_payment_token'] ?? '' ) );
		$razorpay_order_id   = sanitize_text_field( wp_unslash( $_GET['transaction_id'] ?? '' ) );
		$razorpay_payment_id = sanitize_text_field( wp_unslash( $_GET['razorpay_payment_id'] ?? '' ) );
		$razorpay_signature  = sanitize_text_field( wp_unslash( $_GET['razorpay_signature'] ?? '' ) );

		// Bailout.
		if ( empty( $payment_token ) || empty( $razorpay_order_id ) ) {
			return;
		}

		$attendees = $this->get_attendees_for_token( $payment_token );

		// Bailout.
		if ( empty( $attendees ) ) {
			return;
		}

		// Reset attendees.
		$attendee          = reset( $attendees );
		$expected_order_id = (string) get_post_meta( $attendee->ID, '_razorpay_order_id', true );

		/*
		 * This request is public and entirely attacker-controlled, so the Razorpay
		 * order id it names proves nothing on its own — a paid order id from any
		 * other purchase would otherwise complete this one. Only the id recorded at
		 * checkout counts. A request that fails this check says nothing about the
		 * real order, so refuse it without touching that order's status.
		 */
		if ( $expected_order_id && ! hash_equals( $expected_order_id, $razorpay_order_id ) ) {
			$this->log(
				'Refusing Razorpay return: the order id does not belong to this CampTix order.',
				$attendee->ID,
				compact( 'payment_token', 'razorpay_order_id', 'expected_order_id' )
			);

			wp_die( esc_html__( 'We could not verify this payment. Please contact the organizers.', 'campt-indian-payment-gateway' ) );
		}

		$status = $this->verify_razorpay_payment( $attendee->ID, $razorpay_order_id, $razorpay_payment_id, $razorpay_signature );

		/*
		 * Razorpay reported that nothing was ever paid against this order, so this
		 * request is no more meaningful than one naming somebody else's order id.
		 * Refuse it the same way. Recording it as pending would consume a ticket
		 * from the event's inventory and email a ticket, both for free.
		 */
		if ( null === $status ) {
			wp_die( esc_html__( 'We could not verify this payment. Please contact the organizers.', 'campt-indian-payment-gateway' ) );
		}

		// Record the verified payment result.
		$camptix->payment_result(
			$payment_token,
			$status,
			array(
				'transaction_id'      => $razorpay_payment_id ? $razorpay_payment_id : $razorpay_order_id,
				'transaction_details' => array( 'raw' => $_GET ),
			)
		);

		// Show ticket to attendee.
		$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
		$url          = add_query_arg( array(
			'tix_action'       => 'access_tickets',
			'tix_access_token' => $access_token,
		), $camptix->get_tickets_url() );

		// Redirect to ticket page.
		wp_safe_redirect( esc_url_raw( $url . '#tix' ) );

		exit();
	}

	/**
	 * Confirm a Razorpay payment before the CampTix order is completed.
	 *
	 * The outcome is decided by what Razorpay reports about the order, not by what
	 * the return request claims, and it distinguishes three cases:
	 *
	 * - Paid in full: complete the order.
	 * - Nothing was ever paid: refuse, and leave the order untouched. Recording
	 *   this as pending would consume a ticket from the event's inventory and
	 *   email a ticket, so anyone could drain a WordCamp's stock by starting
	 *   orders and returning without paying.
	 * - Anything else — Razorpay unreachable, payment in flight, or paid for the
	 *   wrong amount: pending, so an order whose buyer may already have been
	 *   charged surfaces for an organizer instead of being dropped.
	 *
	 * @since  1.9
	 * @access protected
	 *
	 * @param int    $attendee_id         The attendee to log against.
	 * @param string $razorpay_order_id   The Razorpay order id, already confirmed to be this order's.
	 * @param string $razorpay_payment_id The Razorpay payment id from the return request.
	 * @param string $razorpay_signature  The Razorpay signature from the return request.
	 *
	 * @return int|null One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants,
	 *                  or null to refuse the request without recording a result.
	 */
	protected function verify_razorpay_payment( $attendee_id, $razorpay_order_id, $razorpay_payment_id, $razorpay_signature ) {
		$expected_amount = (int) get_post_meta( $attendee_id, '_razorpay_amount', true );

		if ( ! $expected_amount ) {
			/*
			 * Checkout predates this binding, so there is nothing to check the return
			 * against. Only orders started before this code shipped can reach here, and
			 * their buyers may have paid, so leave them for an organizer to reconcile.
			 * Once no such orders remain, this branch can refuse instead.
			 */
			$this->log( 'Razorpay return with no recorded order binding; holding as pending.', $attendee_id, compact( 'razorpay_order_id' ) );

			return CampTix_Plugin::PAYMENT_STATUS_PENDING;
		}

		/*
		 * The signature is an HMAC of "<order_id>|<payment_id>" under the secret key,
		 * so a valid one shows the buyer really came back through Razorpay's checkout.
		 * It is recorded rather than acted on: the order id is already known to be
		 * this order's, and the authenticated fetch below is what decides whether any
		 * money moved, so a mangled redirect should not cost a paying buyer a ticket.
		 * Note that it throws on mismatch rather than returning false.
		 */
		try {
			$api = $this->get_razjorpay_api();

			$api->utility->verifyPaymentSignature(
				array(
					'razorpay_order_id'   => $razorpay_order_id,
					'razorpay_payment_id' => $razorpay_payment_id,
					'razorpay_signature'  => $razorpay_signature,
				)
			);
		} catch ( Exception $e ) {
			$this->log( 'Razorpay signature verification failed.', $attendee_id, array( 'error' => $e->getMessage() ) );
		}

		// Authoritative check, server to server, authenticated with the secret key.
		try {
			$razorpay_order = $this->fetch_razorpay_order( $razorpay_order_id );
		} catch ( Exception $e ) {
			$this->log( 'Razorpay order fetch failed during payment return.', $attendee_id, array( 'error' => $e->getMessage() ) );

			return CampTix_Plugin::PAYMENT_STATUS_PENDING;
		}

		$razorpay_status = isset( $razorpay_order['status'] ) ? $razorpay_order['status'] : '';
		$amount_paid     = isset( $razorpay_order['amount_paid'] ) ? (int) $razorpay_order['amount_paid'] : -1;

		if ( 'paid' === $razorpay_status && $amount_paid === $expected_amount ) {
			return CampTix_Plugin::PAYMENT_STATUS_COMPLETED;
		}

		/*
		 * `created` means no payment was ever attempted against this order, so the
		 * request carries no evidence of anything. `attempted` means one was, and it
		 * may still settle.
		 */
		if ( 'created' === $razorpay_status ) {
			$this->log(
				'Refusing Razorpay return: no payment was attempted against this order.',
				$attendee_id,
				compact( 'razorpay_order_id', 'razorpay_status' )
			);

			return null;
		}

		$this->log(
			'Razorpay order is not paid in full; holding as pending.',
			$attendee_id,
			compact( 'razorpay_order_id', 'razorpay_status', 'amount_paid', 'expected_amount' )
		);

		return CampTix_Plugin::PAYMENT_STATUS_PENDING;
	}

	/**
	 * Fetch an order from the Razorpay API.
	 *
	 * Isolated in its own method so that the authenticated remote call can be
	 * overridden in tests.
	 *
	 * @since  1.9
	 * @access protected
	 *
	 * @param string $razorpay_order_id
	 *
	 * @return mixed The Razorpay order entity.
	 */
	protected function fetch_razorpay_order( $razorpay_order_id ) {
		$api = $this->get_razjorpay_api();

		return $api->order->fetch( $razorpay_order_id );
	}

	/**
	 * Check if razorpay enbale or not
	 *
	 * @since  0.2
	 * @access public
	 *
	 * @return bool
	 */
	public function is_gateway_enable() {
		return ! empty( $this->camptix_options['payment_methods'][ $this->id ] );
	}


	/**
	 * Get razorpay API object
	 *
	 * @since  0.2
	 * @access private
	 *
	 * @return Razorpay\Api\Api
	 */
	private function get_razjorpay_api() {
		$merchant = $this->get_merchant_credentials();

		return new Api( $merchant['key_id'], $merchant['key_secret'] );
	}
}
