<?php
/**
 * Authorization tests for the report file exporters.
 *
 * @package WordCamp\Reports
 */

namespace WordCamp\Reports\Tests;

use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WordCamp\Reports\Report\Meetup_Status;
use function WordCamp\Reports\get_report_classes;
use const WordCamp\Reports\CAPABILITY;

defined( 'WPINC' ) || die();

/**
 * Every report class's `export_to_file()` is registered on `admin_init`, and
 * `wp-admin/admin-post.php` fires `admin_init` *before* it decides whether the
 * caller is authenticated. So each exporter is solely responsible for its own
 * authorization, and none of them may do anything for an anonymous caller.
 *
 * @group wordcamp-reports
 *
 * @covers \WordCamp\Reports\Report\Meetup_Status::export_to_file
 * @covers \WordCamp\Reports\Report\Meetup_Status::render_admin_page
 */
class Test_Report_Export_Authorization extends WP_UnitTestCase {
	/**
	 * A user with no report capability.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * Set up shared fixtures.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$subscriber_id = $factory->user->create( array(
			'role' => 'subscriber',
		) );
	}

	/**
	 * Start each test as an anonymous caller with a clean request.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( 0 );
		$_GET  = array();
		$_POST = array();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		wp_set_current_user( 0 );

		remove_filter( 'user_has_cap', array( $this, 'grant_report_cap' ), 10 );

		parent::tear_down();
	}

	/**
	 * Grant the reports capability to the current user.
	 *
	 * @param bool[] $allcaps All capabilities for the user.
	 *
	 * @return bool[]
	 */
	public function grant_report_cap( $allcaps ) {
		$allcaps[ CAPABILITY ] = true;

		return $allcaps;
	}

	/**
	 * Run a callable and return everything it echoed.
	 *
	 * @param callable $callback The callable to run.
	 *
	 * @return string
	 */
	protected function capture_output( callable $callback ): string {
		ob_start();
		$callback();

		return ob_get_clean();
	}

	/**
	 * Every report class that registers a file exporter.
	 *
	 * @return array
	 */
	public function data_report_classes_with_exporters(): array {
		$cases = array();

		foreach ( get_report_classes() as $class ) {
			if ( method_exists( $class, 'export_to_file' ) ) {
				$cases[ $class::$slug ] = array( $class );
			}
		}

		return $cases;
	}

	/**
	 * An anonymous `admin-post.php` request must not produce any output from any
	 * exporter, however the report's own view or CSV would normally be emitted.
	 *
	 * This is the regression test for the Meetup Status report rendering its
	 * admin screen -- the private field schema, the internal status vocabulary
	 * and a `run-report` nonce -- to an unauthenticated caller.
	 *
	 * @dataProvider data_report_classes_with_exporters
	 *
	 * @param string $report_class The report class to exercise.
	 */
	public function test_exporter_is_a_no_op_for_anonymous_caller( $report_class ) {
		$_GET['report']  = $report_class::$slug;
		$_POST['action'] = 'Export CSV';

		$output = $this->capture_output(
			function () use ( $report_class ) {
				$report_class::export_to_file();
			}
		);

		$this->assertSame( '', $output, "{$report_class} emitted output for an unauthenticated caller." );
	}

	/**
	 * A valid nonce is not authorization -- an anonymous caller can mint one from
	 * any page that renders `wp_nonce_field( 'run-report' )`, and `wp_verify_nonce()`
	 * accepts it for uid 0.
	 */
	public function test_meetup_status_exporter_rejects_valid_nonce_without_capability() {
		wp_set_current_user( self::$subscriber_id );

		$nonce_key = Meetup_Status::$slug . '-nonce';

		$_GET['report']      = Meetup_Status::$slug;
		$_POST['action']     = 'Export CSV';
		$_POST[ $nonce_key ] = wp_create_nonce( 'run-report' );

		$output = $this->capture_output(
			array( Meetup_Status::class, 'export_to_file' )
		);

		$this->assertSame( '', $output );
	}

	/**
	 * The capability alone is not enough either; the request still has to carry a
	 * valid nonce, so the export cannot be triggered cross-site.
	 */
	public function test_meetup_status_exporter_rejects_capability_without_nonce() {
		wp_set_current_user( self::$subscriber_id );
		add_filter( 'user_has_cap', array( $this, 'grant_report_cap' ) );

		$_GET['report']  = Meetup_Status::$slug;
		$_POST['action'] = 'Export CSV';

		$output = $this->capture_output(
			array( Meetup_Status::class, 'export_to_file' )
		);

		$this->assertSame( '', $output );
	}

	/**
	 * `render_admin_page()` fails closed on its own, so the view cannot leak
	 * regardless of which entry point reaches it.
	 */
	public function test_meetup_status_admin_page_is_a_no_op_for_unauthorized_user() {
		wp_set_current_user( self::$subscriber_id );

		$output = $this->capture_output(
			array( Meetup_Status::class, 'render_admin_page' )
		);

		$this->assertSame( '', $output );
	}

	/**
	 * The counterpart to the above: a user who does hold the capability still
	 * gets the report screen, including the nonce field and the field checkboxes.
	 *
	 * No nonce is supplied, so the report data itself is not built -- this covers
	 * the view rendering, not the export.
	 */
	public function test_meetup_status_admin_page_renders_for_authorized_user() {
		wp_set_current_user( self::$subscriber_id );
		add_filter( 'user_has_cap', array( $this, 'grant_report_cap' ) );

		$output = $this->capture_output(
			array( Meetup_Status::class, 'render_admin_page' )
		);

		$this->assertStringContainsString( Meetup_Status::$slug . '-nonce', $output );
		$this->assertStringContainsString( 'name="fields[]"', $output );
	}
}
