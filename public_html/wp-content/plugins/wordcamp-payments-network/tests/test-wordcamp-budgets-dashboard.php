<?php

namespace WordCamp\Budgets_Dashboard\Tests;

use Payment_Requests_Dashboard;
use WCP_Encryption;
use WP_UnitTestCase;
use function WordCamp\Budgets_Dashboard\{ generate_payment_report };

defined( 'WPINC' ) || die();

/**
 * Class Test_Budgets_Dashboard
 *
 * @group budgets-dashboard
 */
class Test_Budgets_Dashboard extends WP_UnitTestCase {
	/**
	 * Set up shared fixtures for these tests.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		define( 'WORDCAMP_PAYMENTS_ENCRYPTION_KEY', 'key' );
		define( 'WORDCAMP_PAYMENTS_HMAC_KEY', 'hmac' );

		$factory->post->create( array(
			'post_type'   => 'wcp_payment_request',
			'post_status' => 'wcb-approved',

			'meta_input' => array(
				'_wcb_updated_timestamp'         => strtotime( 'Yesterday 10am' ),
				'_camppayments_description'      => 'Test Request',
				'_camppayments_due_by'           => strtotime( 'Next Tuesday' ),
				'_camppayments_payment_amount'   => '500',
				'_camppayments_currency'         => 'USD',
				'_camppayments_payment_method'   => 'Wire',
				'_camppayments_invoice_number'   => 'Invoice 1234',
				'_camppayments_payment_category' => 'audio-visual',

				'_camppayments_bank_name'            => WCP_Encryption::encrypt( 'A Bank' ),
				'_camppayments_bank_street_address'  => WCP_Encryption::encrypt( '1234 Bank St' ),
				'_camppayments_bank_city'            => WCP_Encryption::encrypt( 'Bankersville' ),
				'_camppayments_bank_state'           => WCP_Encryption::encrypt( 'New Bankswick' ),
				'_camppayments_bank_zip_code'        => WCP_Encryption::encrypt( '12345' ),
				'_camppayments_bank_country_iso3166' => WCP_Encryption::encrypt( 'US' ),
				'_camppayments_bank_bic'             => WCP_Encryption::encrypt( '123456' ),

				'_camppayments_beneficiary_name'            => WCP_Encryption::encrypt( 'Jane Beneficiary' ),
				'_camppayments_beneficiary_street_address'  => WCP_Encryption::encrypt( '9876 Beneficiary St' ),
				'_camppayments_beneficiary_city'            => WCP_Encryption::encrypt( 'Benficiaryville' ),
				'_camppayments_beneficiary_state'           => WCP_Encryption::encrypt( 'New Bennieswick' ),
				'_camppayments_beneficiary_zip_code'        => WCP_Encryption::encrypt( '98765' ),
				'_camppayments_beneficiary_country_iso3166' => WCP_Encryption::encrypt( 'Test' ),
				'_camppayments_beneficiary_account_number'  => WCP_Encryption::encrypt( '987654' ),
			),
		) );

		Payment_Requests_Dashboard::upgrade(); // Create index table.
		Payment_Requests_Dashboard::aggregate(); // Populate index table.
	}

	/**
	 * @covers WordCamp\Budgets_Dashboard\generate_payment_report
	 * @covers WordCamp\Budgets_Dashboard\_generate_payment_report_jpm_wires
	 * @covers WCP_Payment_Request::_generate_payment_report_jpm_wires
	 *
	 * @dataProvider data_generate_payment_report
	 */
	public function test_generate_payment_report( array $args, string $expected ) : void {
		if ( ! class_exists( 'WordPressdotorg\MU_Plugins\Utilities\Export_CSV' ) ) {
			$this->markTestSkipped( 'Export_CSV class not found.' );
		}

		$actual = generate_payment_report( $args );

		if ( is_wp_error( $actual ) ) {
			$actual = $actual->get_error_message();
		} else {
			// Replace the dynamic date because it's not easily mocked.
			$actual = preg_replace( '/HEADER,\d{14},1/', 'HEADER,date,1', $actual );
			$actual = preg_replace( '/,wcb-\d+-\d+/', ',wcb-site_id-blog_id', $actual );
		}

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Test cases for `test_generate_payment_report()`.
	 */
	public function data_generate_payment_report() : array {
		$cases = array(
			'vendor payment wire' => array(
				'args' => array(
					'status'     => 'wcb-approved',
					'start_date' => strtotime( '3 days ago' ),
					'end_date'   => time(),
					'post_type'  => 'wcp_payment_request',

					'export_type' => array(
						'label'     => 'JP Morgan Access - Wire Payments',
						'mime_type' => 'text/csv',
						'callback'  => 'WordCamp\Budgets_Dashboard\_generate_payment_report_jpm_wires',
						'filename'  => 'wordcamp-payments-%s-%s-jpm-wires.csv',
					),
				),

				'expected' => <<<EOD
					HEADER,date,1
					P,WIRES,,,N,USD,500.00,,,,,,,ACCT,987654,"Jane Beneficiary","9876 Beneficiary St",,"Benficiaryville New Bennieswick ",,Test,,,SWIFT,123456,"A Bank","1234 Bank St",,"Bankersville New Bankswick 12345",US,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,"Invoice 1234",,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,OUR,,,wcb-site_id-blog_id
					TRAILER,1,500

					EOD
				,
			),

			'no matching posts' => array(
				'args' => array(
					'status'     => 'wcb-approved',
					'start_date' => strtotime( '8 days ago' ),
					'end_date'   => strtotime( '5 days ago' ),
					'post_type'  => 'wcp_payment_request',

					'export_type' => array(
						'label'     => 'JP Morgan Access - Wire Payments',
						'mime_type' => 'text/csv',
						'callback'  => 'WordCamp\Budgets_Dashboard\_generate_payment_report_jpm_wires',
						'filename'  => 'wordcamp-payments-%s-%s-jpm-wires.csv',
					),
				),

				'expected' => <<<EOD
					HEADER,date,1
					TRAILER,0,0

					EOD
				,
			),

			'Invalid date' => array(
				'args' => array(
					'status'     => 'wcb-approved',
					'start_date' => 'invalid date',
					'end_date'   => strtotime( '5 days ago' ),
					'post_type'  => 'wcp_payment_request',

					'export_type' => array(
						'label'     => 'JP Morgan Access - Wire Payments',
						'mime_type' => 'text/csv',
						'callback'  => 'WordCamp\Budgets_Dashboard\_generate_payment_report_jpm_wires',
						'filename'  => 'wordcamp-payments-%s-%s-jpm-wires.csv',
					),
				),

				'expected' => 'Invalid start or end date.',
			),
		);

		return $cases;
	}
}
