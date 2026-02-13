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
			add_filter( 'camptix_register_order_summary_header', array( $this, 'add_order_id_field' ), 10, 3 );
			add_filter( 'camptix_indian_payments_localize_vars', array( $this, 'add_localize_vars' ), 10, 1 );
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
	 * @param $form_heading
	 *
	 * @return mixed
	 */
	public function add_order_id_field( $form_heading ) {
		global $camptix;

		// $api         = $this->get_razjorpay_api();
		$tickets_info = ! empty( $_POST['tix_tickets_selected'] ) ? array_map( 'esc_attr', (array) $_POST['tix_tickets_selected'] ) : array();
		if ( isset( $_POST['tix_coupon'] ) ) {
			$coupon_id = sanitize_text_field( $_POST['tix_coupon'] );
		} else {
			$coupon_id = '';
		}

		try {
			// Order info.
			$order      = $this->razorpay_order_info( $tickets_info, $coupon_id );
			$receipt_id = uniqid( 'camtix-razorpay' );

			// Creates order.
			$api   = $this->get_razjorpay_api();
			$order = $api->order->create(
				array(
					'receipt'         => uniqid( 'camtix-razorpay' ),
					'amount'          => $order['total'] * 100,
					'currency'        => 'INR',
					'payment_capture' => true,
				)
			);

			echo sprintf(
				'<input type="hidden" name="razorpay_order_id" value="%s"><input type="hidden" name="razorpay_receipt_id" value="%s">',
				$order->id,
				$receipt_id
			);
		} catch ( Exception $e ) {
			echo '<div id="tix-errors"><div class="tix-error">';
			echo sprintf( esc_html__( 'Razorpay error: %s', 'campt-indian-payment-gateway' ), $e->getMessage() );
			echo '</div></div>';
		}

		return $form_heading;
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

		$return_url = add_query_arg( array(
			'tix_action'         => 'payment_return',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		), $camptix->get_tickets_url() );

		$info       = $this->get_order( $payment_token );
		$extra_info = array(
			'fullname'       => trim( get_post_meta( $info['attendee_id'], 'tix_first_name', true ) . ' ' . get_post_meta( $info['attendee_id'], 'tix_last_name', true ) ),
			'email'          => get_post_meta( $info['attendee_id'], 'tix_email', true ),
			'phone'          => get_post_meta( $info['attendee_id'], 'tix_phone', true ),
			'total_in_paisa' => ( $info['total'] * 100 ),
			'return_url'     => $return_url,
			'popup_title'    => $this->options['razorpay_popup_title'],
		);

		wp_send_json_success( array_merge( $info, $extra_info ) );
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
		$this->log( sprintf( 'Running payment_return. Server data attached.' ), null, $_SERVER );

		$payment_token  = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$transaction_id = esc_attr( $_GET['transaction_id'] );
		// $receipt_id     = esc_attr( $_GET['receipt_id'] );

		// Bailout.
		if ( empty( $payment_token ) || empty( $transaction_id ) ) {
			return;
		}

		// Get all attendees for order.
		$attendees = get_posts(
			array(
				'posts_per_page' => 1,
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

		// Bailout.
		if ( empty( $attendees ) ) {
			return;
		}

		// Reset attendees.
		$attendee = reset( $attendees );

		// Complete payment
		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $_GET );

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

	/**
	 * Array of selected ticket and there quantity
	 * key:value ticket_id:quantity
	 *
	 * @since  0.2
	 * @access pricate
	 *
	 * @param array  $tickets
	 * @param string $coupon_code
	 *
	 * @return array
	 */
	private function razorpay_order_info( $tickets, $coupon_code ) {
		/* @var  CampTix_Plugin $camptix */
		global $camptix, $wpdb;

		$order_info = array(
			'tickets'  => array(),
			'quantity' => 0,
			'total'    => 0,
		);

		$total = $coupon_percentage = $coupon_price = $coupon_id = 0;

		// Bailout.
		if ( empty( $tickets ) ) {
			return $order_info;
		}

		// Get coupon detail.
		if ( ! empty( $coupon_code ) ) {
			// Get coupon.
			$coupon_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_title = %s",
					$coupon_code
				)
			);

			// Get coupon info.
			if ( ! empty( $coupon_id ) ) {
				$coupon            = get_post( $coupon_id );
				$coupon_percentage = get_post_meta( $coupon->ID, 'tix_discount_percent', true );
				$coupon_price      = get_post_meta( $coupon->ID, 'tix_discount_price', true );
			}
		}

		// Calculate total.
		foreach ( $tickets as $ticket_id => $count ) {
			$order_info['tickets'][] = $ticket_id;
			$order_info['quantity']  += $count;

			$price = get_post_meta( $ticket_id, 'tix_price', true );
			if ( $coupon_percentage ) {
				$price -= ( ( $price * $coupon_percentage ) / 100 );
			} elseif ( $coupon_price ) {
				$price -= $coupon_price;
			}

			$total += $price * $count;
		}

		$order_info['total'] = $total;

		return $order_info;
	}
}
