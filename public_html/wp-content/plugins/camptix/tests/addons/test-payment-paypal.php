<?php
defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/trait-wordcamp-root-blog.php';

/**
 * @covers CampTix_Payment_Method_PayPal
 */
class Test_Camptix_Payment_PayPal_Addon extends \WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * Every URL the gateway asked for during the last drive_*() call.
	 *
	 * @var string[]
	 */
	protected $requested_urls = array();

	/**
	 * Every message CampTix logged during the last drive() call.
	 *
	 * @var string[]
	 */
	protected $logged = array();

	/**
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::create_wordcamp_root_blog( $factory );
	}

	/**
	 * Tears down the shared fixtures created in wpSetUpBeforeClass().
	 */
	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	/**
	 * Clear the request state the gateway reads.
	 */
	public function tear_down() {
		unset( $_REQUEST['tix_payment_token'], $_REQUEST['tix_paypal_ipn'], $_REQUEST['token'], $_REQUEST['PayerID'] );
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * A PayPal gateway instance with its options loaded and the currency set to USD.
	 *
	 * @return CampTix_Payment_Method_PayPal
	 */
	protected function make_configured_paypal() {
		$paypal = new CampTix_Payment_Method_PayPal();
		$paypal->camptix_init();
		$paypal->load_options();
		$paypal->camptix_options['currency'] = 'USD';

		return $paypal;
	}

	/**
	 * Build a CampTix order.
	 *
	 * @param array $spec token, total, status, recorded (transaction id), method, and
	 *                    `bare` to omit tix_order entirely, as the CampTix 1.1 upgrade
	 *                    leaves migrated attendees.
	 *
	 * @return int The attendee id.
	 */
	protected function make_order( $spec ) {
		$spec = array_merge(
			array(
				'token'    => 'tok_test',
				'total'    => 750,
				'status'   => 'draft',
				'recorded' => '',
				'method'   => 'paypal',
				'bare'     => false,
			),
			$spec
		);

		$attendee = self::factory()->post->create(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => $spec['status'],
			)
		);

		update_post_meta( $attendee, 'tix_payment_token', $spec['token'] );
		update_post_meta( $attendee, 'tix_access_token', 'acc_' . $spec['token'] );

		if ( $spec['recorded'] ) {
			update_post_meta( $attendee, 'tix_transaction_id', $spec['recorded'] );
		}

		if ( $spec['bare'] ) {
			return $attendee;
		}

		update_post_meta( $attendee, 'tix_payment_method', $spec['method'] );

		// verify_order() recomputes the order from the ticket, so the price has to agree.
		$ticket = self::factory()->post->create(
			array(
				'post_type'   => 'tix_ticket',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $ticket, 'tix_price', $spec['total'] );
		update_post_meta( $ticket, 'tix_quantity', 100 );

		update_post_meta(
			$attendee,
			'tix_order',
			array(
				'items' => array(
					array(
						'id'          => $ticket,
						'name'        => 'Ticket',
						'description' => 'A ticket',
						'price'       => $spec['total'],
						'quantity'    => 1,
					),
				),
				'total' => $spec['total'],
			)
		);

		return $attendee;
	}

	/** A GetTransactionDetails response body, as PayPal's NVP API returns it. */
	protected function transaction_details( $fields = array() ) {
		return http_build_query(
			array_merge(
				array(
					'ACK'             => 'Success',
					'PAYMENTSTATUS'   => 'Completed',
					'TRANSACTIONTYPE' => 'cart',
					'TRANSACTIONID'   => 'TXN_PAID',
					'AMT'             => '750.00',
					'CURRENCYCODE'    => 'USD',
				),
				$fields
			)
		);
	}

	/** Wrap a body in an HTTP response. */
	protected function response( $body, $code = 200 ) {
		return array(
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'headers'  => array(),
			'body'     => $body,
		);
	}

	/**
	 * Run one of the gateway's public entry points with PayPal stubbed out.
	 *
	 * Both endings are turned into exceptions so the test can see which one ran: the
	 * refusals call wp_die(), and a settlement redirects and dies inside
	 * payment_result(). The die carries its status code and message, since the IPN
	 * path is judged on the code PayPal reads and the return path on what the buyer
	 * is told.
	 *
	 * @param callable $run       Invokes the entry point.
	 * @param callable $http_stub Receives ( $args, $url ) and returns a response.
	 *
	 * @throws Exception Anything raised that is not one of those two endings.
	 *
	 * @return array{outcome:string,code:string,message:string}
	 */
	protected function drive( $run, $http_stub ) {
		$this->requested_urls = array();
		$this->logged         = array();

		$log_spy = function ( $message ) {
			$this->logged[] = $message;
		};

		add_action( 'camptix_log_raw', $log_spy );

		$recorder = function ( $pre, $args, $url ) use ( $http_stub ) {
			$this->requested_urls[] = $url;

			return $http_stub( $args, $url );
		};

		$die_handler = function () {
			return function ( $message, $title = '', $args = array() ) {
				throw new Exception( esc_html( 'die:' . ( $args['response'] ?? 0 ) . ' ' . $message ) );
			};
		};

		$result_spy = function ( $token, $result ) {
			throw new Exception( esc_html( 'result:' . $result . ' ' ) );
		};

		add_filter( 'pre_http_request', $recorder, 10, 3 );
		add_filter( 'wp_die_handler', $die_handler );
		add_action( 'camptix_payment_result', $result_spy, 10, 2 );

		$outcome = array(
			'outcome' => 'none',
			'code'    => '',
			'message' => '',
		);

		try {
			$run();
		} catch ( Exception $e ) {
			// Anything that is not one of the two endings above is a real failure.
			if ( ! preg_match( '/^(die|result):(\S*) ?(.*)$/s', $e->getMessage(), $matches ) ) {
				throw $e;
			}

			$outcome = array(
				'outcome' => $matches[1],
				'code'    => $matches[2],
				'message' => $matches[3],
			);
		} finally {
			remove_filter( 'pre_http_request', $recorder, 10 );
			remove_filter( 'wp_die_handler', $die_handler );
			remove_action( 'camptix_payment_result', $result_spy, 10 );
			remove_action( 'camptix_log_raw', $log_spy );
			$_POST = array();
		}

		return $outcome;
	}

	/**
	 * The binding, case by case.
	 *
	 * `%blog%` and `%other%` stand for this site and a second one sharing the same
	 * PayPal credentials. `order` is passed to make_order(); `details` overrides
	 * fields on the GetTransactionDetails response; `verify` picks what the IPN
	 * verification call answers.
	 *
	 * @dataProvider data_notify
	 */
	public function test_notify( $scenario ) {
		$scenario = array_merge(
			array(
				'order'   => array(),
				'ipn'     => array(
					'txn_id' => 'TXN_PAID', 'payment_status' => 'Completed',
				),
				'details' => array(),
				'verify'  => 'verified',
				'expect'  => 'die',
				'code'    => '200',
				'then'    => 'draft',
				'txn'     => null,
				'no_api'  => false,
				'reason'  => '',
				'entry'   => 'notify',
			),
			$scenario
		);

		$blog     = get_current_blog_id();
		$swap     = function ( $value ) use ( $blog ) {
			return str_replace( array( '%blog%', '%other%' ), array( $blog, $blog + 1 ), $value );
		};
		$paypal   = $this->make_configured_paypal();
		$attendee = null === $scenario['order'] ? null : $this->make_order( $scenario['order'] );

		$details = array_map( $swap, $scenario['details'] );
		$verify  = array(
			'verified' => $this->response( 'VERIFIED' ),
			'invalid'  => $this->response( 'INVALID' ),
			'error'    => $this->response( '', 502 ),
			'down'     => new WP_Error( 'http_request_failed', 'Connection timed out.' ),
		)[ $scenario['verify'] ];

		$back_compat = 'back_compat' === $scenario['entry'];
		$_POST       = $scenario['ipn'];

		if ( $back_compat ) {
			$_REQUEST['tix_paypal_ipn'] = 1;
		} else {
			$_REQUEST['tix_payment_token'] = $scenario['order']['token'] ?? 'tok_nobody';
		}

		$outcome = $this->drive(
			function () use ( $paypal, $back_compat ) {
				$back_compat ? $paypal->payment_notify_back_compat() : $paypal->payment_notify();
			},
			function ( $args, $url ) use ( $verify, $details ) {
				return false === strpos( $url, '/nvp' ) ? $verify : $this->response( $this->transaction_details( $details ) );
			}
		);

		$this->assertSame( $scenario['expect'], $outcome['outcome'] );
		$this->assertSame( $scenario['code'], $outcome['code'] );

		if ( $attendee && $scenario['then'] ) {
			$this->assertSame( $scenario['then'], get_post_status( $attendee ) );
		}

		if ( $attendee && null !== $scenario['txn'] ) {
			$this->assertSame( $scenario['txn'], get_post_meta( $attendee, 'tix_transaction_id', true ) );
		}

		// Which of the three gates closed, so a later refactor cannot blur them together.
		if ( $scenario['reason'] ) {
			$this->assertStringContainsString( $scenario['reason'], implode( "\n", $this->logged ) );
		}

		if ( $scenario['no_api'] ) {
			foreach ( $this->requested_urls as $requested ) {
				$this->assertStringNotContainsString( '/nvp', $requested );
			}
		}
	}

	/**
	 * @return array[]
	 */
	public function data_notify() {
		$paid     = (string) CampTix_Plugin::PAYMENT_STATUS_COMPLETED;
		$refunded = (string) CampTix_Plugin::PAYMENT_STATUS_REFUNDED;
		$refund   = array(
			'txn_id' => 'TXN_REFUND', 'parent_txn_id' => 'TXN_LEGACY', 'payment_status' => 'Refunded',
		);

		return array(
			// A completed transaction, but made for somebody else's order.
			'made for another order' => array(
				array(
					'order'   => array( 'token' => 'tok_victim' ),
					'details' => array( 'CUSTOM' => '%blog%:tok_other' ),
					'reason'  => 'the reference belongs to another order',
				),
			),

			// Many camps share one merchant, so "paid to this merchant" spans sites.
			'made on another site' => array(
				array(
					'order'   => array( 'token' => 'tok_shared' ),
					'details' => array( 'CUSTOM' => '%other%:tok_shared' ),
				),
			),

			// CUSTOM is payer-settable outside Express Checkout, so the amount backs it up.
			'does not cover the order' => array(
				array(
					'order'   => array( 'token' => 'tok_under' ),
					'details' => array(
						'CUSTOM' => '%blog%:tok_under', 'AMT' => '0.01',
					),
					'reason'  => 'paid 0.01 of 750',
				),
			),

			'paid in another currency' => array(
				array(
					'order'   => array( 'token' => 'tok_currency' ),
					'details' => array(
						'CUSTOM' => '%blog%:tok_currency', 'CURRENCYCODE' => 'MXN',
					),
					'reason'  => 'paid in MXN, the order is in USD',
				),
			),

			// A Payments Standard payment carries a type CampTix never starts.
			'a type CampTix could not have started' => array(
				array(
					'order'   => array( 'token' => 'tok_standard' ),
					'details' => array(
						'CUSTOM' => '%blog%:tok_standard', 'TRANSACTIONTYPE' => 'web-accept',
					),
					'reason'  => 'unexpected transaction type web-accept',
				),
			),

			// A free order never went to PayPal, so no transaction was made for it.
			'a free order' => array(
				array(
					'order'   => array(
						'token' => 'tok_free', 'total' => 0,
					),
					'details' => array(
						'CUSTOM' => '%blog%:tok_free', 'AMT' => '0.01',
					),
					'reason'  => 'the order has nothing to pay',
				),
			),

			// Settling this would overwrite the id the other gateway's refund reaches for.
			'an order taken by another gateway' => array(
				array(
					'order'   => array(
						'token' => 'tok_stripe', 'method' => 'stripe',
					),
					'details' => array( 'CUSTOM' => '%blog%:tok_stripe' ),
					'reason'  => 'the order was taken through another gateway',
				),
			),

			// Once an order names a transaction, only that one may act on it.
			'a second transaction for a paid order' => array(
				array(
					'order'   => array(
						'token' => 'tok_paid', 'status' => 'publish', 'recorded' => 'TXN_BUYER',
					),
					'ipn'     => array(
						'txn_id' => 'TXN_INTRUDER', 'payment_status' => 'Completed',
					),
					'details' => array(
						'TRANSACTIONID' => 'TXN_INTRUDER', 'CUSTOM' => '%blog%:tok_paid',
					),
					'then'    => 'publish',
					'txn'     => 'TXN_BUYER',
				),
			),

			// Replayed against an order that settled nothing, an old transaction has no reference.
			'a transaction that settled another order' => array(
				array(
					'order'   => array( 'token' => 'tok_target' ),
					'ipn'     => array(
						'txn_id' => 'TXN_LEGACY', 'payment_status' => 'Completed',
					),
					'details' => array( 'TRANSACTIONID' => 'TXN_LEGACY' ),
				),
			),

			'an order no token answers to' => array(
				array(
					'order'  => null,
					'no_api' => true,
				),
			),

			'an IPN with no transaction id' => array(
				array(
					'order'  => array( 'token' => 'tok_notxn' ),
					'ipn'    => array( 'payment_status' => 'Completed' ),
					'no_api' => true,
				),
			),

			/*
			 * `no_api` is what pins this one: the gate fires before the transaction is
			 * fetched, and without it the request would be turned away by the reference
			 * check instead, leaving the outcome and the order state identical.
			 */
			'an IPN with no payment status' => array(
				array(
					'no_api' => true,
					'order'  => array( 'token' => 'tok_nostatus' ),
					'ipn'   => array( 'txn_id' => 'TXN_PAID' ),
				),
			),

			// PayPal says this is not one of its messages, so it will never be acceptable.
			'an IPN PayPal will not verify' => array(
				array(
					'order'  => array( 'token' => 'tok_forged' ),
					'verify' => 'invalid',
					'code'   => '403',
				),
			),

			// Inconclusive rather than refused: ask PayPal to send it again.
			'PayPal unreachable' => array(
				array(
					'order'  => array( 'token' => 'tok_down' ),
					'verify' => 'down',
					'code'   => '503',
				),
			),

			'PayPal answering the verification with an error' => array(
				array(
					'order'  => array( 'token' => 'tok_502' ),
					'verify' => 'error',
					'code'   => '503',
				),
			),

			// No error code earns its own answer, including the one PayPal documents.
			'a transaction PayPal refuses the argument for' => array(
				array(
					'order'   => array( 'token' => 'tok_10004' ),
					'details' => array(
						'ACK' => 'Failure', 'L_ERRORCODE0' => '10004',
					),
					'code'    => '503',
				),
			),

			'a transaction PayPal will not describe' => array(
				array(
					'order'   => array( 'token' => 'tok_nofetch' ),
					'details' => array( 'ACK' => 'Failure' ),
					'code'    => '503',
				),
			),

			'its own transaction' => array(
				array(
					'order'   => array( 'token' => 'tok_good' ),
					'details' => array( 'CUSTOM' => '%blog%:tok_good' ),
					'expect'  => 'result',
					'code'    => $paid,
					'then'    => 'publish',
					'txn'     => 'TXN_PAID',
				),
			),

			// Some sites charge the gateway fee or tax on top, so it is covers, not equals.
			'more than the order total' => array(
				array(
					'order'   => array(
						'token' => 'tok_over', 'total' => 500,
					),
					'details' => array(
						'CUSTOM' => '%blog%:tok_over', 'AMT' => '521.24',
					),
					'expect'  => 'result',
					'code'    => $paid,
					'then'    => 'publish',
				),
			),

			// A refund names the original transaction, which the order already records.
			'a refund for a recorded transaction' => array(
				array(
					'order'   => array(
						'token' => 'tok_legacy', 'status' => 'publish', 'recorded' => 'TXN_LEGACY',
					),
					'ipn'     => $refund,
					'details' => array(
						'PAYMENTSTATUS' => 'Refunded', 'TRANSACTIONID' => 'TXN_LEGACY',
					),
					'expect'  => 'result',
					'code'    => $refunded,
					'then'    => 'refund',
				),
			),

			// The CampTix 1.1 upgrade left these with a transaction but no tix_order.
			'a refund for an attendee with no recorded order' => array(
				array(
					'order'   => array(
						'token' => 'tok_11', 'status' => 'publish', 'recorded' => 'TXN_2012', 'bare' => true,
					),
					'ipn'     => array(
						'txn_id' => 'TXN_REFUND', 'parent_txn_id' => 'TXN_2012', 'payment_status' => 'Refunded',
					),
					'details' => array(
						'PAYMENTSTATUS' => 'Refunded', 'TRANSACTIONID' => 'TXN_2012',
					),
					'expect'  => 'result',
					'code'    => $refunded,
					'then'    => 'refund',
				),
			),

			// The CampTix 1.1 notify URL carries no token; back-compat finds the order by
			// transaction id, so the recorded arm matches by construction.
			'the CampTix 1.1 notify URL' => array(
				array(
					'order'  => array(
						'token' => 'tok_oldstyle', 'recorded' => 'TXN_OLDSTYLE',
					),
					'ipn'    => array(
						'txn_id' => 'TXN_OLDSTYLE', 'payment_status' => 'Completed',
					),
					'entry'  => 'back_compat',
					'expect' => 'result',
					'code'   => $paid,
					'then'   => 'publish',
				),
			),
		);
	}

	/**
	 * PayPal spells the Express Checkout type with a hyphen in its NVP responses and
	 * without one elsewhere, so the comparison ignores separators.
	 *
	 * @dataProvider data_express_checkout_types
	 */
	public function test_notify_settles_every_spelling_of_the_express_type( $type ) {
		$token = 'tok_type_' . md5( $type );

		$this->test_notify( array(
			'order'   => array( 'token' => $token ),
			'details' => array(
				'TRANSACTIONTYPE' => $type, 'CUSTOM' => '%blog%:' . $token,
			),
			'expect'  => 'result',
			'code'    => (string) CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
			'then'    => 'publish',
		) );
	}

	/**
	 * @return array[]
	 */
	public function data_express_checkout_types() {
		return array(
			'documented NVP spelling' => array( 'express-checkout' ),
			'unseparated'             => array( 'expresscheckout' ),
			'IPN-style underscore'    => array( 'express_checkout' ),
			'cart, with line items'   => array( 'cart' ),
		);
	}

	/**
	 * The return path, where `tix_payment_token` and PayPal's `token` arrive as two
	 * independent request values.
	 *
	 * DoExpressCheckoutPayment answers a repeated call with the original completed
	 * payment (error 11607) rather than refusing, so without the reference check the
	 * amount alone would let one payment settle two equally priced orders.
	 *
	 * @dataProvider data_return
	 */
	public function test_return( $checkout_details, $charged, $request_token = 'tok_ret' ) {
		$paypal   = $this->make_configured_paypal();
		$attendee = $this->make_order( array(
			'token' => 'tok_ret', 'total' => 100,
		) );
		$blog     = get_current_blog_id();

		$checkout_details = array_map(
			function ( $value ) use ( $blog ) {
				return str_replace( array( '%blog%', '%other%' ), array( $blog, $blog + 1 ), $value );
			},
			$checkout_details
		);

		$_REQUEST['tix_payment_token'] = $request_token;
		$_REQUEST['token']             = 'EC-TOKEN';
		$_REQUEST['PayerID']           = 'PAYER123';

		$outcome = $this->drive(
			function () use ( $paypal ) {
				$paypal->payment_return();
			},
			function ( $args ) use ( $checkout_details ) {
				$sent = is_array( $args['body'] ) ? $args['body'] : wp_parse_args( $args['body'] );

				if ( 'GetExpressCheckoutDetails' === ( $sent['METHOD'] ?? '' ) ) {
					return $this->response( http_build_query( array_merge(
						array(
							'ACK' => 'Success', 'PAYMENTREQUEST_0_AMT' => '100.00',
						),
						$checkout_details
					) ) );
				}

				// DoExpressCheckoutPayment. A repeat call answers with the original payment.
				return $this->response( http_build_query( array(
					'ACK'                         => 'SuccessWithWarning',
					'L_ERRORCODE0'                => '11607',
					'PAYMENTINFO_0_TRANSACTIONID' => 'TXN_ALREADY_PAID',
					'PAYMENTINFO_0_PAYMENTSTATUS' => 'Completed',
				) ) );
			}
		);

		if ( $charged ) {
			$this->assertSame( 'result', $outcome['outcome'] );
			$this->assertSame( (string) CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $outcome['code'] );
		} else {
			$this->assertSame( 'die', $outcome['outcome'] );
			$this->assertStringContainsString( 'could not confirm', $outcome['message'] );
			$this->assertSame( 'draft', get_post_status( $attendee ) );
		}
	}

	/**
	 * @return array[]
	 */
	public function data_return() {
		return array(
			'its own checkout'                  => array( array( 'PAYMENTREQUEST_0_CUSTOM' => '%blog%:tok_ret' ), true ),
			'another order of the same price'   => array( array( 'PAYMENTREQUEST_0_CUSTOM' => '%blog%:tok_other' ), false ),
			'the same token on another site'    => array( array( 'PAYMENTREQUEST_0_CUSTOM' => '%other%:tok_ret' ), false ),

			/*
			 * PayPal documents the bare key here while the fields beside it carry the
			 * prefix. This has to be a foreign reference: a matching one would be charged
			 * either way, because an unread key looks the same as an absent one.
			 */
			'another order, bare CUSTOM key'    => array( array( 'CUSTOM' => '%blog%:tok_other' ), false ),
			'its own checkout, bare CUSTOM key' => array( array( 'CUSTOM' => '%blog%:tok_ret' ), true ),
			// Predates the reference; those tokens expire within hours of checkout.
			'a checkout carrying no reference'  => array( array(), true ),

			/*
			 * get_order() resolves the token under the column collation, which ignores
			 * case, so the request can name the order with a token the reference will
			 * never equal. The check has to build it from the stored token, as
			 * reason_transaction_is_not_for_order() does.
			 */
			'a request naming the order in another case' => array(
				array( 'PAYMENTREQUEST_0_CUSTOM' => '%blog%:tok_ret' ),
				true,
				'TOK_RET',
			),
		);
	}

	/**
	 * Checkout has to send the reference, or there is nothing for PayPal to echo back.
	 */
	public function test_checkout_payload_carries_the_order_reference() {
		$paypal   = $this->make_configured_paypal();
		$attendee = $this->make_order( array( 'token' => 'tok_payload' ) );
		$order    = $paypal->get_order_by_attendee_id( $attendee );

		$payload = array();
		$paypal->fill_payload_with_order( $payload, $order, 'tok_payload' );

		$this->assertSame( get_current_blog_id() . ':tok_payload', $payload['PAYMENTREQUEST_0_CUSTOM'] );
	}

	/**
	 * Every partial refund goes to PayPal as one, with an amount, including the last
	 * tickets of an order: PayPal refuses a full refund after a partial one.
	 *
	 * @dataProvider data_partial_refund_payload
	 *
	 * @covers CampTix_Payment_Method_PayPal::payment_refund_partial
	 * @covers CampTix_Payment_Method_PayPal::send_refund_request
	 *
	 * @param string $currency          The camp's currency.
	 * @param float  $amount            The amount to refund.
	 * @param bool   $refunds_remainder Whether this refunds the rest of the order.
	 * @param string $expected_amount   The AMT sent to PayPal.
	 */
	public function test_partial_refund_payload( $currency, $amount, $refunds_remainder, $expected_amount ) {
		$paypal = $this->make_configured_paypal();
		$paypal->camptix_options['currency'] = $currency;
		$token = 'tok_partial_' . md5( $currency . $amount );
		$this->make_order( array(
			'token'    => $token,
			'status'   => 'publish',
			'recorded' => 'TXN_PAID',
		) );

		$sent = null;
		$stub = function ( $pre, $args ) use ( &$sent ) {
			$sent = $args['body'];

			return $this->response( http_build_query( array(
				'ACK'                 => 'Success',
				'REFUNDTRANSACTIONID' => 'TXN_REFUND',
				'REFUNDSTATUS'        => 'Instant',
			) ) );
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		try {
			$result = $paypal->payment_refund_partial( $token, $amount, $refunds_remainder );
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
		}

		$this->assertSame( CampTix_Plugin::PAYMENT_STATUS_REFUNDED, $result['status'] );
		$this->assertSame( 'TXN_REFUND', $result['refund_transaction_id'] );
		$this->assertSame( 'TXN_PAID', $sent['TRANSACTIONID'] );
		$this->assertSame( 'Partial', $sent['REFUNDTYPE'] );
		$this->assertSame( $expected_amount, $sent['AMT'] );
		$this->assertSame( $currency, $sent['CURRENCYCODE'] );
	}

	/**
	 * @return array
	 */
	public function data_partial_refund_payload() {
		return array(
			'some tickets'                  => array( 'USD', 10, false, '10.00' ),
			'the rest of the order'         => array( 'USD', 20.5, true, '20.50' ),
			'a currency without decimals'   => array( 'JPY', 1500, false, '1500' ),
		);
	}

	/**
	 * A full refund still goes to PayPal as one.
	 *
	 * @covers CampTix_Payment_Method_PayPal::send_refund_request
	 */
	public function test_full_refund_payload() {
		$paypal = $this->make_configured_paypal();
		$this->make_order( array(
			'token'    => 'tok_full',
			'status'   => 'publish',
			'recorded' => 'TXN_PAID',
		) );

		$sent = null;
		$stub = function ( $pre, $args ) use ( &$sent ) {
			$sent = $args['body'];

			return $this->response( http_build_query( array( 'ACK' => 'Failure' ) ) );
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		try {
			$paypal->send_refund_request( 'tok_full' );
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
		}

		$this->assertSame( 'Full', $sent['REFUNDTYPE'] );
		$this->assertArrayNotHasKey( 'AMT', $sent );
	}

	/**
	 * After a partial refund PayPal reports the transaction as partially refunded. The
	 * tickets still on the order are paid, so a later notification must not demote them
	 * to pending.
	 *
	 * @covers CampTix_Payment_Method_PayPal::get_status_from_string
	 */
	public function test_partially_refunded_transaction_is_still_paid() {
		$paypal = $this->make_configured_paypal();

		$this->assertSame( CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $paypal->get_status_from_string( 'Partially-Refunded' ) );
	}

	/**
	 * Ask PayPal about an order, answering each NVP call by its METHOD.
	 *
	 * @param CampTix_Payment_Method_PayPal $paypal  Gateway.
	 * @param string                        $token   Payment token.
	 * @param array                         $answers Responses (or WP_Errors) keyed by METHOD.
	 *
	 * @return array The reconcile_payment() result.
	 */
	protected function reconcile_with( $paypal, $token, $answers ) {
		$stub = function ( $pre, $args ) use ( $answers ) {
			return $answers[ $args['body']['METHOD'] ];
		};
		add_filter( 'pre_http_request', $stub, 10, 2 );

		try {
			return $paypal->reconcile_payment( $token );
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
		}
	}

	/** A GetExpressCheckoutDetails response. */
	protected function checkout_details( $fields ) {
		return $this->response( http_build_query( array_merge( array( 'ACK' => 'Success' ), $fields ) ) );
	}

	/**
	 * Checkout records the PayPal token and its expiry on every attendee of the order, so
	 * the sweep can ask about it and release the seats once the token can't be paid.
	 *
	 * @covers CampTix_Payment_Method_PayPal::payment_checkout
	 */
	public function test_checkout_records_the_token_and_its_expiry_on_the_whole_order() {
		$paypal = $this->make_configured_paypal();
		$first  = $this->make_order( array( 'token' => 'tok_checkout' ) );
		$second = $this->make_order( array( 'token' => 'tok_checkout' ) );

		$stub     = function () {
			return $this->response( http_build_query( array(
				'ACK' => 'Success', 'TOKEN' => 'EC-CHECKOUT',
			) ) );
		};
		$redirect = function () {
			throw new RuntimeException( 'redirected' );
		};
		add_filter( 'pre_http_request', $stub );
		add_filter( 'wp_redirect', $redirect );

		try {
			$paypal->payment_checkout( 'tok_checkout' );
			$this->fail( 'Checkout should have redirected to PayPal.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $stub );
			remove_filter( 'wp_redirect', $redirect );
		}

		foreach ( array( $first, $second ) as $attendee_id ) {
			$this->assertSame( 'EC-CHECKOUT', get_post_meta( $attendee_id, 'tix_paypal_token', true ) );
			$this->assertEqualsWithDelta( time() + 3 * HOUR_IN_SECONDS, (int) get_post_meta( $attendee_id, 'tix_paypal_token_expires_at', true ), 5 );
			$this->assertEqualsWithDelta(
				time() + 3 * HOUR_IN_SECONDS - CampTix_Payment_Method_PayPal::LOOKUP_LEAD,
				(int) get_post_meta( $attendee_id, 'tix_session_expires_at', true ),
				5,
				'due for the sweep while PayPal still answers for the token'
			);
		}
	}

	/**
	 * What PayPal says about a checkout, and what the sweep is told.
	 *
	 * @covers CampTix_Payment_Method_PayPal::reconcile_payment
	 */
	public function test_reconcile_payment_reports_what_paypal_says() {
		$paypal  = $this->make_configured_paypal();
		$expires = time() + HOUR_IN_SECONDS;
		$cases   = array(
			'not completed'  => array(
				array( 'GetExpressCheckoutDetails' => $this->checkout_details( array( 'CHECKOUTSTATUS' => 'PaymentActionNotInitiated' ) ) ),
				CampTix_Payment_Method::LOOKUP_OPEN,
			),
			'expired token'  => array(
				array(
					'GetExpressCheckoutDetails' => $this->response( http_build_query( array(
						'ACK' => 'Failure', 'L_ERRORCODE0' => '10411',
					) ) ),
				),
				CampTix_Payment_Method::LOOKUP_EXPIRED,
			),
			'other error'    => array(
				array(
					'GetExpressCheckoutDetails' => $this->response( http_build_query( array(
						'ACK' => 'Failure', 'L_ERRORCODE0' => '10002',
					) ) ),
				),
				CampTix_Payment_Method::LOOKUP_UNKNOWN,
			),
			'unreachable'    => array(
				array( 'GetExpressCheckoutDetails' => new WP_Error( 'http_request_failed', 'timed out' ) ),
				CampTix_Payment_Method::LOOKUP_UNREACHABLE,
			),
			'failed payment' => array(
				array( 'GetExpressCheckoutDetails' => $this->checkout_details( array( 'CHECKOUTSTATUS' => 'PaymentActionFailed' ) ) ),
				CampTix_Payment_Method::LOOKUP_EXPIRED,
			),
			'in progress'    => array(
				array( 'GetExpressCheckoutDetails' => $this->checkout_details( array( 'CHECKOUTSTATUS' => 'PaymentActionInProgress' ) ) ),
				CampTix_Payment_Method::LOOKUP_UNKNOWN,
			),
		);

		foreach ( $cases as $name => list( $answers, $expected ) ) {
			$token    = 'tok_' . md5( $name );
			$attendee = $this->make_order( array( 'token' => $token ) );
			update_post_meta( $attendee, 'tix_paypal_token', 'EC-' . $token );
			update_post_meta( $attendee, 'tix_paypal_token_expires_at', $expires );
			update_post_meta( $attendee, 'tix_session_expires_at', $expires - CampTix_Payment_Method_PayPal::LOOKUP_LEAD );

			$result = $this->reconcile_with( $paypal, $token, $answers );

			$this->assertSame( $expected, $result['status'], $name );
			$this->assertSame( 'draft', get_post_status( $attendee ), $name );
			if ( CampTix_Payment_Method::LOOKUP_OPEN === $expected ) {
				$this->assertSame( $expires, $result['expires_at'], 'open until the token expires' );
			}
		}

		$attendee = $this->make_order( array( 'token' => 'tok_no_paypal_token' ) );
		$result   = $this->reconcile_with( $paypal, 'tok_no_paypal_token', array() );
		$this->assertSame( CampTix_Payment_Method::LOOKUP_EXPIRED, $result['status'], 'nothing to ask about' );

		// PayPal still reports a checkout not started hours after its token expired.
		$attendee = $this->make_order( array( 'token' => 'tok_past_expiry' ) );
		update_post_meta( $attendee, 'tix_paypal_token', 'EC-PAST' );
		update_post_meta( $attendee, 'tix_paypal_token_expires_at', time() - MINUTE_IN_SECONDS );
		$result = $this->reconcile_with(
			$paypal,
			'tok_past_expiry',
			array( 'GetExpressCheckoutDetails' => $this->checkout_details( array( 'CHECKOUTSTATUS' => 'PaymentActionNotInitiated' ) ) )
		);
		$this->assertSame( CampTix_Payment_Method::LOOKUP_EXPIRED, $result['status'], 'gone by the expiry recorded at checkout' );
		$this->assertSame( 'draft', get_post_status( $attendee ), 'the sweep times it out, not the lookup' );
	}

	/**
	 * A completed checkout whose result was never recorded settles the order, after the same
	 * checks as the IPN; a transaction that isn't this order's leaves it for someone to look at.
	 *
	 * @covers CampTix_Payment_Method_PayPal::reconcile_payment
	 */
	public function test_reconcile_payment_settles_a_completed_checkout_after_the_ipn_checks() {
		$paypal    = $this->make_configured_paypal();
		$completed = $this->checkout_details(
			array(
				'CHECKOUTSTATUS'                 => 'PaymentActionCompleted',
				'PAYMENTREQUEST_0_TRANSACTIONID' => 'TXN_PAID',
			)
		);

		$paid = $this->make_order( array( 'token' => 'tok_paid_late' ) );
		update_post_meta( $paid, 'tix_paypal_token', 'EC-PAID' );
		$result = $this->reconcile_with(
			$paypal,
			'tok_paid_late',
			array(
				'GetExpressCheckoutDetails' => $completed,
				'GetTransactionDetails'     => $this->response( $this->transaction_details( array( 'CUSTOM' => get_current_blog_id() . ':tok_paid_late' ) ) ),
			)
		);
		$this->assertSame( CampTix_Payment_Method::LOOKUP_SETTLED, $result['status'] );
		$this->assertSame( 'publish', get_post_status( $paid ) );
		$this->assertSame( 'TXN_PAID', get_post_meta( $paid, 'tix_transaction_id', true ) );

		$other = $this->make_order( array( 'token' => 'tok_not_ours' ) );
		update_post_meta( $other, 'tix_paypal_token', 'EC-OTHER' );
		$result = $this->reconcile_with(
			$paypal,
			'tok_not_ours',
			array(
				'GetExpressCheckoutDetails' => $completed,
				'GetTransactionDetails'     => $this->response(
					$this->transaction_details(
						array(
							'CUSTOM'    => get_current_blog_id() . ':tok_someone_else',
							'EMAIL'     => 'payer@example.org',
							'FIRSTNAME' => 'Payer',
						)
					)
				),
			)
		);
		$this->assertSame( CampTix_Payment_Method::LOOKUP_UNKNOWN, $result['status'] );
		$this->assertSame( 'draft', get_post_status( $other ) );
		$this->assertSame( 'TXN_PAID', $result['data']['TRANSACTIONID'], 'logged with what identifies the charge' );
		$this->assertArrayNotHasKey( 'EMAIL', $result['data'], 'but not who paid: a kept draft logs this every sweep' );
		$this->assertArrayNotHasKey( 'FIRSTNAME', $result['data'] );

		$short = $this->make_order( array( 'token' => 'tok_short' ) );
		update_post_meta( $short, 'tix_paypal_token', 'EC-SHORT' );
		$result = $this->reconcile_with(
			$paypal,
			'tok_short',
			array(
				'GetExpressCheckoutDetails' => $completed,
				'GetTransactionDetails'     => $this->response(
					$this->transaction_details(
						array(
							'CUSTOM' => get_current_blog_id() . ':tok_short',
							'AMT'    => '1.00',
						)
					)
				),
			)
		);
		$this->assertSame( CampTix_Payment_Method::LOOKUP_UNKNOWN, $result['status'], 'underpaid' );
		$this->assertSame( 'draft', get_post_status( $short ) );
	}

	/**
	 * Run the real timeout sweep with this PayPal instance answering for `paypal`, and
	 * PayPal's NVP calls answered by METHOD. Returns the METHODs asked and the log lines.
	 *
	 * @param CampTix_Payment_Method_PayPal $paypal  Gateway.
	 * @param array                         $answers Responses (or WP_Errors) keyed by METHOD.
	 *
	 * @return array { asked: string[], logged: string[] }
	 */
	protected function sweep_with( $paypal, $answers ) {
		global $camptix;

		$asked  = array();
		$logged = array();
		$stub   = function ( $pre, $args ) use ( $answers, &$asked ) {
			$asked[] = $args['body']['METHOD'];

			return $answers[ $args['body']['METHOD'] ];
		};
		$method = function ( $method, $method_id ) use ( $paypal ) {
			return 'paypal' === $method_id ? $paypal : $method;
		};
		$log    = function ( $message ) use ( &$logged ) {
			$logged[] = $message;
		};

		add_filter( 'pre_http_request', $stub, 10, 2 );
		add_filter( 'camptix_get_payment_method_by_id', $method, 20, 2 );
		add_action( 'camptix_log_raw', $log );
		try {
			$camptix->review_timeout_payments();
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
			remove_filter( 'camptix_get_payment_method_by_id', $method, 20 );
			remove_action( 'camptix_log_raw', $log );
		}

		return compact( 'asked', 'logged' );
	}

	/** PayPal's answer for a token that has expired. */
	protected function token_expired() {
		return $this->response(
			http_build_query(
				array(
					'ACK'          => 'Failure',
					'L_ERRORCODE0' => '10411',
				)
			)
		);
	}

	/**
	 * A draft PayPal order as checkout leaves it, `$elapsed` seconds later.
	 */
	protected function make_checked_out_order( $token, $paypal_token, $elapsed ) {
		$attendee   = $this->make_order( array( 'token' => $token ) );
		$checked_in = time() - $elapsed;
		update_post_meta( $attendee, 'tix_timestamp', $checked_in );
		update_post_meta( $attendee, 'tix_paypal_token', $paypal_token );
		update_post_meta( $attendee, 'tix_paypal_token_expires_at', $checked_in + CampTix_Payment_Method_PayPal::TOKEN_LIFETIME );
		update_post_meta( $attendee, 'tix_session_expires_at', $checked_in + CampTix_Payment_Method_PayPal::TOKEN_LIFETIME - CampTix_Payment_Method_PayPal::LOOKUP_LEAD );

		return $attendee;
	}

	/**
	 * The sweep first asks about a checkout while its token still answers. One not completed
	 * keeps its seats until the token expires, and is released once PayPal says it has.
	 *
	 * @covers CampTix_Payment_Method_PayPal::reconcile_payment
	 */
	public function test_timeout_sweep_asks_paypal_before_the_token_expires() {
		$paypal = $this->make_configured_paypal();
		$grace  = CampTix_Plugin::DRAFT_LIFETIME_GRACE;

		// The earliest the sweep reaches it, and the latest (one cron interval on).
		$past_due = array(
			'tok_sweep_early' => $grace + MINUTE_IN_SECONDS,
			'tok_sweep_late'  => $grace + 11 * MINUTE_IN_SECONDS,
		);
		foreach ( $past_due as $token => $late_by ) {
			$attendee      = $this->make_checked_out_order( $token, 'EC-' . $token, CampTix_Payment_Method_PayPal::TOKEN_LIFETIME - CampTix_Payment_Method_PayPal::LOOKUP_LEAD + $late_by );
			$token_expires = (int) get_post_meta( $attendee, 'tix_paypal_token_expires_at', true );
			$this->assertGreaterThan( time(), $token_expires, "$token: the token still answers when the sweep first asks" );

			$this->sweep_with( $paypal, array( 'GetExpressCheckoutDetails' => $this->checkout_details( array( 'CHECKOUTSTATUS' => 'PaymentActionNotInitiated' ) ) ) );

			$this->assertSame( 'draft', get_post_status( $attendee ), $token );
			$this->assertSame( $token_expires, (int) get_post_meta( $attendee, 'tix_session_expires_at', true ), "$token: seats kept until the token expires" );
		}

		// After the token expires PayPal says so, and the order is released, saying why.
		update_post_meta( $attendee, 'tix_session_expires_at', time() - $grace - MINUTE_IN_SECONDS );
		$sweep = $this->sweep_with(
			$paypal,
			array(
				'GetExpressCheckoutDetails' => $this->token_expired(),
			)
		);

		$this->assertSame( 'timeout', get_post_status( $attendee ) );
		$this->assertContains( 'At timeout, the PayPal token has expired.', $sweep['logged'] );
	}

	/**
	 * PayPal goes on reporting a checkout not started after its token's three hours, rather
	 * than saying the token expired. The sweep releases the order then anyway, instead of
	 * asking again every sweep until the 30 hour cap.
	 *
	 * @covers CampTix_Payment_Method_PayPal::reconcile_payment
	 */
	public function test_timeout_sweep_releases_a_checkout_not_started_once_its_token_expires() {
		$paypal   = $this->make_configured_paypal();
		$attendee = $this->make_checked_out_order( 'tok_never_started', 'EC-NEVER', CampTix_Payment_Method_PayPal::TOKEN_LIFETIME + CampTix_Plugin::DRAFT_LIFETIME_GRACE + MINUTE_IN_SECONDS );

		// Where the first sweep left it: due once the token expires.
		update_post_meta( $attendee, 'tix_session_expires_at', (int) get_post_meta( $attendee, 'tix_paypal_token_expires_at', true ) );

		$sweep = $this->sweep_with( $paypal, array( 'GetExpressCheckoutDetails' => $this->checkout_details( array( 'CHECKOUTSTATUS' => 'PaymentActionNotInitiated' ) ) ) );

		$this->assertSame( array( 'GetExpressCheckoutDetails' ), $sweep['asked'] );
		$this->assertSame( 'timeout', get_post_status( $attendee ) );
		$this->assertContains( 'At timeout, the PayPal checkout was not completed before its token expired.', $sweep['logged'] );
	}

	/**
	 * A completed checkout whose transaction couldn't be fetched is kept, and the transaction
	 * remembered: by the next sweep the token may have expired and PayPal won't name it again.
	 *
	 * @covers CampTix_Payment_Method_PayPal::reconcile_payment
	 */
	public function test_timeout_sweep_remembers_a_paypal_transaction_it_could_not_fetch() {
		$paypal   = $this->make_configured_paypal();
		$attendee = $this->make_checked_out_order( 'tok_remember', 'EC-REMEMBER', CampTix_Payment_Method_PayPal::TOKEN_LIFETIME - MINUTE_IN_SECONDS );

		$sweep = $this->sweep_with(
			$paypal,
			array(
				'GetExpressCheckoutDetails' => $this->checkout_details(
					array(
						'CHECKOUTSTATUS'                 => 'PaymentActionCompleted',
						'PAYMENTREQUEST_0_TRANSACTIONID' => 'TXN_PAID',
					)
				),
				'GetTransactionDetails'     => new WP_Error( 'http_request_failed', 'timed out' ),
			)
		);

		$this->assertSame( array( 'GetExpressCheckoutDetails', 'GetTransactionDetails' ), $sweep['asked'] );
		$this->assertSame( 'draft', get_post_status( $attendee ), 'kept for the next sweep' );
		$this->assertSame( 'TXN_PAID', get_post_meta( $attendee, 'tix_paypal_transaction_id', true ) );

		// The token has expired by now; asking about it would release the order.
		update_post_meta( $attendee, 'tix_session_expires_at', time() - CampTix_Plugin::DRAFT_LIFETIME_GRACE - MINUTE_IN_SECONDS );
		$sweep = $this->sweep_with(
			$paypal,
			array(
				'GetExpressCheckoutDetails' => $this->token_expired(),
				'GetTransactionDetails'     => $this->response( $this->transaction_details( array( 'CUSTOM' => get_current_blog_id() . ':tok_remember' ) ) ),
			)
		);

		$this->assertSame( array( 'GetTransactionDetails' ), $sweep['asked'] );
		$this->assertSame( 'publish', get_post_status( $attendee ) );
		$this->assertSame( 'TXN_PAID', get_post_meta( $attendee, 'tix_transaction_id', true ) );
	}
}
