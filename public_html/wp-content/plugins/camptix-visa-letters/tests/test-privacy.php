<?php
/**
 * Tests for the GDPR exporter and eraser.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Privacy
 */
class Test_CampTix_Visa_Letters_Privacy extends WP_UnitTestCase {
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
	 * Tear down.
	 */
	public function tear_down() {
		$this->tear_down_visa_fixtures();
		parent::tear_down();
	}

	/**
	 * Both handlers are registered with core's privacy tools and callable.
	 */
	public function test_exporter_and_eraser_are_registered() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'camptix-visa-letter', $exporters );
		$this->assertArrayHasKey( 'camptix-visa-letter', $erasers );
		$this->assertIsCallable( $exporters['camptix-visa-letter']['callback'] );
		$this->assertIsCallable( $erasers['camptix-visa-letter']['callback'] );
	}

	/**
	 * The export includes the letter's ordinary personal fields.
	 */
	public function test_export_includes_the_ordinary_personal_fields() {
		$this->make_paid_letter( 'export' );

		$export = ctx_vl_data_exporter( 'eva@example.org', 1 );
		$dump   = maybe_serialize( $export );

		$this->assertNotEmpty( $export['data'] );
		$this->assertStringContainsString( 'Eva', $dump );
		$this->assertStringContainsString( 'Horvat', $dump );
		$this->assertStringContainsString( 'Zagreb', $dump );
	}

	/**
	 * The export deliberately withholds the passport number and the date of birth.
	 *
	 * An export request is authenticated by an emailed link, which is a weaker gate
	 * than those two fields deserve.
	 */
	public function test_export_withholds_the_passport_number_and_date_of_birth() {
		$this->make_paid_letter( 'exportsecret' );

		$dump = maybe_serialize( ctx_vl_data_exporter( 'eva@example.org', 1 ) );

		$this->assertStringNotContainsString( 'AB1234567', $dump );
		$this->assertStringNotContainsString( '1990-04-17', $dump );
		$this->assertStringNotContainsString( 'ctxvl1:', $dump );
	}

	/**
	 * A letter whose metas were somehow stored as a scalar must not raise.
	 */
	public function test_export_tolerates_non_array_metas() {
		$letter_id = wp_insert_post(
			array(
				'post_type'   => 'tix_visa_letter',
				'post_status' => 'draft',
				'post_title'  => 'Letter with broken metas',
			),
			true
		);

		update_post_meta( $letter_id, 'visa_letter_metas', 'scalar-not-an-array@example.org' );

		$export = ctx_vl_data_exporter( 'scalar-not-an-array@example.org', 1 );

		$this->assertIsArray( $export );
		$this->assertTrue( $export['done'] );
	}

	/**
	 * Erasure blanks the personal fields and removes the stored ciphertext.
	 */
	public function test_erasure_blanks_the_personal_fields() {
		list( , $letter_id ) = $this->make_paid_letter( 'erase' );

		ctx_vl_data_eraser( 'eva@example.org', 1 );

		$dump = maybe_serialize( get_post_meta( $letter_id, 'visa_letter_metas', true ) );

		$this->assertStringNotContainsString( 'Eva', $dump );
		$this->assertStringNotContainsString( 'Horvat', $dump );
		$this->assertStringNotContainsString( '1990-04-17', $dump );
		$this->assertStringNotContainsString( 'Zagreb', $dump );
		$this->assertStringNotContainsString( 'ctxvl1:', $dump );
		$this->assertStringNotContainsString( 'eva@example.org', $dump );
	}

	/**
	 * Erasure deletes the PDF, which contains every personal field.
	 */
	public function test_erasure_deletes_the_pdf() {
		list( , $letter_id ) = $this->make_paid_letter( 'erasepdf' );
		$filename            = $this->attach_stub_pdf( $letter_id );
		$path                = ctx_vl_get_letters_dir() . '/' . $filename;

		$this->assertFileExists( $path );

		ctx_vl_data_eraser( 'eva@example.org', 1 );

		$this->assertFileDoesNotExist( $path );
		$this->assertSame( '', get_post_meta( $letter_id, 'visa_letter_document', true ) );
	}

	/**
	 * The letter title is anonymized but keeps the reference number.
	 */
	public function test_erasure_anonymizes_the_title_but_keeps_the_number() {
		list( , $letter_id ) = $this->make_paid_letter( 'erasetitle' );
		$number              = get_post_meta( $letter_id, 'visa_letter_number', true );

		ctx_vl_data_eraser( 'eva@example.org', 1 );

		$title = get_post( $letter_id )->post_title;

		$this->assertStringContainsString( $number, $title );
		$this->assertStringNotContainsString( 'Eva', $title );
		$this->assertStringNotContainsString( 'eva@example.org', $title );
	}

	/**
	 * The checkout staging copy on the attendee is erased too.
	 */
	public function test_erasure_removes_the_attendee_staging_copy() {
		list( $attendee_id ) = $this->make_paid_letter( 'erasestaging' );

		$this->assertNotEmpty( get_post_meta( $attendee_id, 'visa_letter_metas', true ) );

		ctx_vl_data_eraser( 'eva@example.org', 1 );

		$this->assertSame( '', get_post_meta( $attendee_id, 'visa_letter_metas', true ) );
	}

	/**
	 * An export after erasure finds nothing left.
	 */
	public function test_export_after_erasure_is_empty() {
		$this->make_paid_letter( 'eraseexport' );

		ctx_vl_data_eraser( 'eva@example.org', 1 );

		$this->assertEmpty( ctx_vl_data_exporter( 'eva@example.org', 1 )['data'] );
	}

	/**
	 * Erasure reports what it did, and a second run has nothing left to do.
	 */
	public function test_erasure_is_idempotent() {
		$this->make_paid_letter( 'eraseagain' );

		$first = ctx_vl_data_eraser( 'eva@example.org', 1 );

		$this->assertNotEmpty( $first['items_removed'] );
		$this->assertTrue( $first['done'] );

		$second = ctx_vl_data_eraser( 'eva@example.org', 1 );

		$this->assertEmpty( $second['items_removed'] );
		$this->assertTrue( $second['done'] );
	}

	/**
	 * Erasing one person's letter leaves everyone else's alone.
	 */
	public function test_erasure_does_not_touch_another_attendees_letter() {
		list( , $victim_letter )      = $this->make_paid_letter( 'victim' );
		list( $other_id, $bystander ) = $this->make_paid_letter(
			'bystander',
			array( 'email' => 'other@example.org' )
		);

		$bystander_pdf = $this->attach_stub_pdf( $bystander );

		ctx_vl_data_eraser( 'eva@example.org', 1 );

		$this->assertNotEmpty( $victim_letter );
		$this->assertSame(
			'AB1234567',
			ctx_vl_open_metas( get_post_meta( $bystander, 'visa_letter_metas', true ) )['passport_number']
		);
		$this->assertFileExists( ctx_vl_get_letters_dir() . '/' . $bystander_pdf );
		$this->assertNotEmpty( get_post_meta( $other_id, 'visa_letter_metas', true ) );
	}
}
