<?php
/**
 * Tests for visa letter PDF document creation, focused on the failure branches.
 *
 * The letter document is written from `save_post` on a published letter, which the
 * `camptix_payment_result` path triggers. So these branches decide what happens to an
 * attendee who has already paid when PDF generation is unavailable or produces nothing.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Document
 */
class Test_CampTix_Visa_Letters_Document extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

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
	 * Messages captured from CampTix's log during a test.
	 *
	 * @var array
	 */
	protected $logged = array();

	/**
	 * Temporary files created by a test, removed on teardown.
	 *
	 * @var array
	 */
	protected $temp_files = array();

	/**
	 * Capture anything the add-on writes to the CampTix log.
	 */
	public function set_up() {
		parent::set_up();

		$this->logged     = array();
		$this->temp_files = array();

		add_action(
			'camptix_log_raw',
			function ( $message, $post_id = 0, $data = null, $module = 'general' ) {
				$this->logged[] = array(
					'message' => $message,
					'post_id' => $post_id,
					'data'    => $data,
					'module'  => $module,
				);
			},
			10,
			4
		);
	}

	/**
	 * Remove any files a test created, in the temp dir and in the letters dir.
	 */
	public function tear_down() {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		$letters_dir = ctx_vl_get_letters_dir();
		if ( $letters_dir ) {
			foreach ( glob( $letters_dir . '/*.pdf' ) as $pdf ) {
				wp_delete_file( $pdf );
			}
		}

		parent::tear_down();
	}

	/**
	 * A published letter with realistic details, as the payment path would create.
	 *
	 * @return int Letter post ID.
	 */
	protected function create_published_letter() {
		return wp_insert_post(
			array(
				'post_type'   => 'tix_visa_letter',
				'post_status' => 'publish',
				'post_title'  => 'Visa letter for Eva Horvat',
				'meta_input'  => array(
					'visa_letter_metas' => array(
						'first_name'       => 'Eva',
						'last_name'        => 'Horvat',
						'email'            => 'eva@example.org',
						'passport_country' => 'Croatia',
						'passport_number'  => 'AB1234567',
						'date_of_birth'    => '1990-04-17',
						'nationality'      => 'Croatian',
						'mailing_address'  => '1 Example Street',
					),
				),
			),
			true
		);
	}

	/**
	 * Create a file in the temp dir, tracked for teardown.
	 *
	 * @param string $contents Contents to write. An empty string produces a zero-byte file.
	 * @return string Full path.
	 */
	protected function create_temp_file( $contents ) {
		$path               = get_temp_dir() . 'ctx-vl-test-' . wp_generate_password( 8, false, false ) . '.pdf';
		$this->temp_files[] = $path;

		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $path;
	}

	/**
	 * Publishing a letter must not kill the request when the PDF generator is absent.
	 *
	 * `wordcamp-docs` can be inactive on a site. This path runs off the attendee's
	 * payment return, so dying here would strand someone who has already paid.
	 */
	public function test_publishing_a_letter_survives_a_missing_pdf_generator() {
		$this->assertFalse(
			class_exists( 'WordCamp_Docs_PDF_Generator' ),
			'This test is only meaningful while the generator is absent from the suite.'
		);

		$letter_id = $this->create_published_letter();

		$this->assertIsInt( $letter_id );
		$this->assertNotWPError( $letter_id );

		// Proves the save_post handlers actually ran, so the assertion below is not vacuous.
		$this->assertNotEmpty(
			get_post_meta( $letter_id, 'visa_letter_number', true ),
			'The letter number is assigned on save_post; if it is empty the hooks never fired.'
		);

		$this->assertSame(
			'',
			get_post_meta( $letter_id, 'visa_letter_document', true ),
			'No document may be recorded when no PDF could be generated.'
		);
	}

	/**
	 * A missing PDF generator must be recorded in the CampTix log, not swallowed.
	 */
	public function test_missing_pdf_generator_is_logged() {
		$letter_id = $this->create_published_letter();

		$messages = wp_list_pluck( $this->logged, 'message' );

		$this->assertNotEmpty( $messages, 'The failure must be logged.' );
		$this->assertNotEmpty(
			preg_grep( '/generator/i', $messages ),
			'The log entry should name the missing PDF generator. Got: ' . implode( ' | ', $messages )
		);
	}

	/**
	 * A PDF that was never written must not be recorded as the letter's document.
	 *
	 * `WordCamp_Docs_PDF_Generator::generate_pdf_from_file()` returns its intended output
	 * path unconditionally, right after `exec()`, without checking that wkhtmltopdf wrote
	 * anything. Recording the filename anyway leaves the letter advertising a download
	 * that 404s -- the defect reported in #1760 and fixed for invoices in #1761.
	 */
	public function test_document_is_not_recorded_when_the_pdf_was_never_written() {
		$letter_id = $this->create_published_letter();
		$missing   = get_temp_dir() . 'ctx-vl-never-written.pdf';

		$this->assertFileDoesNotExist( $missing );

		$recorded = CampTix_Addon_Visa_Letters::record_letter_document( $letter_id, $missing, 'never-written.pdf' );

		$this->assertFalse( $recorded );
		$this->assertSame( '', get_post_meta( $letter_id, 'visa_letter_document', true ) );
	}

	/**
	 * A zero-byte PDF is a failed generation, not a document.
	 */
	public function test_document_is_not_recorded_when_the_pdf_is_empty() {
		$letter_id = $this->create_published_letter();
		$empty     = $this->create_temp_file( '' );

		$this->assertSame( 0, filesize( $empty ) );

		$recorded = CampTix_Addon_Visa_Letters::record_letter_document( $letter_id, $empty, 'empty.pdf' );

		$this->assertFalse( $recorded );
		$this->assertSame( '', get_post_meta( $letter_id, 'visa_letter_document', true ) );
	}

	/**
	 * A failed document write must be logged with the letter ID.
	 */
	public function test_failed_document_write_is_logged_with_the_letter_id() {
		$letter_id    = $this->create_published_letter();
		$this->logged = array();

		CampTix_Addon_Visa_Letters::record_letter_document( $letter_id, get_temp_dir() . 'ctx-vl-absent.pdf', 'absent.pdf' );

		$this->assertNotEmpty( $this->logged, 'A failed PDF write must be logged.' );
		$this->assertSame( $letter_id, $this->logged[0]['post_id'] );
	}

	/**
	 * The happy path still records the document and moves the file into place.
	 */
	public function test_document_is_recorded_when_the_pdf_exists() {
		$letter_id = $this->create_published_letter();
		$generated = $this->create_temp_file( '%PDF-1.4 test' );

		$recorded = CampTix_Addon_Visa_Letters::record_letter_document( $letter_id, $generated, 'issued.pdf' );

		$this->assertTrue( $recorded );
		$this->assertSame( 'issued.pdf', get_post_meta( $letter_id, 'visa_letter_document', true ) );
		$this->assertFileExists( ctx_vl_get_letters_dir() . '/issued.pdf' );
		$this->assertFileDoesNotExist( $generated );
	}
}
