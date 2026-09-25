<?php
/**
 * Tests for the optional retention purge.
 *
 * Letters accumulate passport numbers, so a site can opt into erasing all of them a
 * set number of days after the event ends.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Retention
 */
class Test_CampTix_Visa_Letters_Retention extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;
	use Visa_Letter_Fixtures;

	/**
	 * The letter template calls get_wordcamp_post(), which switches to the root blog.
	 *
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
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_up_visa_fixtures();
	}

	/**
	 * Leave no scheduled cron behind.
	 */
	public function tear_down() {
		wp_clear_scheduled_hook( 'ctx_vl_retention_cron' );
		$this->tear_down_visa_fixtures();
		parent::tear_down();
	}

	/**
	 * Pin the event end so the cutoff is deterministic.
	 *
	 * @param int $timestamp Event end timestamp.
	 */
	protected function pin_event_end( $timestamp ) {
		add_filter(
			'ctx_vl_event_end_timestamp',
			static function () use ( $timestamp ) {
				return $timestamp;
			}
		);
	}

	/**
	 * Both retention settings survive the options validator.
	 */
	public function test_retention_settings_are_validated() {
		$output = CampTix_Addon_Visa_Letters::validate_options(
			array(),
			array(
				'visa-letter-retention'      => '1',
				'visa-letter-retention-days' => '45',
			)
		);

		$this->assertSame( 1, $output['visa-letter-retention'] );
		$this->assertSame( 45, $output['visa-letter-retention-days'] );
	}

	/**
	 * The purge is wired to its cron hook.
	 */
	public function test_purge_is_hooked_to_the_cron_event() {
		$this->assertNotFalse( has_action( 'ctx_vl_retention_cron', 'ctx_vl_retention_purge' ) );
	}

	/**
	 * Turning retention on schedules the daily job; turning it off clears it.
	 */
	public function test_schedule_follows_the_setting() {
		$this->set_visa_options( array( 'visa-letter-retention' => 1 ) );
		ctx_vl_retention_schedule();

		$this->assertNotFalse( wp_next_scheduled( 'ctx_vl_retention_cron' ) );

		$this->set_visa_options( array( 'visa-letter-retention' => 0 ) );
		ctx_vl_retention_schedule();

		$this->assertFalse( wp_next_scheduled( 'ctx_vl_retention_cron' ) );
	}

	/**
	 * With retention off, nothing is erased even long after the event.
	 */
	public function test_disabled_retention_erases_nothing() {
		list( $attendee_id, $letter_id ) = $this->make_paid_letter( 'retoff' );

		$this->set_visa_options( array( 'visa-letter-retention' => 0 ) );
		$this->pin_event_end( time() - YEAR_IN_SECONDS );

		$this->assertSame( 0, ctx_vl_retention_purge() );
		$this->assertSame(
			'AB1234567',
			ctx_vl_open_metas( get_post_meta( $letter_id, 'visa_letter_metas', true ) )['passport_number']
		);
		$this->assertNotEmpty( get_post_meta( $attendee_id, 'visa_letter_metas', true ) );
	}

	/**
	 * Before the cutoff, nothing is erased.
	 */
	public function test_purge_before_the_cutoff_erases_nothing() {
		list( , $letter_id ) = $this->make_paid_letter( 'retearly' );

		$this->set_visa_options(
			array(
				'visa-letter-retention'      => 1,
				'visa-letter-retention-days' => 30,
			)
		);
		$this->pin_event_end( time() );

		$this->assertSame( 0, ctx_vl_retention_purge() );
		$this->assertSame(
			'AB1234567',
			ctx_vl_open_metas( get_post_meta( $letter_id, 'visa_letter_metas', true ) )['passport_number']
		);
	}

	/**
	 * Past the cutoff, letters and staging copies are erased and the PDF is deleted.
	 */
	public function test_purge_past_the_cutoff_erases_everything() {
		list( $attendee_id, $letter_id ) = $this->make_paid_letter( 'retpurge' );
		$filename                        = $this->attach_stub_pdf( $letter_id );

		$this->set_visa_options(
			array(
				'visa-letter-retention'      => 1,
				'visa-letter-retention-days' => 30,
			)
		);
		$this->pin_event_end( time() - 31 * DAY_IN_SECONDS );

		$erased = ctx_vl_retention_purge();

		$this->assertGreaterThanOrEqual( 2, $erased );

		$dump = maybe_serialize( get_post_meta( $letter_id, 'visa_letter_metas', true ) );
		$this->assertStringNotContainsString( 'ctxvl1:', $dump );
		$this->assertStringNotContainsString( 'Eva', $dump );
		$this->assertFileDoesNotExist( ctx_vl_get_letters_dir() . '/' . $filename );
		$this->assertSame( '', get_post_meta( $attendee_id, 'visa_letter_metas', true ) );
		$this->assertNotEmpty( get_post_meta( $letter_id, '_ctx_vl_erased', true ) );
	}

	/**
	 * A second purge finds nothing left to erase.
	 */
	public function test_second_purge_erases_nothing() {
		$this->make_paid_letter( 'rettwice' );

		$this->set_visa_options(
			array(
				'visa-letter-retention'      => 1,
				'visa-letter-retention-days' => 30,
			)
		);
		$this->pin_event_end( time() - 31 * DAY_IN_SECONDS );

		$this->assertGreaterThanOrEqual( 1, ctx_vl_retention_purge() );
		$this->assertSame( 0, ctx_vl_retention_purge() );
	}

	/**
	 * The purge still runs after a site stops taking new requests.
	 *
	 * Switching the feature off does not make the letters already on file disappear.
	 */
	public function test_purge_runs_with_the_feature_switched_off() {
		list( , $letter_id ) = $this->make_paid_letter( 'retinactive' );

		$this->set_visa_options(
			array(
				'visa-letter-active'         => 0,
				'visa-letter-retention'      => 1,
				'visa-letter-retention-days' => 30,
			)
		);
		$this->pin_event_end( time() - 31 * DAY_IN_SECONDS );

		$this->assertGreaterThanOrEqual( 1, ctx_vl_retention_purge() );
		$this->assertNotEmpty( get_post_meta( $letter_id, '_ctx_vl_erased', true ) );
	}

	/**
	 * With no event date at all, the purge does nothing rather than erasing early.
	 */
	public function test_purge_without_an_event_date_erases_nothing() {
		list( , $letter_id ) = $this->make_paid_letter( 'retnodate' );

		$this->set_visa_options( array( 'visa-letter-retention' => 1 ) );
		$this->pin_event_end( 0 );

		$this->assertSame( 0, ctx_vl_retention_purge() );
		$this->assertSame( '', get_post_meta( $letter_id, '_ctx_vl_erased', true ) );
	}

	/**
	 * A single-day camp leaves the End Date empty, so the cutoff uses the Start Date.
	 *
	 * Without the fallback such a camp would never purge.
	 */
	public function test_single_day_camp_falls_back_to_the_start_date() {
		list( , $letter_id ) = $this->make_paid_letter( 'retsingleday' );

		$this->make_wordcamp_post( gmdate( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ) );

		$this->set_visa_options(
			array(
				'visa-letter-retention'      => 1,
				'visa-letter-retention-days' => 30,
			)
		);

		$this->assertGreaterThanOrEqual( 1, ctx_vl_retention_purge() );
		$this->assertNotEmpty( get_post_meta( $letter_id, '_ctx_vl_erased', true ) );
	}
}
