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

		// `WCP_Payment_Request::save_payment()` (hooked to `save_post`) looks
		// up the current user to log who made the change; without one, the
		// factory-created posts below trip a "read property on false" warning.
		wp_set_current_user( $factory->user->create( array( 'role' => 'administrator' ) ) );

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
	 * Counts the rows currently in the payment index table.
	 */
	private function count_index_rows(): int {
		global $wpdb;

		$table = Payment_Requests_Dashboard::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name isn't user input, can't be a bound placeholder.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}

	/**
	 * Returns the `blog_id` the persisted run state would resume after, or `null` if no run is in progress.
	 */
	private function get_run_cursor(): ?int {
		$run = get_site_option( Payment_Requests_Dashboard::AGGREGATE_RUN_OPTION );

		return is_array( $run ) && isset( $run['cursor'] ) ? (int) $run['cursor'] : null;
	}

	/**
	 * Backdates the run state's heartbeat past the stall timeout, so watchdog() sees a dead run.
	 */
	private function stall_run_state(): void {
		$run = get_site_option( Payment_Requests_Dashboard::AGGREGATE_RUN_OPTION );

		$run['updated'] = time() - Payment_Requests_Dashboard::AGGREGATE_STALL_TIMEOUT - 1;

		update_site_option( Payment_Requests_Dashboard::AGGREGATE_RUN_OPTION, $run );
	}

	/**
	 * @covers Payment_Requests_Dashboard::aggregate
	 * @covers Payment_Requests_Dashboard::watchdog
	 */
	public function test_aggregate_batches_across_multiple_sites(): void {
		global $wpdb;

		// Force one blog per batch, so two new sites are enough to exercise a multi-batch chain.
		add_filter(
			'wordcamp_payments_aggregate_batch_size',
			function () {
				return 1;
			}
		);

		$start_blog_id = (int) $wpdb->get_var( "SELECT MAX(blog_id) FROM $wpdb->blogs" );
		$blog_a        = self::factory()->blog->create();
		$blog_b        = self::factory()->blog->create();

		foreach ( array( $blog_a, $blog_b ) as $blog_id ) {
			switch_to_blog( $blog_id );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			self::factory()->post->create( array(
				'post_type'   => 'wcp_payment_request',
				'post_status' => 'wcb-approved',
				'meta_input'  => array(
					'_wcb_updated_timestamp'         => strtotime( 'Yesterday 10am' ),
					'_camppayments_description'      => 'Batch Test Request',
					'_camppayments_due_by'           => strtotime( 'Next Tuesday' ),
					'_camppayments_payment_amount'   => '100',
					'_camppayments_currency'         => 'USD',
					'_camppayments_payment_method'   => 'Wire',
					'_camppayments_invoice_number'   => 'Batch Invoice',
					'_camppayments_payment_category' => 'audio-visual',
				),
			) );
			restore_current_blog();
		}

		$rows_before = $this->count_index_rows();
		$this->assertGreaterThan( 0, $rows_before, 'The wpSetUpBeforeClass fixture should already have indexed rows.' );

		// Batch 1: starting the cursor at $start_blog_id skips every pre-existing blog, so this batch
		// should pick up exactly $blog_a and schedule a continuation for it.
		Payment_Requests_Dashboard::aggregate( $start_blog_id );

		$this->assertNotFalse(
			wp_next_scheduled( 'wordcamp_payments_aggregate', array( $blog_a ) ),
			'A full batch should schedule a continuation for the next blog_id.'
		);
		$this->assertSame( $blog_a, $this->get_run_cursor() );

		$rows_after_batch_1 = $this->count_index_rows();
		$this->assertSame(
			$rows_before + 1,
			$rows_after_batch_1,
			'A mid-chain batch must add rows, not truncate the ones earlier batches already indexed.'
		);

		// Simulate the batch 1 continuation getting lost to a clobbered `cron` option (the race
		// `watchdog()` exists to recover from), instead of ever firing.
		wp_unschedule_event( wp_next_scheduled( 'wordcamp_payments_aggregate', array( $blog_a ) ), 'wordcamp_payments_aggregate', array( $blog_a ) );
		$this->assertFalse( wp_next_scheduled( 'wordcamp_payments_aggregate', array( $blog_a ) ), 'Precondition: the continuation is now lost.' );

		// A missing continuation alone isn't enough to resume on: that's also what an in-flight batch
		// looks like, since cron deletes a single event before firing it.
		Payment_Requests_Dashboard::watchdog();
		$this->assertFalse(
			wp_next_scheduled( 'wordcamp_payments_aggregate', array( $blog_a ) ),
			'watchdog() must not resume a run whose heartbeat is still fresh -- that batch may be running right now.'
		);

		$this->stall_run_state();
		Payment_Requests_Dashboard::watchdog();

		$this->assertNotFalse(
			wp_next_scheduled( 'wordcamp_payments_aggregate', array( $blog_a ) ),
			'watchdog() should resume the chain from the persisted cursor once the run has stopped making progress.'
		);

		// Consume the (re-)scheduled batch 2: picks up $blog_b, and since that still fills the
		// batch, schedules one more (empty) check before the chain can be considered done.
		wp_unschedule_event( wp_next_scheduled( 'wordcamp_payments_aggregate', array( $blog_a ) ), 'wordcamp_payments_aggregate', array( $blog_a ) );
		Payment_Requests_Dashboard::aggregate( $blog_a );

		$this->assertNotFalse( wp_next_scheduled( 'wordcamp_payments_aggregate', array( $blog_b ) ) );
		$this->assertSame( $blog_b, $this->get_run_cursor() );

		$rows_after_batch_2 = $this->count_index_rows();
		$this->assertSame( $rows_after_batch_1 + 1, $rows_after_batch_2, 'Batch 2 should have indexed blog_b, on top of batch 1.' );

		// Batch 3: $blog_b was the last blog in the network, so this finds nothing -- the chain
		// ends here, with no further continuation and the run state cleared.
		wp_unschedule_event( wp_next_scheduled( 'wordcamp_payments_aggregate', array( $blog_b ) ), 'wordcamp_payments_aggregate', array( $blog_b ) );
		Payment_Requests_Dashboard::aggregate( $blog_b );

		$this->assertFalse( get_site_option( Payment_Requests_Dashboard::AGGREGATE_RUN_OPTION ) );

		$rows_after_batch_3 = $this->count_index_rows();
		$this->assertSame( $rows_after_batch_2, $rows_after_batch_3, 'The empty final batch must not truncate or duplicate prior rows.' );

		// watchdog() must be a no-op once the chain has already completed cleanly.
		Payment_Requests_Dashboard::watchdog();
		$this->assertFalse( wp_next_scheduled( 'wordcamp_payments_aggregate', array( $blog_b ) ) );
	}

	/**
	 * A batch that dies partway through leaves nothing scheduled, so the run state has to be written
	 * before the batch does any work -- otherwise there'd be nothing for watchdog() to resume from.
	 *
	 * @covers Payment_Requests_Dashboard::aggregate
	 */
	public function test_aggregate_records_resume_point_before_doing_any_work(): void {
		global $wpdb;

		$start_blog_id  = (int) $wpdb->get_var( "SELECT MAX(blog_id) FROM $wpdb->blogs" );
		$state_at_start = null;

		// This filter is read after the run state is saved but before any blog is indexed, so it's a
		// stand-in for "the batch died here".
		add_filter(
			'wordcamp_payments_aggregate_batch_size',
			function ( $batch_size ) use ( &$state_at_start ) {
				$state_at_start = get_site_option( Payment_Requests_Dashboard::AGGREGATE_RUN_OPTION );

				return $batch_size;
			}
		);

		Payment_Requests_Dashboard::aggregate( $start_blog_id );

		$this->assertIsArray( $state_at_start, 'The run state should already be persisted when the batch begins work.' );
		$this->assertSame( $start_blog_id, (int) $state_at_start['cursor'] );
		$this->assertLessThanOrEqual( time(), (int) $state_at_start['updated'] );
	}

	/**
	 * A fresh run truncates the index, so a continuation left queued by a run that never finished would
	 * index its remaining blogs a second time, on top of what this run inserts for the same blogs.
	 *
	 * @covers Payment_Requests_Dashboard::aggregate
	 */
	public function test_a_fresh_run_retires_a_stalled_chain(): void {
		$stale_cursor = PHP_INT_MAX - 1;

		// A run that got as far as $stale_cursor and then stopped, leaving its continuation queued.
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'wordcamp_payments_aggregate', array( $stale_cursor ) );
		update_site_option(
			Payment_Requests_Dashboard::AGGREGATE_RUN_OPTION,
			array(
				'cursor'  => $stale_cursor,
				'updated' => time() - Payment_Requests_Dashboard::AGGREGATE_STALL_TIMEOUT - 1,
			)
		);

		// The recurring event that *starts* a run, which must survive the cleanup.
		if ( ! wp_next_scheduled( 'wordcamp_payments_aggregate' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wordcamp_payments_aggregate' );
		}

		$this->assertNotFalse(
			wp_next_scheduled( 'wordcamp_payments_aggregate', array( $stale_cursor ) ),
			'Precondition: the stalled run left a continuation queued.'
		);

		Payment_Requests_Dashboard::aggregate();

		$this->assertFalse(
			wp_next_scheduled( 'wordcamp_payments_aggregate', array( $stale_cursor ) ),
			'A fresh run must retire the previous run\'s continuation, or it will index into the table this run just truncated.'
		);
		$this->assertNotFalse(
			wp_next_scheduled( 'wordcamp_payments_aggregate' ),
			'The recurring daily event must not be swept up along with the stale continuations.'
		);

		// This network is smaller than a batch, so the run finished and cleared its own state too.
		$this->assertFalse( get_site_option( Payment_Requests_Dashboard::AGGREGATE_RUN_OPTION ) );

		// This is the only test that exercises a *fresh* run, so it's the only one that reaches the
		// TRUNCATE. That's DDL: MySQL commits it implicitly and won't roll it back with the rest of this
		// test, while the rows aggregate() re-inserted afterwards *would* be rolled back -- leaving the
		// index empty for every test below, which reads it via generate_payment_report(). Commit the
		// rebuilt index instead, once this test's own cron entries are cleared so they don't leak with it.
		wp_unschedule_hook( 'wordcamp_payments_aggregate' );
		self::commit_transaction();
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
				'filename'  => 'WordCampPayments%s%sSEPA.xml',
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
				'filename'  => 'WordCampPayments%s%sSEPA.xml',
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
