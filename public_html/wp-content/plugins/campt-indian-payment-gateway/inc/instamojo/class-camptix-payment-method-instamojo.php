<?php

/**
 * CampTix Instamojo Payment Method
 *
 * This class handles all Instamojo integration for CampTix
 *
 * @category       Class
 * @package        Camptix Instamojo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class CampTix_Payment_Method_Instamojo extends CampTix_Payment_Method {
	public $id = 'instamojo';
	public $name = 'Instamojo';
	public $description = 'Redefining Payments, Simplifying Lives! Empowering any business to collect money online within minutes.';
	public $supported_currencies = array( 'INR' );

	/* We can have an array to store our options. Use $this->get_payment_options() to retrieve them. */
	protected $options = array();

	function camptix_init() {
		$this->options = array_merge( array(
			'Instamojo-Api-Key'    => '',
			'Instamojo-Auth-Token' => '',
			'Instamojo-salt'       => '',
			'sandbox' => true,
		), $this->get_payment_options() );

		// IPN Listener		
		if ( $this->is_gateway_enable() ) {
			add_action( 'template_redirect', array( $this, 'template_redirect' ) );
			add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'add_attendee_info' ), 10, 3 );
		}
	}

	public function is_gateway_enable() {
		return isset( $this->camptix_options['payment_methods'][ $this->id ] );
	}

	public function add_attendee_info( $attendee, $attendee_info, $current_count ) {
		if ( ! empty( $_POST['tix_attendee_info'][ $current_count ]['phone'] ) ) {
			$attendee->phone = trim( $_POST['tix_attendee_info'][ $current_count ]['phone'] );
		}
		return $attendee;
	}


	function payment_settings_fields() {
		// code change by me start
		$this->add_settings_field_helper( __('Instamojo-Api-Key', 'campt-indian-payment-gateway'), __('Instamojo Api KEY', 'campt-indian-payment-gateway'), array( $this, 'field_text' ) );
		$this->add_settings_field_helper( __('Instamojo-Auth-Token', 'campt-indian-payment-gateway'), __('Instamojo Auth Token', 'campt-indian-payment-gateway') , array( $this, 'field_text' ) );
		$this->add_settings_field_helper( __('Instamojo-salt', 'campt-indian-payment-gateway'), __('Instamojo Salt', 'campt-indian-payment-gateway'), array( $this, 'field_text' ) );		
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'campt-indian-payment-gateway' ), array( $this, 'field_yesno' ),
			__( "The Instamojo Sandbox is a way to test payments without using real accounts and transactions. If you'd like to use Sandbox Mode, you'll need to create a <a href='https://docs.instamojo.com/docs/what-is-the-difference-between-the-sandbox-and-production-environment'>Instamojo Developer</a> account and obtain the API credentials for your sandbox user.",'campt-indian-payment-gateway' )
		);
	}

	function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['Instamojo-Api-Key'] ) ) {
			$output['Instamojo-Api-Key'] = $input['Instamojo-Api-Key'];
		}
		if ( isset( $input['Instamojo-Auth-Token'] ) ) {
			$output['Instamojo-Auth-Token'] = $input['Instamojo-Auth-Token'];
		}
		if ( isset( $input['Instamojo-salt'] ) ) {
			$output['Instamojo-salt'] = $input['Instamojo-salt'];
		}
		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}
		return $output;
	}

	function template_redirect() {
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'instamojo' != $_REQUEST['tix_payment_method'] ) {
			return;
		}

		if ( isset( $_GET['tix_action'] ) ) {
			if ( 'payment_cancel' == $_GET['tix_action'] ) {
				$this->payment_cancel();
			}

			if ( 'payment_return' == $_GET['tix_action'] ) {
				$this->payment_return();
			}

			if ( 'payment_notify' == $_GET['tix_action'] ) {
				$this->payment_notify();
			}
		}
	}

	function payment_return() {
		global $camptix;

		$this->log( sprintf( 'Running payment_return. Request data attached.' ), null, $_REQUEST );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
		if ( empty( $payment_token ) ) {
			return;
		}
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
		if ( empty( $attendees ) ) {
			return;
		}
		$attendee = reset( $attendees );

		$payment_data = array(
			'transaction_id'      => $_REQUEST['payment_id'] ?? '',
			'transaction_details' => $_REQUEST,
		);
		unset( $payment_data['transaction_details']['tix_action'], $payment_data['transaction_details']['tix_payment_method'] );

		/*
		 * A return is public and proves nothing on its own, so it may record a result
		 * only for a payment Instamojo confirms was credited against the request
		 * created for this order. Anything else is left alone and shown the ticket
		 * page: the webhook is what settles orders, and it is signed.
		 */
		if ( 'draft' == $attendee->post_status ) {
			$request = $this->get_payment_request( isset( $_REQUEST['payment_request_id'] ) ? trim( $_REQUEST['payment_request_id'] ) : '' );

			if ( $this->payment_request_owns_token( $request, $payment_token ) && $this->get_credit_payment( $request ) ) {
				return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING, $payment_data );
			}
		}

		$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
		$url          = add_query_arg(
			array(
				'tix_action'       => 'access_tickets',
				'tix_access_token' => $access_token,
			),
			$camptix->get_tickets_url()
		);
		wp_safe_redirect( esc_url_raw( $url . '#tix' ) );
		die();
	}	
	 /* Runs when Instamjo Money sends an ITN signal. Verify the payload and use $this->payment_result
	 to signal a transaction result back to CampTix.*/
	
	function payment_notify() {
		global $camptix;

		$this->log( sprintf( 'Running payment_notify. Request data attached.' ), null, $_REQUEST );

		//Basic PHP script to handle Instamojo RAP webhook.
		$instamojo_salt  = $this->options['Instamojo-salt'];
		$data         = $_POST;
		$mac_provided = $data['mac'] ?? '';  // Get the MAC from the POST data, if it sent one.
		unset( $data['mac'] );  // Remove the MAC key from the data.
		$ver   = explode( '.', phpversion() );
		$major = (int) $ver[0];
		$minor = (int) $ver[1];
		if ( $major >= 5 and $minor >= 4 ) {
			ksort( $data, SORT_STRING | SORT_FLAG_CASE );
		} else {
			uksort( $data, 'strcasecmp' );
		}

		$payment_data = array(
			'transaction_id'      => $data['payment_id'] ?? '',
			'transaction_details' => $data,
		);
		unset( $payment_data['transaction_details']['tix_action'], $payment_data['transaction_details']['tix_payment_method'] );

		// You can get the 'salt' from Instamojo's developers page(make sure to log in first): https://www.instamojo.com/developers
		// Pass the 'salt' without <>
		$mac_calculated = hash_hmac( "sha1", implode( "|", $data ), $instamojo_salt );

		/*
		 * Instamojo signs its webhooks, so a request that does not carry a signature
		 * made with the merchant salt establishes nothing about an order and must not
		 * move one. An unconfigured salt is treated the same way: hash_hmac() still
		 * returns a value for an empty key, but it rests on no secret, so the comparison
		 * would stop meaning anything.
		 */
		if ( ! $instamojo_salt || ! $mac_provided || ! hash_equals( $mac_calculated, (string) $mac_provided ) ) {
			$this->log( 'Refusing Instamojo webhook: the signature did not verify.', null, array_keys( $data ) );

			wp_die( esc_html__( 'Invalid signature.', 'campt-indian-payment-gateway' ), '', array( 'response' => 403 ) );
		}

		// Request is valid, but this might be a webhook for a timed out payment / failed payment, where one has actually passed.
		// Check the payment request to see what other statuses are.
		$request = $this->get_payment_request( $data['payment_request_id'] );
		if ( $request ) {
			$payment_data['payment_request'] = $request;
		}

		/*
		 * The signature covers the posted body only. The payment token lives in the
		 * query string, which it does not cover, so confirm with Instamojo that this
		 * payment request is the one created for that order.
		 */
		$payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		if ( ! $this->payment_request_owns_token( $request, $payment_token ) ) {
			$this->log( 'Refusing Instamojo webhook: it does not belong to the order it names.', null, array_keys( $data ) );

			wp_die( esc_html__( 'Unknown payment request.', 'campt-indian-payment-gateway' ), '', array( 'response' => 400 ) );
		}

		// Instamojo is calling, not a buyer, so no redirect to a ticket page.
		$credit = $this->get_credit_payment( $request );

		if ( $credit ) {
			return $this->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
				array(
					'transaction_id'      => $credit->payment_id,
					'transaction_details' => $credit,
					'payment_request'     => $request,
				),
				false
			);
		}

		/*
		 * Instamojo has credited nothing against this request. Take the signed body's
		 * word for a failure, but hold a claimed success it cannot confirm for an
		 * organizer rather than completing the order on it.
		 */
		$status = ( isset( $data['status'] ) && 'Credit' === $data['status'] )
			? CampTix_Plugin::PAYMENT_STATUS_PENDING
			: CampTix_Plugin::PAYMENT_STATUS_FAILED;

		return $this->payment_result( $payment_token, $status, $payment_data, false );
	}

	/**
	 * Confirm that an Instamojo payment request is the one created for a CampTix order.
	 *
	 * Checkout hands Instamojo a redirect and a webhook URL that carry this order's
	 * payment token, and the API echoes both back. A token read out of a fetched
	 * request is therefore Instamojo stating which order a payment belongs to, unlike
	 * the token in an incoming request, which the sender chooses.
	 *
	 * @param object|false $request       A payment request from get_payment_request().
	 * @param string       $payment_token The payment token the incoming request names.
	 *
	 * @return bool
	 */
	protected function payment_request_owns_token( $request, $payment_token ) {
		if ( ! $request || ! $payment_token ) {
			return false;
		}

		foreach ( array( 'webhook', 'redirect_url' ) as $field ) {
			if ( empty( $request->$field ) ) {
				continue;
			}

			parse_str( (string) wp_parse_url( $request->$field, PHP_URL_QUERY ), $args );

			if ( ! empty( $args['tix_payment_token'] ) && hash_equals( (string) $args['tix_payment_token'], (string) $payment_token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the credited payment on an Instamojo payment request, if there is one.
	 *
	 * A request can carry several attempts; only a `Credit` one means money moved.
	 *
	 * @param object|false $request
	 *
	 * @return object|null
	 */
	protected function get_credit_payment( $request ) {
		if ( empty( $request->payments ) ) {
			return null;
		}

		foreach ( (array) $request->payments as $payment ) {
			if ( isset( $payment->status ) && 'Credit' === $payment->status ) {
				return $payment;
			}
		}

		return null;
	}

	public function payment_checkout( $payment_token ) {

		if ( ! $payment_token || empty( $payment_token ) ) {
			return false;
		}
		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) )
			die( __( 'The selected currency is not supported by this payment method.', 'campt-indian-payment-gateway' ) );

		$return_url = add_query_arg( array(
			'tix_action'         => 'payment_return',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => 'instamojo',
		), $this->get_tickets_url() );

		$cancel_url = add_query_arg( array(
			'tix_action'         => 'payment_cancel',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => 'instamojo',
		), $this->get_tickets_url() );

		$notify_url = add_query_arg( array(
			'tix_action'         => 'payment_notify',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => 'instamojo',
		), $this->get_tickets_url() );

		$order = $this->get_order( $payment_token );

		$instamojo_key   = $this->options['Instamojo-Api-Key'];
		$instamojo_token = $this->options['Instamojo-Auth-Token'];
		$instamojo_salt  = $this->options['Instamojo-salt'];

		$order_amount = $order['total'];
		if ( isset( $this->camptix_options['event_name'] ) ) {
			$productinfo = $this->camptix_options['event_name'];
		} else {
			$productinfo = 'Ticket for Order - ' . $payment_token;
		}

		$attendees = get_posts(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'any',
				'meta_query'  => array(
					array(
						'key'     => 'tix_payment_token',
						'compare' => '=',
						'value'   => $payment_token,
					),
				),
			)
		);

		// Use the first attendee's details as the buyer info.
		foreach ( $attendees as $attendee ) {
			$email = $attendee->tix_email;
			$name  = $attendee->tix_first_name . ' ' . $attendee->tix_last_name;
			break;
		}

		// Use the first attendee with a valid phone number. This may differ from the buyer.
		foreach ( $attendees as $attendee ) {
			$phone = $this->sanitize_phone_for_instamojo( $attendee->tix_phone );

			// Use the first attendee entry with a valid phone number, hopefully the first one.
			if ( $phone ) {
				break;
			}
		}

		$url = $this->options['sandbox'] ? 'https://test.instamojo.com/api/1.1/payment-requests/' : 'https://www.instamojo.com/api/1.1/payment-requests/';

		$payload = Array(
			'purpose'                 => substr( $productinfo, 0, 30 ), // https://github.com/wpindiaorg/camptix-indian-payments/issues/45#issuecomment-392804508
			'amount'                  => $order_amount,
			'phone'                   => $phone,
			'buyer_name'              => $name,
			'redirect_url'            => $return_url,
			'send_email'              => false,
			'webhook'                 => $notify_url,
			'send_sms'                => false,
			'email'                   => $email,
			'allow_repeated_payments' => false,
		);

		$params = array(
			'method' => 'POST',
			'sslverify' => true,
			'timeout'   => 60,
			'headers'   => array(

				'Accept'       => 'application/json',
				'Content-Type' => 'application/json;charset=UTF-8',
				'X-Api-Key'  =>  $instamojo_key,
				'X-Auth-Token' => $instamojo_token
			),
			'body' => json_encode($payload)

		);

		// GET a response.
		$response = wp_remote_post( $url, $params );

		// Check to see if the request was valid.
		if ( ! is_wp_error( $response )  ) {
			$json_decode = json_decode( $response['body']);
			if ( empty( $json_decode->success ) ) {
				$error_messages = '';
				foreach ( $json_decode->message as $key => $value ) {
					$error_messages .= esc_html( $key . ' : ' . implode( ', ', (array) $value ) ) . '<br />';
				}
				wp_die(
					'<h1>Instamojo Payment Gateway Error</h1>' .
					sprintf(
						__( 'Error: %s', 'campt-indian-payment-gateway' ),
						$error_messages ? $error_messages : 'Unknown error occurred'
					),
					'Instamojo Payment Gateway Error'
				);
				return;
			}

			if ( empty(  $json_decode->payment_request->longurl ) ) {
				wp_die(
					'<h1>Instamojo Payment Gateway Error</h1>' .
					__( 'Error: Invalid payment URL from Instamojo', 'campt-indian-payment-gateway' ),
					'Instamojo Payment Gateway Error'
				);
				return;
			}

			$long_url = $json_decode->payment_request->longurl;
			header( 'Location:' . $long_url );
		} else {
			wp_die(
				'<h1>Instamojo Payment Gateway Error</h1>' .
				__( 'Invalid Instamojo Access Key & Token, or Instamojo unavailable.', 'campt-indian-payment-gateway' ),
				'Instamojo Payment Gateway Error'
			);
			return;
		}

		return;

	}

	/**
	 * Sanitize a phone number into the expected format.
	 *
	 * @param string $phone The phone number to sanitize.
	 * @return bool|string The sanitized phone number, false on failure.
	 */
	public function sanitize_phone_for_instamojo( $phone ) {
		$phone = ltrim( $phone, '0' );
		$phone = ltrim( $phone, '+91' ); // Remove '+91' CountyCode of India : Not supported by Instamojo. issue #43, #46
		$phone = ltrim( $phone, '+' ); // Remove '+' for international attendee : Not supported by Instamojo. issue #43, #46
		$phone = str_replace( array( ' ', '-', '.' ), '', $phone ); // Remove special characters : Not supported by Instamojo. issue #43, #46
		if ( strlen($phone) > 10 ) {
			$phone = substr( $phone, -10 );
			$phone = ltrim( $phone, '0' );
			if ( strlen($phone) <= 9 ) {
				$phone = str_pad( $phone, 10, '9', STR_PAD_LEFT);
			}
		} elseif ( strlen($phone) <= 9 ) {
				$phone = str_pad( $phone, 10, '9', STR_PAD_LEFT);
		} else {
			$phone = $phone; // Instamojo is expecting a 10 digit value.
		}

		// Indian mobile numbers start with 9,8,7, or 6. issue #43, #46
		if ( ! preg_match( "/^[6-9][0-9]{9}$/", $phone ) ) {
			return false;
		}
	
		return $phone;
	}

	/**
	 * Runs when the user cancels their payment during checkout at Instamojo.
	 * his will simply tell CampTix to put the created attendee drafts into to Cancelled state.
	 */
	public function payment_cancel() {
		global $camptix;

		$this->log( sprintf( 'Running payment_cancel. Request data attached.' ), null, $_REQUEST );

		$payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		if ( !$payment_token ) {
			die( __( 'Empty Token', 'campt-indian-payment-gateway' ) );
		}
		// Set the associated attendees to cancelled.
		return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
	}

	/**
	 * Get payment request details from Instamojo.
	 *
	 * @param string $payment_request_id Payment Request ID.
	 * @return object|false
	 */
	public function get_payment_request( $payment_request_id ) {
		$url = 'https://' . ( $this->options['sandbox'] ? 'test' : 'www' ) . '.instamojo.com/api/1.1/payment-requests/' . $payment_request_id . '/';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json;charset=UTF-8',
					'X-Api-Key'    => $this->options['Instamojo-Api-Key'],
					'X-Auth-Token' => $this->options['Instamojo-Auth-Token'],
				),
			)
		);

		return json_decode( wp_remote_retrieve_body( $response ) )->payment_request ?? false;
	}
}
