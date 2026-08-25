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
				),
			),

			'paid in another currency' => array(
				array(
					'order'   => array( 'token' => 'tok_currency' ),
					'details' => array(
						'CUSTOM' => '%blog%:tok_currency', 'CURRENCYCODE' => 'MXN',
					),
				),
			),

			// A Payments Standard payment carries a type CampTix never starts.
			'a type CampTix could not have started' => array(
				array(
					'order'   => array( 'token' => 'tok_standard' ),
					'details' => array(
						'CUSTOM' => '%blog%:tok_standard', 'TRANSACTIONTYPE' => 'web-accept',
					),
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
				),
			),

			// Settling this would overwrite the id the other gateway's refund reaches for.
			'an order taken by another gateway' => array(
				array(
					'order'   => array(
						'token' => 'tok_stripe', 'method' => 'stripe',
					),
					'details' => array( 'CUSTOM' => '%blog%:tok_stripe' ),
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
	public function test_return( $checkout_details, $charged ) {
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

		$_REQUEST['tix_payment_token'] = 'tok_ret';
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
}
