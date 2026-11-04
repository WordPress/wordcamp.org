<?php

defined( 'WPINC' ) || die();

require_once WP_PLUGIN_DIR . '/camptix/tests/trait-wordcamp-root-blog.php';
require_once __DIR__ . '/class-test-razorpay-gateway.php';

/**
 * Covers asking Razorpay about an order before it times out, and refunds.
 *
 * @covers CampTix_Payment_Method_Razorpay
 */
class Test_CampTix_Payment_Razorpay extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

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
	 * A Razorpay gateway with the currency set to INR.
	 *
	 * @return Test_Razorpay_Gateway
	 */
	protected function make_gateway() {
		$gateway = new Test_Razorpay_Gateway();

		$gateway->camptix_options['currency'] = 'INR';

		return $gateway;
	}

	/**
	 * A draft CampTix order bound to a Razorpay order, as checkout leaves it.
	 *
	 * @param string $token             Payment token.
	 * @param string $razorpay_order_id Razorpay order id, or '' for none.
	 * @param int    $amount            Amount sent to Razorpay, in paise.
	 * @param string $status            Attendee status.
	 *
	 * @return int The attendee post ID.
	 */
	protected function make_order( $token, $razorpay_order_id, $amount = 50000, $status = 'draft' ) {
		$attendee = self::factory()->post->create(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => $status,
			)
		);

		update_post_meta( $attendee, 'tix_payment_token', $token );
		update_post_meta( $attendee, 'tix_access_token', 'acc_' . $token );
		update_post_meta(
			$attendee,
			'tix_order',
			array(
				'items' => array(),
				'total' => $amount / 100,
			)
		);

		if ( $razorpay_order_id ) {
			update_post_meta( $attendee, '_razorpay_order_id', $razorpay_order_id );
			update_post_meta( $attendee, '_razorpay_amount', $amount );
		}

		return $attendee;
	}

	/**
	 * What Razorpay reports about an order, what happens to it, and what the sweep is told.
	 *
	 * @covers CampTix_Payment_Method_Razorpay::reconcile_payment
	 */
	public function test_reconcile_payment_settles_by_what_razorpay_reports() {
		$gateway = $this->make_gateway();

		$gateway->orders   = array(
			'order_paid'      => array(
				'status'      => 'paid',
				'amount_paid' => 50000,
			),
			'order_short'     => array(
				'status'      => 'paid',
				'amount_paid' => 100,
			),
			'order_auth'      => array(
				'status'      => 'attempted',
				'amount_paid' => 0,
			),
			'order_failed'    => array(
				'status'      => 'attempted',
				'amount_paid' => 0,
			),
			'order_untouched' => array(
				'status'      => 'created',
				'amount_paid' => 0,
			),
			'order_error'     => new Razorpay\Api\Errors\BadRequestError( 'The id provided does not exist', 'BAD_REQUEST_ERROR', 400 ),
			'order_down'      => new Exception( 'cURL error 28: timed out' ),
		);
		$gateway->payments = array(
			'order_paid'   => array(
				array(
					'id'     => 'pay_failed_first',
					'status' => 'failed',
				),
				array(
					'id'     => 'pay_paid',
					'status' => 'captured',
				),
			),
			'order_short'  => array(
				array(
					'id'     => 'pay_short',
					'status' => 'captured',
				),
			),
			'order_auth'   => array(
				array(
					'id'     => 'pay_auth',
					'status' => 'authorized',
				),
			),
			'order_failed' => array(
				array(
					'id'     => 'pay_declined',
					'status' => 'failed',
				),
			),
		);

		$cases = array(
			'paid in full'            => array( 'order_paid', CampTix_Payment_Method::LOOKUP_SETTLED, 'publish', 'pay_paid' ),
			'paid the wrong amount'   => array( 'order_short', CampTix_Payment_Method::LOOKUP_SETTLED, 'pending', 'pay_short' ),
			'authorized, not captured' => array( 'order_auth', CampTix_Payment_Method::LOOKUP_SETTLED, 'pending', 'pay_auth' ),
			'every attempt failed'    => array( 'order_failed', CampTix_Payment_Method::LOOKUP_EXPIRED, 'draft', '' ),
			'never attempted'         => array( 'order_untouched', CampTix_Payment_Method::LOOKUP_EXPIRED, 'draft', '' ),
			'Razorpay error'          => array( 'order_error', CampTix_Payment_Method::LOOKUP_UNKNOWN, 'draft', '' ),
			'Razorpay unreachable'    => array( 'order_down', CampTix_Payment_Method::LOOKUP_UNREACHABLE, 'draft', '' ),
			'no Razorpay order'       => array( '', CampTix_Payment_Method::LOOKUP_EXPIRED, 'draft', '' ),
		);

		foreach ( $cases as $name => list( $razorpay_order_id, $expected, $attendee_status, $transaction_id ) ) {
			$token    = 'tok_' . md5( $name );
			$attendee = $this->make_order( $token, $razorpay_order_id );

			$result = $gateway->reconcile_payment( $token );

			$this->assertSame( $expected, $result['status'], $name );
			$this->assertSame( $attendee_status, get_post_status( $attendee ), $name );
			$this->assertSame( $transaction_id, get_post_meta( $attendee, 'tix_transaction_id', true ), $name );
		}
	}

	/**
	 * A refund is requested for the order's payment, and its id recorded.
	 *
	 * @covers CampTix_Payment_Method_Razorpay::send_refund_request
	 */
	public function test_refund_is_requested_for_the_orders_payment() {
		$gateway = $this->make_gateway();
		$this->make_order( 'tok_refund', 'order_refund', 50000, 'publish' );
		$attendee = $this->make_order( 'tok_refund_2', 'order_refund_2', 50000, 'publish' );
		update_post_meta( $attendee, 'tix_transaction_id', 'pay_refund_me' );
		$gateway->refund = array(
			'id'     => 'rfnd_1',
			'status' => 'processed',
		);

		$result = $gateway->send_refund_request( 'tok_refund_2' );

		$this->assertSame( 'pay_refund_me', $gateway->refunds_requested[0][0] );
		$this->assertSame( CampTix_Plugin::PAYMENT_STATUS_REFUNDED, $result['status'] );
		$this->assertSame( 'rfnd_1', $result['refund_transaction_id'] );
	}

	/**
	 * An order recorded with the Razorpay order id as its transaction (a return that came
	 * back without a payment id) is refunded through the order's captured payment.
	 *
	 * @covers CampTix_Payment_Method_Razorpay::send_refund_request
	 */
	public function test_refund_finds_the_captured_payment_of_an_order_id() {
		$gateway  = $this->make_gateway();
		$attendee = $this->make_order( 'tok_by_order', 'order_by_order', 50000, 'publish' );
		update_post_meta( $attendee, 'tix_transaction_id', 'order_by_order' );
		$gateway->payments = array(
			'order_by_order' => array(
				array(
					'id'     => 'pay_failed',
					'status' => 'failed',
				),
				array(
					'id'     => 'pay_captured',
					'status' => 'captured',
				),
			),
		);
		$gateway->refund   = array( 'id' => 'rfnd_2' );

		$result = $gateway->send_refund_request( 'tok_by_order' );

		$this->assertSame( 'pay_captured', $gateway->refunds_requested[0][0] );
		$this->assertSame( CampTix_Plugin::PAYMENT_STATUS_REFUNDED, $result['status'] );
	}

	/**
	 * A refund Razorpay refuses is reported as failed.
	 *
	 * @covers CampTix_Payment_Method_Razorpay::send_refund_request
	 */
	public function test_refused_refund_is_reported_failed() {
		$gateway  = $this->make_gateway();
		$attendee = $this->make_order( 'tok_refused', 'order_refused', 50000, 'publish' );
		update_post_meta( $attendee, 'tix_transaction_id', 'pay_refused' );
		$gateway->refund = new Razorpay\Api\Errors\BadRequestError( 'The payment has been fully refunded already', 'BAD_REQUEST_ERROR', 400 );

		$result = $gateway->send_refund_request( 'tok_refused' );

		$this->assertSame( CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED, $result['status'] );
	}
}
