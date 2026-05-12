<?php
defined( 'WPINC' ) || die();

if ( ! defined( 'WORDCAMP_CAMPTIX_STRIPE_LIVE_WEBHOOK_SECRET' ) ) {
	define( 'WORDCAMP_CAMPTIX_STRIPE_LIVE_WEBHOOK_SECRET', 'whsec_test_secret' );
}

/**
 * @covers CampTix_Payment_Method_Stripe
 */
class Test_Camptix_Payment_Stripe_Addon extends \WP_UnitTestCase {
	/**
	 * Provide a test case for the function "CampTix_Payment_Method_Stripe->get_fractional_unit_amount".
	 **/
	public function currencyAmountProvider() {
		return array(
			array(
				'USD', 10, 1000, // 10USD should be 1000
			),
			array(
				'EUR', 10, 1000, // 10EUR should be 1000
			),
			array(
				'JPY', 10, 10, // 10 JPY should be 10
			),
		);
	}

	/**
	 * @covers CampTix_Payment_Method_Stripe->get_fractional_unit_amount
	 * @dataProvider currencyAmountProvider
	 */
	public function test_get_fractional_unit_amount( $currency, $amount, $expected_result ) {
		$client            = new CampTix_Payment_Method_Stripe();
		$fractional_amount = $client->get_fractional_unit_amount( $currency, $amount );
		$this->assertEquals( $expected_result, $fractional_amount);
	}


	/**
	 * @covers CampTix_Payment_Method_Stripe->get_fractional_unit_amount
	 * @expectedException Exception
	 */
	public function test_get_fractional_unit_amount_with_invalid_currency() {
		$client = new CampTix_Payment_Method_Stripe();
		try {
			$client->get_fractional_unit_amount( 'DUMMY', 100 );
			$this->fail( 'Exception should be thrown.' );
		} catch ( Exception $e ) {
			$this->assertEquals( 'Unknown currency multiplier for DUMMY.', $e->getMessage() );
		}
	}

	/**
	 * @covers CampTix_Payment_Method_Stripe::rest_stripe_webhook_permissions_check
	 */
	public function test_rest_stripe_webhook_permissions_check() {
		$client    = new CampTix_Payment_Method_Stripe();
		$payload   = wp_json_encode( array( 'id' => 'evt_test' ) );
		$timestamp = time();
		$signature = hash_hmac(
			'sha256',
			$timestamp . '.' . $payload,
			WORDCAMP_CAMPTIX_STRIPE_LIVE_WEBHOOK_SECRET
		);
		$request   = new WP_REST_Request( 'POST', '/camptix/v1/stripe-webhook' );

		$request->set_body( $payload );
		$request->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=' . $signature );

		$this->assertTrue( $client->rest_stripe_webhook_permissions_check( $request ) );

		$request->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=invalid' );

		$result = $client->rest_stripe_webhook_permissions_check( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'camptix_stripe_webhook_invalid_signature', $result->get_error_code() );
	}
}
