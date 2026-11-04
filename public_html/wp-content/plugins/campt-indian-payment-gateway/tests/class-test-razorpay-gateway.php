<?php

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/inc/razorpay/class-camptix-payment-method-razorpay.php';

/**
 * A Razorpay gateway whose API calls answer from fixtures.
 */
class Test_Razorpay_Gateway extends CampTix_Payment_Method_Razorpay {
	/**
	 * Razorpay orders by id, or an Exception to throw.
	 *
	 * @var array
	 */
	public $orders = array();

	/**
	 * Payments by Razorpay order id, or an Exception to throw.
	 *
	 * @var array
	 */
	public $payments = array();

	/**
	 * The refund to answer with, or an Exception to throw.
	 *
	 * @var array|Exception
	 */
	public $refund = array();

	/**
	 * Refund requests made, as [ payment id, notes ].
	 *
	 * @var array
	 */
	public $refunds_requested = array();

	/**
	 * Answer from $orders.
	 *
	 * @param string $razorpay_order_id Razorpay order id.
	 *
	 * @throws Exception When the fixture is one.
	 *
	 * @return array
	 */
	protected function fetch_razorpay_order( $razorpay_order_id ) {
		return $this->answer( $this->orders[ $razorpay_order_id ] );
	}

	/**
	 * Answer from $payments.
	 *
	 * @param string $razorpay_order_id Razorpay order id.
	 *
	 * @throws Exception When the fixture is one.
	 *
	 * @return array
	 */
	protected function fetch_razorpay_order_payments( $razorpay_order_id ) {
		return $this->answer( $this->payments[ $razorpay_order_id ] ?? array() );
	}

	/**
	 * Record the request and answer from $refund.
	 *
	 * @param string $razorpay_payment_id Razorpay payment id.
	 * @param array  $notes               Notes.
	 *
	 * @throws Exception When the fixture is one.
	 *
	 * @return array
	 */
	protected function create_razorpay_refund( $razorpay_payment_id, $notes ) {
		$this->refunds_requested[] = array( $razorpay_payment_id, $notes );

		return $this->answer( $this->refund );
	}

	/**
	 * Return a fixture, or throw it.
	 *
	 * @param mixed $fixture Fixture.
	 *
	 * @throws Exception When the fixture is one.
	 *
	 * @return mixed
	 */
	private function answer( $fixture ) {
		if ( $fixture instanceof Exception ) {
			throw $fixture;
		}

		return $fixture;
	}
}
