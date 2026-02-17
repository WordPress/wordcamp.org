<?php

namespace WordCamp\Budgets_Dashboard\Tests;

use Payment_Requests_Dashboard;
use WCP_Encryption;
use WordCamp_Budgets;
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

		$factory->post->create( array(
			'post_type'   => 'wcp_payment_request',
			'post_status' => 'wcb-approved',

			'meta_input' => array(
				'_wcb_updated_timestamp'         => strtotime( 'Yesterday 10am' ),
				'_camppayments_description'      => 'SEPA Test Request',
				'_camppayments_due_by'           => strtotime( 'Next Tuesday' ),
				'_camppayments_payment_amount'   => '250',
				'_camppayments_currency'         => 'EUR',
				'_camppayments_payment_method'   => 'sepa_transfer',
				'_camppayments_invoice_number'   => 'SEPA-INV-001',
				'_camppayments_payment_category' => 'venue',

				'_camppayments_sepa_account_name' => WCP_Encryption::encrypt( 'Account Name Here' ),
				'_camppayments_sepa_bic'          => WCP_Encryption::encrypt( 'DEUTDEDBFRA' ),
				'_camppayments_sepa_iban'         => WCP_Encryption::encrypt( 'DE89370400440532013000' ),
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
					P,WIRES,,,N,USD,500.00,,,,,,,ACCT,987654,"Jane Beneficiary","9876 Beneficiary St",,"Benficiaryville New Bennieswick ",,Test,,,SWIFT,123456,"A Bank","1234 Bank St",,"Bankersville New Bankswick 12345",US,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,wcb-site_id-blog_id,"WordPress Community Support","Invoice 1234",,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,OUR,,,wcb-site_id-blog_id
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

	/**
	 * @covers WordCamp\Budgets_Dashboard\generate_payment_report
	 * @covers WordCamp\Budgets_Dashboard\_generate_payment_report_sepa
	 * @covers WCP_Payment_Request::_generate_payment_report_sepa
	 * @covers WordCamp_Budgets::generate_sepa_xml
	 */
	public function test_generate_sepa_payment_report(): void {
		$args = array(
			'status'     => 'wcb-approved',
			'start_date' => strtotime( '3 days ago' ),
			'end_date'   => time(),
			'post_type'  => 'wcp_payment_request',

			'export_type' => array(
				'label'     => 'SEPA Credit Transfer (ISO 20022 XML)',
				'mime_type' => 'application/xml',
				'callback'  => 'WordCamp\Budgets_Dashboard\_generate_payment_report_sepa',
				'filename'  => 'wordcamp-payments-%s-%s-sepa.xml',
			),
		);

		$actual = generate_payment_report( $args );

		$this->assertIsString( $actual, 'SEPA report should return a string.' );
		$this->assertStringContainsString( '<?xml version="1.0" encoding="UTF-8"?>', $actual );
		$this->assertStringContainsString( 'pain.001.003.03', $actual );
		$this->assertStringContainsString( '<CstmrCdtTrfInitn>', $actual );
		$this->assertStringContainsString( '<PmtMtd>TRF</PmtMtd>', $actual );
		$this->assertStringContainsString( '<Cd>SEPA</Cd>', $actual );
		$this->assertStringContainsString( '<ChrgBr>SLEV</ChrgBr>', $actual );

		// Verify payment data.
		$this->assertStringContainsString( '<Nm>Account Name Here</Nm>', $actual );
		$this->assertStringContainsString( '<IBAN>DE89370400440532013000</IBAN>', $actual );
		$this->assertStringContainsString( '<BIC>DEUTDEDBFRA</BIC>', $actual );
		$this->assertStringContainsString( '<InstdAmt Ccy="EUR">250.00</InstdAmt>', $actual );
		$this->assertStringContainsString( '<Ustrd>SEPA-INV-001</Ustrd>', $actual );

		// Verify counts.
		$this->assertStringContainsString( '<NbOfTxs>1</NbOfTxs>', $actual );
		$this->assertStringContainsString( '<CtrlSum>250.00</CtrlSum>', $actual );
	}

	/**
	 * SEPA export with no matching posts should return an empty string.
	 *
	 * @covers WordCamp\Budgets_Dashboard\generate_payment_report
	 * @covers WordCamp\Budgets_Dashboard\_generate_payment_report_sepa
	 */
	public function test_generate_sepa_report_no_matching_posts(): void {
		$args = array(
			'status'     => 'wcb-approved',
			'start_date' => strtotime( '8 days ago' ),
			'end_date'   => strtotime( '5 days ago' ),
			'post_type'  => 'wcp_payment_request',

			'export_type' => array(
				'label'     => 'SEPA Credit Transfer (ISO 20022 XML)',
				'mime_type' => 'application/xml',
				'callback'  => 'WordCamp\Budgets_Dashboard\_generate_payment_report_sepa',
				'filename'  => 'wordcamp-payments-%s-%s-sepa.xml',
			),
		);

		$actual = generate_payment_report( $args );

		$this->assertSame( '', $actual, 'SEPA report with no matching posts should return an empty string.' );
	}

	/**
	 * Test generate_sepa_xml() output format directly.
	 *
	 * @covers WordCamp_Budgets::generate_sepa_xml
	 */
	public function test_generate_sepa_xml_format(): void {
		add_filter(
			'wcb_sepa_debtor_bic',
			function () {
				return 'TESTBIC123';
			}
		);
		add_filter(
			'wcb_sepa_debtor_iban',
			function () {
				return 'DE00000000000000000000';
			}
		);

		$payments = array(
			array(
				'amount'       => 100.50,
				'account_name' => 'Alice',
				'bic'          => 'ALICEBIC',
				'iban'         => 'DE11111111111111111111',
				'reference'    => 'wcb-1-100',
				'invoice'      => 'INV-100',
			),
			array(
				'amount'       => 200.00,
				'account_name' => 'Bob',
				'bic'          => '',
				'iban'         => 'DE22222222222222222222',
				'reference'    => 'wcb-1-200',
				'invoice'      => '',
			),
		);

		$xml = WordCamp_Budgets::generate_sepa_xml( $payments );

		// Validate XML structure.
		$dom = new \DOMDocument();
		$this->assertTrue( $dom->loadXML( $xml ), 'Output should be valid XML.' );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument native properties.
		$root = $dom->documentElement;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'Document', $root->localName );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'urn:iso:std:iso:20022:tech:xsd:pain.001.003.03', $root->namespaceURI );

		// Verify header counts.
		$this->assertStringContainsString( '<NbOfTxs>2</NbOfTxs>', $xml );
		$this->assertStringContainsString( '<CtrlSum>300.50</CtrlSum>', $xml );

		// Verify debtor info from filters.
		$this->assertStringContainsString( '<Nm>WordPress Community Support PBC</Nm>', $xml );
		$this->assertStringContainsString( '<IBAN>DE00000000000000000000</IBAN>', $xml );
		$this->assertStringContainsString( '<BIC>TESTBIC123</BIC>', $xml );

		// Verify first payment.
		$this->assertStringContainsString( '<Nm>Alice</Nm>', $xml );
		$this->assertStringContainsString( '<IBAN>DE11111111111111111111</IBAN>', $xml );
		$this->assertStringContainsString( '<BIC>ALICEBIC</BIC>', $xml );
		$this->assertStringContainsString( '<InstdAmt Ccy="EUR">100.50</InstdAmt>', $xml );
		$this->assertStringContainsString( '<Ustrd>INV-100</Ustrd>', $xml );
		$this->assertStringContainsString( '<EndToEndId>wcb-1-100</EndToEndId>', $xml );

		// Verify second payment (no BIC, no invoice).
		$this->assertStringContainsString( '<Nm>Bob</Nm>', $xml );
		$this->assertStringContainsString( '<IBAN>DE22222222222222222222</IBAN>', $xml );
		$this->assertStringContainsString( '<InstdAmt Ccy="EUR">200.00</InstdAmt>', $xml );
		$this->assertStringContainsString( '<EndToEndId>wcb-1-200</EndToEndId>', $xml );

		// Bob has no BIC, so CdtrAgt should not appear for that transaction.
		// Bob has no invoice, so RmtInf should not appear for that transaction.
		// Count occurrences: Alice has BIC, Bob doesn't - so only 1 CdtrAgt (besides the debtor).
		$this->assertSame( 1, substr_count( $xml, '<BIC>ALICEBIC</BIC>' ) );

		// Clean up filters.
		remove_all_filters( 'wcb_sepa_debtor_bic' );
		remove_all_filters( 'wcb_sepa_debtor_iban' );
	}

	/**
	 * Test generate_sepa_xml() with empty payments returns empty string.
	 *
	 * @covers WordCamp_Budgets::generate_sepa_xml
	 */
	public function test_generate_sepa_xml_empty_payments(): void {
		$this->assertSame( '', WordCamp_Budgets::generate_sepa_xml( array() ) );
	}
}
