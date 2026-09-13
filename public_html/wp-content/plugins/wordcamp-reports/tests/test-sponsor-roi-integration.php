<?php
/**
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Tests;

use WP_UnitTestCase;
use WordCamp\Reports\Report\Sponsor_ROI;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__, 2 ) . '/camptix/tests/trait-wordcamp-root-blog.php';

/**
 * A Sponsor_ROI subclass with camp enumeration stubbed to a single test subsite.
 *
 * Camp enumeration (WordCamp_Details) and site resolution (validate_wordcamp_id)
 * depend on the wcpt plugin, which isn't loaded in the isolated suite. Everything
 * downstream — the per-camp gatherer, spend join, currency, reach — runs for real
 * against a factory-created subsite.
 */
class Stubbed_Sponsor_ROI extends Sponsor_ROI {
	/**
	 * The single camp this stub reports, as [ wordcamp_id, site_id, name, start_timestamp ].
	 *
	 * @var array
	 */
	public $test_camp = array();

	/**
	 * Return the single fixture camp instead of querying WordCamp_Details.
	 *
	 * @return array
	 */
	protected function get_wordcamps() {
		return array(
			$this->test_camp[0] => array(
				'ID'                      => $this->test_camp[0],
				'Name'                    => $this->test_camp[2],
				'URL'                     => 'https://test.wordcamp.test',
				'Start Date (YYYY-mm-dd)' => $this->test_camp[3],
				'Status'                  => 'wcpt-closed',
			),
		);
	}

	/**
	 * Resolve the fixture camp without wcpt, which the isolated harness lacks.
	 *
	 * @param int $wordcamp_id
	 *
	 * @return array|null
	 */
	protected function resolve_wordcamp( $wordcamp_id ) {
		$valid          = new \stdClass();
		$valid->post_id = $this->test_camp[0];
		$valid->site_id = $this->test_camp[1];

		return $valid;
	}
}

/**
 * Cross-site integration tests for the Sponsor ROI report.
 *
 * @group wordcamp-reports
 * @group sponsor-roi
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- the stub above is only meaningful to this test case.
class Test_Sponsor_ROI_Integration extends WP_UnitTestCase {
	use \CampTix_Root_Blog_Fixture;

	/**
	 * Provision central — see the same fixture in Test_Sponsor_ROI for why.
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
	 * Register the invoice statuses and sponsor-level taxonomy the join needs.
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'wcbsi_paid', 'wcbsi_approved', 'wcbsi_submitted' ) as $status ) {
			register_post_status( $status );
		}

		register_taxonomy( 'wcb_sponsor_level', 'wcb_sponsor' );
	}

	/**
	 * Build a stubbed report pointed at a fresh subsite.
	 *
	 * @return Stubbed_Sponsor_ROI
	 */
	protected function stubbed_report() {
		$site_id = self::factory()->blog->create();

		$report            = new Stubbed_Sponsor_ROI( '2024-01-01',
			'2024-12-31',
			0,
			array(
				'cache_data' => false,
				'public'     => false,
			)
		);
		$report->test_camp = array( 987, $site_id, 'WordCamp Test', strtotime( '2024-06-01' ) );

		return $report;
	}

	/**
	 * `get_data` emits one row per sponsor per camp.
	 */
	public function test_get_data_emits_one_row_per_sponsor_per_camp() {
		$report  = $this->stubbed_report();
		$site_id = $report->test_camp[1];

		switch_to_blog( $site_id );

		$sponsor_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
			'post_title'  => 'PayPal',
		) );
		update_post_meta( $sponsor_id, '_mes_id', 4242 );
		update_post_meta( $sponsor_id, '_wcpt_sponsor_website', 'https://paypal.com' );
		update_post_meta( $sponsor_id, '_wcpt_sponsor_company_name', 'PayPal' );

		wp_set_object_terms( $sponsor_id, 'Gold', 'wcb_sponsor_level' );

		// Suspend wordcamp-payments' invoice status workflow for the fixture insert —
		// see make_invoice() in Test_Sponsor_ROI.
		$intercepted = remove_filter( 'wp_insert_post_data', 'WordCamp\\Budgets\\Sponsor_Invoices\\set_invoice_status', 10 );

		$invoice_id = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor_invoice',
			'post_status' => 'wcbsi_paid',
		) );

		if ( $intercepted ) {
			add_filter( 'wp_insert_post_data', 'WordCamp\\Budgets\\Sponsor_Invoices\\set_invoice_status', 10, 2 );
		}
		update_post_meta( $invoice_id, '_wcbsi_sponsor_id', $sponsor_id );
		update_post_meta( $invoice_id, '_wcbsi_amount', 5000 );
		update_post_meta( $invoice_id, '_wcbsi_currency', 'USD' );

		$attendee_id = self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => 'publish',
		) );
		update_post_meta( $attendee_id, 'tix_attended', true );

		restore_current_blog();

		$rows = $report->get_data();

		$this->assertCount( 1, $rows );

		$row = $rows[0];

		$this->assertSame( '4242', $row['rollup_key'] );
		$this->assertSame( 4242, $row['mes_id'] );
		$this->assertSame( 987, $row['wordcamp_id'] );
		$this->assertSame( $site_id, $row['site_id'] );
		$this->assertSame( 'WordCamp Test', $row['wordcamp_name'] );
		$this->assertSame( '2024-06-01', $row['event_date'] );
		$this->assertSame( 'PayPal', $row['sponsor_name'] );
		$this->assertSame( 'Gold', $row['tier'] );
		$this->assertSame( 'https://paypal.com', $row['website'] );
		$this->assertSame( 5000.0, $row['spend_usd'] );
		$this->assertTrue( $row['has_invoice'] );
		$this->assertSame( 1, $row['registered'] );
		$this->assertSame( 1, $row['attended'] );
		$this->assertTrue( $row['attendance_measured'] );
	}

	/**
	 * `get_data` flags missing invoice and unmeasured attendance.
	 */
	public function test_get_data_flags_missing_invoice_and_unmeasured_attendance() {
		$report  = $this->stubbed_report();
		$site_id = $report->test_camp[1];

		switch_to_blog( $site_id );

		// A sponsor with no invoice at all, on a camp that never used check-in.
		self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
			'post_title'  => 'Local Hosting Co',
		) );

		self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => 'publish',
		) );

		restore_current_blog();

		$rows = $report->get_data();

		$this->assertCount( 1, $rows );

		$row = $rows[0];

		$this->assertSame( "local-{$site_id}-{$row['sponsor_id']}", $row['rollup_key'] );
		$this->assertSame( 0, $row['mes_id'] );
		$this->assertSame( 0.0, $row['spend_usd'] );
		$this->assertFalse( $row['has_invoice'] );
		$this->assertSame( 1, $row['registered'] );
		$this->assertSame( 0, $row['attended'] );
		$this->assertFalse( $row['attendance_measured'] );
	}

	/**
	 * `get_data` filters by `mes_id`.
	 */
	public function test_get_data_filters_by_mes_id() {
		$report  = $this->stubbed_report();
		$site_id = $report->test_camp[1];

		$filtered            = new Stubbed_Sponsor_ROI(
			'2024-01-01',
			'2024-12-31',
			4242,
			array(
				'cache_data' => false,
				'public'     => false,
			)
		);
		$filtered->test_camp = $report->test_camp;

		switch_to_blog( $site_id );

		$match = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
			'post_title'  => 'Matching Global Sponsor',
		) );
		update_post_meta( $match, '_mes_id', 4242 );

		$other = self::factory()->post->create( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'publish',
			'post_title'  => 'Other Sponsor',
		) );
		update_post_meta( $other, '_mes_id', 1111 );

		restore_current_blog();

		$rows = $filtered->get_data();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Matching Global Sponsor', $rows[0]['sponsor_name'] );
	}
}
