<?php

class CampTix_Payment_Method_Stripe extends CampTix_Payment_Method {
	public $id          = 'stripe';
	public $name        = 'Credit Card (Stripe)';
	public $description = 'Credit card processing, powered by Stripe.';

	/**
	 * See https://support.stripe.com/questions/which-currencies-does-stripe-support.
	 *
	 * 1.7
	 * Removing SVC, because it is no longer in circulation and is rarely used. (https://www.xe.com/currency/svc-salvadoran-colon)
	 */
	public $supported_currencies = array(
		'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BMD',
		'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DKK',
		'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL',
		'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'KES', 'KGS', 'KHR', 'KYD', 'KZT', 'LAK', 'LBP',
		'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT', 'MOP', 'MRO', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR',
		'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON',
		'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLL', 'SOS', 'SRD', 'STD', 'SZL', 'THB',
		'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'WST', 'XCD', 'YER', 'ZAR',
		'ZMW',
		// Zero decimal currencies (https://stripe.com/docs/currencies#zero-decimal)
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	public $supported_features = array(
		'refund-single' => true,
		'refund-all'    => true,
	);

	/**
	 * We can have an array to store our options.
	 * Use `$this->get_payment_options()` to retrieve them.
	 */
	protected $options = array();

	/**
	 * Runs during camptix_init, loads our options and sets some actions.
	 *
	 * @see CampTix_Addon
	 */
	public function camptix_init() {
		$this->options = array_merge(
			array(
				'api_predef'          => '',
				'api_secret_key'      => '',
				'api_public_key'      => '',
				'api_test_secret_key' => '',
				'api_test_public_key' => '',
				'sandbox'             => true,
			),
			$this->get_payment_options()
		);

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	/**
	 * Get the credentials for the API account.
	 *
	 * If a standard account is setup, this will just use the value that's
	 * already in $this->options. If a predefined account is setup, though, it
	 * will use those instead.
	 *
	 * SECURITY WARNING: This must be called on the fly, and saved in a local
	 * variable instead of $this->options. Storing the predef credentials in
	 * $this->options would result in them being exposed to the user if they
	 * switched from a predefined account to a standard one. That happens because
	 * validate_options() will not strip the predefined credentials when options
	 * are saved in this scenario, so they would be saved to the database.
	 *
	 * validate_options() could be updated to protect against that, but that's
	 * more susceptible to human error. It's simpler, and therefore safer, to
	 * just never let predefined credentials into $this->options to begin with.
	 *
	 * @return array
	 */
	public function get_api_credentials() {
		$options = array_merge( $this->options, $this->get_predefined_account( $this->options['api_predef'] ) );

		$prefix = 'api_';
		if ( true === $options['sandbox'] ) {
			$prefix = 'api_test_';
		}

		return array(
			'api_public_key' => $options[ $prefix . 'public_key' ],
			'api_secret_key' => $options[ $prefix . 'secret_key' ],
		);
	}

	/**
	 * Convert an amount in the currency's base unit to its equivalent fractional unit.
	 *
	 * Stripe wants amounts in the fractional unit (e.g., pennies), not the base unit (e.g., dollars).
	 *
	 * The data here comes from https://stripe.com/docs/currencies
	 *
	 * @param string $order_currency
	 * @param int    $base_unit_amount
	 *
	 * @return int
	 * @throws Exception
	 */
	public function get_fractional_unit_amount( $order_currency, $base_unit_amount ) {
		$fractional_amount = null;

		$currency_multipliers = array(
			// Zero-decimal currencies
			1    => array(
				'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF',
				'XOF', 'XPF',
			),
			100  => array(
				'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN',
				'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP',
				'CRC', 'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP',
				'GBP', 'GEL', 'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR',
				'ILS', 'INR', 'ISK', 'JMD', 'KES', 'KGS', 'KHR', 'KYD', 'KZT',
				'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT', 'MRO', 'MOP', 'MUR', 'MVR', 'MWK',
				'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR',
				'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLL',
				'SOS', 'SRD', 'STD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD',
				'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'WST', 'XCD', 'YER', 'ZAR', 'ZMW',
			),
		);

		foreach ( $currency_multipliers as $multiplier => $currencies ) {
			if ( in_array( $order_currency, $currencies, true ) ) {
				$fractional_amount = floatval( $base_unit_amount ) * $multiplier;
			}
		}

		if ( is_null( $fractional_amount ) ) {
			throw new Exception( "Unknown currency multiplier for $order_currency." );
		}

		return intval( $fractional_amount );
	}

	/**
	 * Add payment settings fields
	 *
	 * This runs during settings field registration in CampTix for the
	 * payment methods configuration screen. If your payment method has
	 * options, this method is the place to add them to. You can use the
	 * helper function to add typical settings fields. Don't forget to
	 * validate them all in validate_options.
	 */
	public function payment_settings_fields() {
		// Allow pre-defined accounts if any are defined by plugins.
		if ( count( $this->get_predefined_accounts() ) > 0 ) {
			$this->add_settings_field_helper( 'api_predef', __( 'Account', 'wordcamporg' ), array( $this, 'field_api_predef' ) );
		}

		// Settings fields are not needed when a predefined account is chosen.
		// These settings fields should *never* expose predefined credentials.
		if ( ! $this->get_predefined_account() ) {
			$this->add_settings_field_helper( 'api_secret_key', __( 'Secret Key',      'wordcamporg' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'api_public_key', __( 'Publishable Key', 'wordcamporg' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'api_test_secret_key', __( 'Test Secret Key',      'wordcamporg' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'api_test_public_key', __( 'Test Publishable Key', 'wordcamporg' ), array( $this, 'field_text' ) );
			$this->add_settings_field_helper( 'sandbox',       __( 'Sandbox Mode',  'wordcamporg' ), array( $this, 'field_yesno' ),
				sprintf(
					__( 'When Sandbox Mode is enabled, the Test keys will be used for transactions. <a href="%s">Read more</a> about testing transactions with Stripe.', 'wordcamporg' ),
					'https://stripe.com/docs/testing'
				)
			);
		}
	}

	/**
	 * Predefined accounts field callback
	 *
	 * Renders a drop-down select with a list of predefined accounts
	 * to select from, as well as some js for better ux.
	 *
	 * @uses $this->get_predefined_accounts()
	 *
	 * @param array $args
	 */
	public function field_api_predef( $args ) {
		$accounts = $this->get_predefined_accounts();

		if ( empty( $accounts ) ) {
			return;
		}

		?>

		<select id="camptix-stripe-predef-select" name="<?php echo esc_attr( $args['name'] ); ?>">
			<option value=""><?php esc_html_e( 'Custom', 'wordcamporg' ); ?></option>

			<?php foreach ( $accounts as $key => $account ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['value'], $key ); ?>>
					<?php echo esc_html( $account['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<!-- Let's disable the rest of the fields unless None is selected -->
		<script>
			jQuery( document ).ready( function( $ ) {
				var select = $('#camptix-stripe-predef-select')[0];

				$( select ).on( 'change', function() {
					$( '[name^="camptix_payment_options_stripe"]' ).each( function() {
						// Don't disable myself.
						if ( this == select ) {
							return;
						}

						$( this ).prop( 'disabled', select.value.length > 0 );
						$( this ).toggleClass( 'disabled', select.value.length > 0 );
					});
				});
			});
		</script>

		<?php
	}

	/**
	 * Get an array of predefined Stripe accounts
	 *
	 * Runs an empty array through a filter, where one might specify a list of
	 * predefined stripe credentials, through a plugin or something.
	 *
	 * @static $predefs
	 *
	 * @return array An array of predefined accounts (or an empty one)
	 */
	public function get_predefined_accounts() {
		static $predefs = false;

		if ( false === $predefs ) {
			$predefs = apply_filters( 'camptix_stripe_predefined_accounts', array() );
		}

		return $predefs;
	}

	/**
	 * Get a predefined account
	 *
	 * If the $key argument is false or not set, this function will look up the active
	 * predefined account, otherwise it'll look up the one under the given key. After a
	 * predefined account is set, Stripe credentials will be overwritten during API
	 * requests, but never saved/exposed. Useful with array_merge().
	 *
	 * @param string $key
	 *
	 * @return array An array with credentials, or an empty array if key not found.
	 */
	public function get_predefined_account( $key = false ) {
		$accounts = $this->get_predefined_accounts();

		if ( false === $key ) {
			$key = $this->options['api_predef'];
		}

		if ( ! array_key_exists( $key, $accounts ) ) {
			return array();
		}

		return $accounts[ $key ];
	}

	/**
	 * Validate options
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	public function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['api_secret_key'] ) ) {
			$output['api_secret_key'] = $input['api_secret_key'];
		}

		if ( isset( $input['api_test_secret_key'] ) ) {
			$output['api_test_secret_key'] = $input['api_test_secret_key'];
		}

		if ( isset( $input['api_public_key'] ) ) {
			$output['api_public_key'] = $input['api_public_key'];
		}

		if ( isset( $input['api_test_public_key'] ) ) {
			$output['api_test_public_key'] = $input['api_test_public_key'];
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		if ( isset( $input['api_predef'] ) ) {
			// If a valid predefined account is set, erase the credentials array.
			// We do not store predefined credentials in options, only code.
			if ( $this->get_predefined_account( $input['api_predef'] ) ) {
				$output = array_merge( $output, array(
					'api_secret_key'      => '',
					'api_public_key'      => '',
					'api_test_secret_key' => '',
					'api_test_public_key' => '',
				) );
			} else {
				$input['api_predef'] = '';
			}

			$output['api_predef'] = $input['api_predef'];
		}

		return $output;
	}

	/**
	 * Watch for and process Stripe requests
	 *
	 * For Stripe we'll watch for some additional CampTix actions which may be
	 * fired from Stripe either with a redirect (cancel and return)
	 */
	public function template_redirect() {
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'stripe' != $_REQUEST['tix_payment_method'] ) {
			return;
		}

		if ( isset( $_GET['tix_action'] ) ) {
			if ( 'payment_cancel' == $_GET['tix_action'] ) {
				$this->payment_cancel();
			}

			if ( 'payment_return' == $_GET['tix_action'] ) {
				$this->payment_return();
			}

			/*
			 * TODO: We might need to add this, like with paypal, incase the transaction completes
			 *       but the user never returns to WordCamp to finalise it.. or something...
			 *
			 * This would be extra helpful to auto-cancel disputed tickets, or where
			 * delayed-settlement transfer payments are used (if they get enabled in the future).
			 *
			 * if ( 'payment_notify' == $_GET['tix_action'] ) {
			 *   $this->payment_notify();
			 * }
			 */
		}
	}

	/**
	 * Handle a canceled payment
	 *
	 * Runs when the user cancels their payment during checkout at Stripe.
	 * this will simply tell CampTix to put the created attendee drafts into to Cancelled state.
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	public function payment_cancel() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$camptix->log( sprintf( 'Running payment_cancel. Request data attached.' ), null, $_REQUEST );
		$camptix->log( sprintf( 'Running payment_cancel. Server data attached.'  ), null, $_SERVER );

		$payment_token = $_REQUEST['tix_payment_token'] ?? '';

		if ( ! $payment_token ) {
			wp_die( 'empty token' );
		}

		// Set the associated attendees to cancelled.
		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
	}


	/**
	 * Process a request to complete the order
	 *
	 * This runs when Stripe redirects the user back after the user has clicked
	 * Pay Now on Stipe. At this point, the user has been charged, so we don't
	 * verify the order, and just set it as complete. This method ends with a
	 * call to payment_result back to CampTix which will redirect the user to
	 * their tickets page, send receipts, etc.
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	public function payment_return() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$payment_token  = wp_unslash( $_REQUEST['tix_payment_token'] ?? '' );
		$stripe_session = wp_unslash( $_REQUEST['tix_stripe_session'] ?? '' );

		$camptix->log( 'User returning from Stripe', null, compact( 'payment_token' ) );

		if ( ! $payment_token || ! $stripe_session ) {
			$camptix->log( 'Dying because invalid Stripe return data', null, compact( 'payment_token', 'stripe_session' ) );
			wp_die( 'empty token' );
		}

		$order = $this->get_order( $payment_token );
		if ( ! $order ) {
			$camptix->log( "Dying because couldn't find order", null, compact( 'payment_token' ) );
			wp_die( 'could not find order' );
		}

		// Fetch the Payment details.
		$stripe  = new CampTix_Stripe_API_Client( $payment_token, $this->get_api_credentials()['api_secret_key'] );
		$session = $stripe->get_session( $stripe_session );

		if ( empty( $session['status'] ) ) {
			$camptix->log( "Dying because couldn't get Payment status", $order['attendee_id'], compact( 'payment_token', 'payment_session' ) );
			wp_die( 'could not find payment details' );
		}

		// Hmm.. Not finalised.
		if ( 'open' === $session['status'] ) {
			$payment_data = array(
				'error' => 'Error during Payment checkout',
				'data' => $session,
			);
			$camptix->log( 'Error during post-stripe checkout.', $order['attendee_id'], $session );
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
		}

		// Success! (status can only be open, or completed)
		// Technically there can be multiple charges (ie. partial payments / installments) but we don't have that enabled.
		$transaction_id = $session['payment_intent']['latest_charge'] ?? '';

		/**
		 * Note that when returning a successful payment, CampTix will be
		 * expecting the transaction_id and transaction_details array keys.
		 */
		$payment_data = array(
			'transaction_id'      => $transaction_id,
			'transaction_details' => array(
				'raw' => $session,
			),
		);

		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );
	}

	/**
	 * Process a checkout request
	 *
	 * This method is the fire starter. It's called when the user initiates
	 * a checkout process with the selected payment method. In Stripe's case,
	 * if everything's okay, we redirect to the Stripe Checkout page with the
	 * details of our transaction. If something's wrong, we return a failed
	 * result back to CampTix immediately.
	 *
	 * @param string $payment_token
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	public function payment_checkout( $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		if ( empty( $payment_token ) ) {
			return false;
		}

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) ) {
			wp_die( esc_html__( 'The selected currency is not supported by this payment method.', 'wordcamporg' ) );
		}

		$order = $this->get_order( $payment_token );

		// One final check before charging the user.
		if ( ! $camptix->verify_order( $order ) ) {
			$camptix->log( 'Could not verify order', $order['attendee_id'], array( 'payment_token' => $payment_token ), 'stripe' );
			wp_die( 'Something went wrong, order is not available.' );
		}

		$metadata    = array();
		$order_items = array();
		foreach ( $order['items'] as $item ) {
			$metadata[ $item['name'] ] = $item['quantity'];

			try {
				$item['fractional_price'] = $this->get_fractional_unit_amount( $this->camptix_options['currency'], $item['price'] );
			} catch ( Exception $e ) {
				$item['fractional_price'] = $item['price'];
			}

			// Prefix the Event name to the line item.
			$item['name'] = $this->camptix_options['event_name'] . ': ' . $item['name'];

			$order_items[] = $item;
		}

		$return_url = add_query_arg(
			array(
				'tix_action'         => 'payment_return',
				'tix_payment_token'  => $payment_token,
				'tix_payment_method' => 'stripe',
				'tix_stripe_session' => '{CHECKOUT_SESSION_ID}',
			),
			$camptix->get_tickets_url()
		);

		$cancel_url = add_query_arg(
			array(
				'tix_action'         => 'payment_cancel',
				'tix_payment_token'  => $payment_token,
				'tix_payment_method' => 'stripe',
			),
			$camptix->get_tickets_url()
		);

		$receipt_email = wp_unslash( $_REQUEST['tix_stripe_receipt_email'] ?? '' );
		if ( ! $receipt_email && 1 === count( array_unique( wp_list_pluck( $_REQUEST['tix_attendee_info'], 'email' ) ) ) ) {
			$receipt_email = end( $_REQUEST['tix_attendee_info'] )['email'];
		}

		$stripe  = new CampTix_Stripe_API_Client( $payment_token, $this->get_api_credentials()['api_secret_key'] );
		$session = $stripe->create_session( $this->camptix_options['event_name'], $order_items, $receipt_email, $return_url, $cancel_url, $metadata );

		$camptix->log(
			'Requesting Stripe checkout session',
			$order['attendee_id'],
			array(
				'camptix_payment_token' => $payment_token,
				'request_payload'       => compact( 'order_items', 'receipt_email' ),
				'response'              => $session,
			)
		);

		if ( ! is_wp_error( $session ) && ! empty( $session['url'] ) ) {
			wp_redirect( esc_url_raw( $session['url'] ) );
			die();
		}

		// Error has occured.
		$camptix->log( 'Error during Stripe Checkout.', null, $session );

		return $camptix->payment_result(
			$payment_token,
			CampTix_Plugin::PAYMENT_STATUS_FAILED,
			array(
				'error_code' => is_wp_error( $session ) ? $session->get_error_code() : '',
				'raw'        => $session,
			)
		);
	}

	/**
	 * Submits a single, user-initiated refund request to Stripe and returns the result.
	 *
	 * @param string $payment_token
	 *
	 * @return int One of the CampTix_Plugin::PAYMENT_STATUS_{status} constants
	 */
	public function payment_refund( $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$result = $this->send_refund_request( $payment_token );

		if ( CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED === $result['status'] ) {
			$order = $this->get_order( $payment_token );

			$camptix->log( 'Stripe refund failed', $order['attendee_id'], $result, 'stripe' );

			return $camptix->payment_result(
				$payment_token,
				CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,
				$result
			);
		}

		return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_REFUNDED, $result );
	}

	/**
	 * Send a request to Stripe to refund a transaction.
	 *
	 * @param string $payment_token
	 *
	 * @return array
	 */
	public function send_refund_request( $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$result = array(
			'status'                     => CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,
			'transaction_id'             => '',
			'refund_transaction_id'      => '',
			'refund_transaction_details' => '',
		);

		$order          = $this->get_order( $payment_token );
		$transaction_id = $camptix->get_post_meta_from_payment_token( $payment_token, 'tix_transaction_id' );

		if ( empty( $order ) || ! $transaction_id ) {
			$camptix->log( 'Could not refund because could not find order', null, array( 'payment_token' => $payment_token ), 'stripe' );

			return $result;
		}

		$metadata = array(
			'Refund reason' => filter_input( INPUT_POST, 'tix_refund_request_reason', FILTER_UNSAFE_RAW ),
		);

		// Create a new Idempotency token for the refund request.
		// The same token can't be used for both a charge and a refund.
		$idempotency_token = md5( 'tix-idempotency-token' . $payment_token . time() . rand( 1, 9999 ) );
		$credentials       = $this->get_api_credentials();

		$stripe = new CampTix_Stripe_API_Client( $idempotency_token, $credentials['api_secret_key'] );
		$refund = $stripe->request_refund( $transaction_id, $metadata );

		if ( is_wp_error( $refund ) ) {
			$result['refund_transaction_details'] = array(
				'errors'     => $refund->errors,
				'error_data' => $refund->error_data,
			);

			return $result;
		}

		$result['status']                     = CampTix_Plugin::PAYMENT_STATUS_REFUNDED;
		$result['transaction_id']             = $refund['charge'];
		$result['refund_transaction_id']      = $refund['id'];
		$result['refund_transaction_details'] = array(
			'raw' => array(
				'refund_transaction_id' => $refund['id'],
				'refund'                => $refund,
			),
		);

		return $result;
	}
}

camptix_register_addon( 'CampTix_Payment_Method_Stripe' );

/**
 * Class CampTix_Stripe_API_Client
 *
 * A simple client for the Stripe API to handle the simple needs of CampTix.
 */
class CampTix_Stripe_API_Client {
	/**
	 * @var string
	 */
	protected $payment_token = '';

	/**
	 * @var string
	 */
	protected $api_secret_key = '';

	/**
	 * @var string
	 */
	protected $user_agent = '';

	/**
	 * @var string
	 */
	protected $currency = '';

	/**
	 * CampTix_Stripe_API_Client constructor.
	 *
	 * @param string $payment_token
	 * @param string $api_secret_key
	 */
	public function __construct( $payment_token, $api_secret_key ) {
		/* @var CampTix_Plugin $camptix */
		global $camptix;

		$camptix_options = $camptix->get_options();

		$this->payment_token  = $payment_token;
		$this->api_secret_key = $api_secret_key;
		$this->user_agent     = 'CampTix/' . $camptix->version;
		$this->currency       = $camptix_options['currency'];
	}

	/**
	 * Get the API's endpoint URL for the given request type.
	 *
	 * @param string $request_type 'refund', 'create_session', 'get_session'.
	 *
	 * @return string
	 */
	protected function get_request_url( $request_type, &$args ) {
		$request_url = '';

		$api_base = 'https://api.stripe.com/';

		switch ( $request_type ) {
			case 'refund':
				$request_url = $api_base . 'v1/refunds';
				break;
			case 'create_session':
				$request_url = $api_base . '/v1/checkout/sessions';
				break;
			case 'get_session':
				$request_url = $api_base . '/v1/checkout/sessions/' . ( $args['session_id'] ?? '' );
				unset( $args['session_id'] );
				break;
		}

		return $request_url;
	}

	/**
	 * Send a request to the API and do basic processing on the response.
	 *
	 * @param string $type The type of API request. 'refund', 'create_session', or 'get_session'.
	 * @param array  $args Parameters that will populate the body of the request.
	 *
	 * @return array|WP_Error
	 */
	protected function send_request( $type, $args, $method = 'POST' ) {
		$request_url = $this->get_request_url( $type, $args );

		if ( ! $request_url ) {
			return new WP_Error(
				'camptix_stripe_invalid_request_type',
				sprintf(
					__( '%s is not a valid request type.', 'wordcamporg' ),
					esc_html( $type )
				)
			);
		}

		$request_args = array(
			'method'     => $method,
			'user-agent' => $this->user_agent,
			'timeout'    => 30, // The default of 5 seconds can result in frequent timeouts.

			'body' => $args ? $args : null,

			'headers' => array(
				'Authorization'   => 'Bearer ' . $this->api_secret_key,
				'Idempotency-Key' => $this->payment_token,
			),
		);

		$response = wp_remote_request( $request_url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $response_code ) {
			if ( ! is_array( $response_body ) || ! isset( $response_body['error'] ) ) {
				return new WP_Error(
					'camptix_stripe_unexpected_response',
					__( 'An unexpected error occurred.', 'wordcamporg' ),
					$response
				);
			}

			return $this->handle_error( $response_code, $response_body['error'] );
		}

		return $response_body;
	}

	/**
	 * Parse error codes and messages from the API.
	 *
	 * @param int   $error_code
	 * @param array $error_content
	 *
	 * @return WP_Error
	 */
	protected function handle_error( $error_code, $error_content ) {
		$error = new WP_Error();

		switch ( $error_content['type'] ) {
			case 'card_error':
				if ( isset( $error_content['message'] ) ) {
					$reason = $error_content['message'];
				} elseif ( isset( $error_content['decline_code'] ) ) {
					$reason = $error_content['decline_code'];
				} elseif ( isset( $error_content['code'] ) ) {
					$reason = $error_content['code'];
				} else {
					$reason = __( 'Unspecified error', 'wordcamporg' );
				}

				$message = sprintf(
					__( 'Card error: %s', 'wordcamporg' ),
					esc_html( $reason )
				);
				break;
			default:
				$message = sprintf(
					__( '%1$d error: %2$s', 'wordcamporg' ),
					$error_code,
					esc_html( $error_content['type'] )
				);
				break;
		}

		$error->add(
			sprintf( 'camptix_stripe_request_error_%d', $error_code ),
			$message,
			$error_content
		);

		return $error;
	}

	/**
	 * Create a checkout session on the Stripe API.
	 *
	 * @param string $description   The description of the payment for the card statement.
	 * @param array  $items         The line items for the payment.
	 * @param string $receipt_email The email of the user, if known.
	 * @param string $return_url    The location to redirect to upon a success.
	 * @param string $cancel_url    The location to redirect to upon a payment cancelation.
	 * @param array  $metadata      Any Key-Value pairs to attach to the payment.
	 *
	 * @return array|WP_Error
	 */
	public function create_session( $description, $items, $receipt_email, $return_url, $cancel_url, $metadata ) {
		$line_items = array();
		foreach ( $items as $item ) {
			$line_item = array(
				'quantity'     => $item['quantity'],
				'price_data'   => array(
					'product_data' => array(
						'name'        => $item['name'],
					),
					'unit_amount' => $item['fractional_price'],
					'currency'    => $this->currency,
				),
			);

			if ( ! empty( $item['description'] ) ) {
				$line_item['price_data']['product_data']['description'] = $item['description'];
			}

			$line_items[] = $line_item;
		}

		$statement_descriptor = sanitize_text_field( $description );
		$statement_descriptor = str_replace( array( '<', '>', '"', "'" ), '', $statement_descriptor );
		$statement_descriptor = $this->trim_string( $statement_descriptor, 22 );

		$args = array(
			'mode'                => 'payment',
			'submit_type'         => 'book',
			'success_url'         => $return_url,
			'cancel_url'          => $cancel_url,
			'line_items'          => $line_items,
			'payment_intent_data' => array(
				'description'          => $description, // Displayed in Stripe Dashboard.
				'statement_descriptor' => $statement_descriptor, // Displayed on purchasers statement.
			),
		);

		if ( ! empty( $receipt_email ) && is_email( $receipt_email ) ) {
			$args['customer_email'] = $receipt_email;
		}

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			$args['metadata'] = $this->clean_metadata( $metadata );
		}

		return $this->send_request( 'create_session', $args );
	}

	/**
	 * Retrieve a Payment session, expanding the payment_intent.
	 *
	 * @param string $session_id The Stripe Session ID.
	 *
	 * @return array|WP_Error
	 */
	public function get_session( $session_id ) {
		return $this->send_request(
			'get_session',
			array(
				'session_id' => $session_id,
				'expand'     => array(
					'payment_intent',
				),
			),
			'GET'
		);
	}

	/**
	 * Send a refund request to the API.
	 *
	 * @param string $transaction_id
	 * @param array  $metadata       Associative array of extra data to store with the transaction.
	 *
	 * @return array|WP_Error
	 */
	public function request_refund( $transaction_id, $metadata = array() ) {
		$args = array(
			'charge' => $transaction_id,
			'reason' => 'requested_by_customer',
		);

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			$args['metadata'] = $this->clean_metadata( $metadata );
		}

		return $this->send_request( 'refund', $args );
	}

	/**
	 * Trim a string to a certain number of characters.
	 *
	 * @param string $string The original string.
	 * @param int    $chars  The max number of characters for the string.
	 * @param string $suffix A suffix to append if the string exceeds the max.
	 *
	 * @return string
	 */
	protected function trim_string( $string, $chars = 500, $suffix = '...' ) {
		if ( strlen( $string ) > $chars ) {
			if ( function_exists( 'mb_substr' ) ) {
				$string = mb_substr( $string, 0, ( $chars - mb_strlen( $suffix ) ) ) . $suffix;
			} else {
				$string = substr( $string, 0, ( $chars - strlen( $suffix ) ) ) . $suffix;
			}
		}

		return $string;
	}

	/**
	 * Clean up an array of metadata before passing to Stripe.
	 *
	 * @see https://stripe.com/docs/api#metadata
	 *
	 * @param array $metadata An associative array of metadata.
	 *
	 * @return array
	 */
	protected function clean_metadata( $metadata = array() ) {
		$cleaned = array();

		foreach ( $metadata as $key => $val ) {
			// A Stripe transaction can only have 20 metadata keys.
			if ( count( $cleaned ) > 20 ) {
				return $cleaned;
			}

			// Trim the key to 40 chars.
			$key = $this->trim_string( $key, 40, '' );

			// Trim the val to 500 chars.
			$val = $this->trim_string( $val );

			$cleaned[ $key ] = $val;
		}

		return $cleaned;
	}
}
