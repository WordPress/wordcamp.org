<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Tests;

use WP_UnitTestCase;
use WordCamp\Reports\Report\Sponsor_ROI;
use function WordCamp\Reports\get_report_classes;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/camptix/tests/trait-wordcamp-root-blog.php';

/**
 * Unit tests for the Sponsor ROI report.
 *
 * @group wordcamp-reports
 * @group sponsor-roi
 */
class Test_Sponsor_ROI extends WP_UnitTestCase {
	use \CampTix_Root_Blog_Fixture;

	/**
	 * Provision central, so attendee saves don't query a blog that doesn't exist.
	 *
	 * Saving a `tix_attendee` runs CampTix's `is_wordcamp_closed()`, which does
	 * `switch_to_blog( WORDCAMP_ROOT_BLOG_ID )` without checking that the blog
	 * exists.
	 *
	 * @param \WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::create_wordcamp_root_blog( $factory );
	}

	/**
	 * Remove the root blog this class created.
	 */
	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	/**
	 * Register the custom invoice statuses the spend join filters by.
	 *
	 * In production these are registered by wordcamp-payments
	 * (includes/sponsor-invoice.php); WP_Query silently ignores
	 * unregistered statuses, so the isolated suite must register them.
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'wcbsi_paid', 'wcbsi_approved', 'wcbsi_submitted', 'wcbsi_uncollectible', 'wcbsi_refunded' ) as $status ) {
			register_post_status( $status );
		}
	}

	/**
	 * Invoke a protected/private method for testing.
	 *
	 * @param object $instance The object to invoke the method on.
	 * @param string $method   The method name.
	 * @param array  $args     Arguments to pass to the method.
	 *
	 * @return mixed
	 */
	protected function invoke( $instance, $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $instance, $method );

		return $ref->invokeArgs( $instance, $args );
	}

	/**
	 * Build a raw data row with sensible defaults, overridden by $overrides.
	 *
	 * @param array $overrides Values to override the defaults.
	 *
	 * @return array
	 */
	protected function row( array $overrides = array() ) {
		return array_merge(
			array(
				'rollup_key'          => '',
				'mes_id'              => 0,
				'wordcamp_id'         => 0,
				'site_id'             => 0,
				'wordcamp_name'       => '',
				'event_date'          => '',
				'sponsor_id'          => 0,
				'sponsor_name'        => '',
				'tier'                => '',
				'website'             => '',
				'country'             => '',
				'first_time'          => '',
				'spend_usd'           => 0.0,
				'has_invoice'         => false,
				'registered'          => 0,
				'attended'            => 0,
				'attendance_measured' => false,
			),
			$overrides
		);
	}

	/**
	 * Build a report instance with a valid date range.
	 *
	 * @param array $options Optional report options.
	 *
	 * @return Sponsor_ROI
	 */
	protected function report( array $options = array() ) {
		$options = array_merge( array( 'cache_data' => false ), $options );

		return new Sponsor_ROI( '2024-01-01', '2024-12-31', 0, $options );
	}

	/**
	 * Report is registered.
	 */
	public function test_report_is_registered() {
		$this->assertContains(
			'WordCamp\Reports\Report\Sponsor_ROI',
			get_report_classes()
		);
	}

	/**
	 * Slug and group.
	 */
	public function test_slug_and_group() {
		$this->assertSame( 'sponsor-roi', Sponsor_ROI::$slug );
		$this->assertSame( 'wordcamp', Sponsor_ROI::$group );
	}

	/**
	 * Invalid date range yields error and empty data.
	 */
	public function test_invalid_date_range_yields_error_and_empty_data() {
		$report = new Sponsor_ROI( 'not-a-date', 'also-not-a-date' );

		$this->assertNotEmpty( $report->error->get_error_messages() );
		$this->assertSame( array(), $report->get_data() );
	}

	/**
	 * Create a sponsor invoice post with the meta the spend join relies on.
	 *
	 * @param int    $sponsor_id Local wcb_sponsor post ID.
	 * @param float  $amount     Invoice amount.
	 * @param string $currency   ISO currency code.
	 * @param string $status     Invoice post status.
	 *
	 * @return int The invoice post ID.
	 */
	protected function make_invoice( $sponsor_id, $amount, $currency, $status ) {
		// When the integrated suite loads wordcamp-payments, its `set_invoice_status()`
		// forces invoices with incomplete request data back to `draft` on insert. The
		// plugin itself removes this filter before programmatic status writes; do the same.
		$intercepted = remove_filter( 'wp_insert_post_data', 'WordCamp\\Budgets\\Sponsor_Invoices\\set_invoice_status', 10 );

		$id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor_invoice',
			'post_status' => $status,
		) );

		if ( $intercepted ) {
			add_filter( 'wp_insert_post_data', 'WordCamp\\Budgets\\Sponsor_Invoices\\set_invoice_status', 10, 2 );
		}

		update_post_meta( $id, '_wcbsi_sponsor_id', $sponsor_id );
		update_post_meta( $id, '_wcbsi_amount', $amount );
		update_post_meta( $id, '_wcbsi_currency', $currency );

		return $id;
	}

	/**
	 * `get_sponsor_invoice_totals` sums and filters by status.
	 */
	public function test_get_sponsor_invoice_totals_sums_and_filters_by_status() {
		$sponsor_id = self::factory()->post->create( array( 'post_type' => 'wcb_sponsor' ) );
		$other_id   = self::factory()->post->create( array( 'post_type' => 'wcb_sponsor' ) );

		$this->make_invoice( $sponsor_id, 3000, 'USD', 'wcbsi_paid' );
		$this->make_invoice( $sponsor_id, 2000, 'USD', 'wcbsi_paid' );
		$this->make_invoice( $sponsor_id, 1500, 'EUR', 'wcbsi_paid' );
		$this->make_invoice( $sponsor_id, 9999, 'USD', 'wcbsi_submitted' ); // Excluded by status.
		$this->make_invoice( $other_id, 8888, 'USD', 'wcbsi_paid' );        // Excluded by sponsor.

		$report = $this->report();
		$totals = $this->invoke( $report, 'get_sponsor_invoice_totals', array( $sponsor_id, Sponsor_ROI::COUNTED_STATUSES_PAID ) );

		$this->assertEqualSets( array( 'USD', 'EUR' ), array_keys( $totals ) );
		$this->assertSame( 5000.0, $totals['USD'] );
		$this->assertSame( 1500.0, $totals['EUR'] );
	}

	/**
	 * `get_sponsor_invoice_totals` filters even when statuses unregistered.
	 */
	public function test_get_sponsor_invoice_totals_filters_even_when_statuses_unregistered() {
		// WP_Query silently drops unregistered statuses from post_status, failing
		// open. Simulate a context where the wcbsi_* statuses were never registered
		// (found live: a submitted invoice inflated spend in the pilot run).
		$sponsor_id = self::factory()->post->create( array( 'post_type' => 'wcb_sponsor' ) );

		$this->make_invoice( $sponsor_id, 5000, 'USD', 'wcbsi_paid' );
		$this->make_invoice( $sponsor_id, 99999, 'USD', 'wcbsi_submitted' );

		foreach ( array( 'wcbsi_paid', 'wcbsi_approved', 'wcbsi_submitted', 'wcbsi_uncollectible', 'wcbsi_refunded' ) as $status ) {
			unset( $GLOBALS['wp_post_statuses'][ $status ] );
		}

		$totals = $this->invoke( $this->report(), 'get_sponsor_invoice_totals', array( $sponsor_id, Sponsor_ROI::COUNTED_STATUSES_PAID ) );

		$this->assertSame( 5000.0, $totals['USD'] );
	}

	/**
	 * `get_sponsor_invoice_totals` includes sent when configured.
	 */
	public function test_get_sponsor_invoice_totals_includes_sent_when_configured() {
		$sponsor_id = self::factory()->post->create( array( 'post_type' => 'wcb_sponsor' ) );

		$this->make_invoice( $sponsor_id, 1000, 'USD', 'wcbsi_paid' );
		$this->make_invoice( $sponsor_id, 500, 'USD', 'wcbsi_approved' );

		$report = $this->report();

		$paid_only = $this->invoke( $report, 'get_sponsor_invoice_totals', array( $sponsor_id, Sponsor_ROI::COUNTED_STATUSES_PAID ) );
		$paid_sent = $this->invoke( $report, 'get_sponsor_invoice_totals', array( $sponsor_id, Sponsor_ROI::COUNTED_STATUSES_PAID_SENT ) );

		$this->assertSame( 1000.0, $paid_only['USD'] );
		$this->assertSame( 1500.0, $paid_sent['USD'] );
	}

	/**
	 * `to_base_currency` short circuits usd.
	 */
	public function test_to_base_currency_short_circuits_usd() {
		$report = $this->report();

		$this->assertSame( 1234.5, $this->invoke( $report, 'to_base_currency', array( 1234.5, 'USD', '2024-06-01' ) ) );
	}

	/**
	 * `to_base_currency` reads converted value.
	 */
	public function test_to_base_currency_reads_converted_value() {
		$report = $this->report();

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- currency codes are the XRT API's own property names.
		// Stub the XRT client: convert() returns an object with a ->USD prop.
		$report->xrt = new class() {
			/**
			 * Stubbed conversion: a fixed USD rate, no network call.
			 *
			 * @param float  $amount
			 * @param string $currency
			 * @param string $date
			 *
			 * @return object
			 */
			public function convert( $amount, $currency, $date = '' ) {
				$o = new \stdClass();

				$o->{$currency} = $amount;
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- currency codes are the XRT API's own property names.
				$o->USD = $amount * 1.1; // Pretend EUR->USD = 1.1.

				return $o;
			}
		};

		$this->assertEqualsWithDelta( 110.0, $this->invoke( $report, 'to_base_currency', array( 100.0, 'EUR', '2024-06-01' ) ), 0.001 );
	}

	/**
	 * `to_base_currency` tolerates unknown currency.
	 */
	public function test_to_base_currency_tolerates_unknown_currency() {
		$report = $this->report();

		$report->xrt = new class() {
			/**
			 * Stubbed conversion that reports an unknown currency.
			 *
			 * @param float  $amount
			 * @param string $currency
			 * @param string $date
			 *
			 * @return \WP_Error
			 */
			public function convert( $amount, $currency, $date = '' ) {
				return new \WP_Error( 'unknown_currency', 'nope' );
			}
		};

		$this->assertSame( 0.0, $this->invoke( $report, 'to_base_currency', array( 50.0, 'XYZ', '2024-06-01' ) ) );
		$this->assertEmpty( $report->error->get_error_messages() ); // Tolerated, not surfaced.
	}

	/**
	 * `get_camp_reach` counts registered and attended.
	 */
	public function test_get_camp_reach_counts_registered_and_attended() {
		// 3 published attendees, 2 of them checked in; 1 draft (ignored).
		$a1 = self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => 'publish',
		) );
		$a2 = self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => 'publish',
		) );
		self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => 'publish',
		) );
		self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => 'draft',
		) );

		update_post_meta( $a1, 'tix_attended', true );
		update_post_meta( $a2, 'tix_attended', true );

		$reach = $this->invoke( $this->report(), 'get_camp_reach' );

		$this->assertSame( 3, $reach['registered'] );
		$this->assertSame( 2, $reach['attended'] );
		$this->assertTrue( $reach['measured'] );
	}

	/**
	 * `get_camp_reach` flags unmeasured attendance.
	 */
	public function test_get_camp_reach_flags_unmeasured_attendance() {
		// Attendees exist but none were ever checked in: attendance was not measured.
		self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => 'publish',
		) );
		self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => 'publish',
		) );

		$reach = $this->invoke( $this->report(), 'get_camp_reach' );

		$this->assertSame( 2, $reach['registered'] );
		$this->assertSame( 0, $reach['attended'] );
		$this->assertFalse( $reach['measured'] );
	}

	/**
	 * `to_base_currency` surfaces other errors.
	 */
	public function test_to_base_currency_surfaces_other_errors() {
		$report = $this->report();

		$report->xrt = new class() {
			/**
			 * Stubbed conversion that fails with an unexpected error code.
			 *
			 * @param float  $amount
			 * @param string $currency
			 * @param string $date
			 *
			 * @return \WP_Error
			 */
			public function convert( $amount, $currency, $date = '' ) {
				return new \WP_Error( 'request_error', 'API down' );
			}
		};

		$this->assertSame( 0.0, $this->invoke( $report, 'to_base_currency', array( 50.0, 'EUR', '2024-06-01' ) ) );
		$this->assertNotEmpty( $report->error->get_error_messages() );
	}

	/**
	 * `compile_report_data` rolls up by rollup key.
	 */
	public function test_compile_report_data_rolls_up_by_rollup_key() {
		$rows = array(
			// PayPal at two camps under the same agreement (mes_id 42).
			$this->row( array(
				'rollup_key' => '42', 'mes_id' => 42, 'sponsor_name' => 'PayPal', 'wordcamp_id' => 1, 'tier' => 'Gold', 'spend_usd' => 5000.0, 'registered' => 2000, 'attended' => 1800, 'attendance_measured' => true,
			) ),
			$this->row( array(
				'rollup_key' => '42', 'mes_id' => 42, 'sponsor_name' => 'PayPal', 'wordcamp_id' => 2, 'tier' => 'Silver', 'spend_usd' => 3000.0, 'registered' => 1000, 'attended' => 900, 'attendance_measured' => true,
			) ),
			// A local-only sponsor (no agreement).
			$this->row( array(
				'rollup_key' => 'local-9-7', 'mes_id' => 0, 'sponsor_name' => 'Local Co', 'wordcamp_id' => 2, 'tier' => 'Bronze', 'spend_usd' => 500.0, 'registered' => 1000, 'attended' => 900, 'attendance_measured' => true,
			) ),
		);

		$compiled = $this->report()->compile_report_data( $rows );

		$this->assertArrayHasKey( '42', $compiled );

		$paypal = $compiled['42'];

		$this->assertSame( 'PayPal', $paypal['sponsor_name'] );
		$this->assertSame( 8000.0, $paypal['totals']['spend_usd'] );
		$this->assertSame( 3000, $paypal['totals']['registered'] );
		$this->assertSame( 2700, $paypal['totals']['attended'] );
		$this->assertSame( 2, $paypal['totals']['camp_count'] );
		$this->assertSame( 0, $paypal['totals']['unmeasured_camps'] );
		$expected_tiers = array(
			1 => 'Gold',
			2 => 'Silver',
		);

		$this->assertSame( $expected_tiers, $paypal['tiers'] );
		$this->assertCount( 2, $paypal['camps'] );

		// cost_per_attended = 8000 / 2700.
		$this->assertEqualsWithDelta( 2.963, $paypal['ratios']['cost_per_attended'], 0.001 );
		$this->assertEqualsWithDelta( 2.667, $paypal['ratios']['cost_per_registered'], 0.001 );

		$this->assertArrayHasKey( 'local-9-7', $compiled );
	}

	/**
	 * `compile_report_data` null ratio when no attendance.
	 */
	public function test_compile_report_data_null_ratio_when_no_attendance() {
		$rows = array(
			$this->row( array(
				'rollup_key' => '7', 'mes_id' => 7, 'spend_usd' => 100.0, 'registered' => 0, 'attended' => 0, 'attendance_measured' => true,
			) ),
		);

		$compiled = $this->report()->compile_report_data( $rows );

		$this->assertNull( $compiled['7']['ratios']['cost_per_attended'] );
		$this->assertNull( $compiled['7']['ratios']['cost_per_registered'] );
	}

	/**
	 * Cache key varies with range and filters.
	 */
	public function test_cache_key_varies_with_range_and_filters() {
		$a = new Sponsor_ROI( '2024-01-01', '2024-12-31' );
		$b = new Sponsor_ROI( '2025-01-01', '2025-12-31' );
		$c = new Sponsor_ROI( '2024-01-01', '2024-12-31', 4242 );
		$d = new Sponsor_ROI( '2024-01-01', '2024-12-31', 0, array( 'include_sent_invoices' => true ) );
		$e = new Sponsor_ROI( '2024-01-01', '2024-12-31' );

		$keys = array(
			'a' => $this->invoke( $a, 'get_cache_key' ),
			'b' => $this->invoke( $b, 'get_cache_key' ),
			'c' => $this->invoke( $c, 'get_cache_key' ),
			'd' => $this->invoke( $d, 'get_cache_key' ),
		);

		// A different range, MES filter, or status policy must never share a cache entry.
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ) );

		// Identical parameters must share one.
		$this->assertSame( $keys['a'], $this->invoke( $e, 'get_cache_key' ) );
	}

	/**
	 * Private fields excluded from public safelist.
	 */
	public function test_private_fields_excluded_from_public_safelist() {
		$public  = new Sponsor_ROI( '2024-01-01', '2024-12-31', 0, array( 'public' => true ) );
		$private = new Sponsor_ROI( '2024-01-01', '2024-12-31', 0, array( 'public' => false ) );

		$public_keys  = array_keys( $public->get_data_fields_safelist() );
		$private_keys = array_keys( $private->get_data_fields_safelist() );

		// Spend is internal-only.
		$this->assertNotContains( 'spend_usd', $public_keys );
		$this->assertNotContains( 'has_invoice', $public_keys );
		$this->assertContains( 'spend_usd', $private_keys );

		// Reach aggregates are public-safe.
		$this->assertContains( 'registered', $public_keys );
		$this->assertContains( 'attended', $public_keys );
		$this->assertContains( 'attendance_measured', $public_keys );
	}

	/**
	 * Export to file does nothing without capability.
	 */
	public function test_export_to_file_does_nothing_without_capability() {
		// No nonce, no cap, wrong action: must not emit and must not fatal.
		$_POST = array();

		$this->assertNull( Sponsor_ROI::export_to_file() );
	}

	/**
	 * `compile_report_data` excludes unmeasured camps from attended.
	 */
	public function test_compile_report_data_excludes_unmeasured_camps_from_attended() {
		$rows = array(
			$this->row( array(
				'rollup_key' => '42', 'wordcamp_id' => 1, 'spend_usd' => 1000.0, 'registered' => 500, 'attended' => 450, 'attendance_measured' => true,
			) ),
			// Check-in never used here: registered counts, attended must not count as zero.
			$this->row( array(
				'rollup_key' => '42', 'wordcamp_id' => 2, 'spend_usd' => 1000.0, 'registered' => 300, 'attended' => 0, 'attendance_measured' => false,
			) ),
		);

		$compiled = $this->report()->compile_report_data( $rows );

		$totals = $compiled['42']['totals'];

		$this->assertSame( 800, $totals['registered'] );
		$this->assertSame( 450, $totals['attended'] );
		$this->assertSame( 2, $totals['camp_count'] );
		$this->assertSame( 1, $totals['unmeasured_camps'] );
	}
}
