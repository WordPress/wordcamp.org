<?php
defined( 'WPINC' ) || die();

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
				'EUR', 10, 1000, // 10USD should be 1000
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
}
