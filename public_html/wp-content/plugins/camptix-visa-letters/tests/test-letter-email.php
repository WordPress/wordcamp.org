<?php
/**
 * Tests for delivering the visa letter by email.
 *
 * This runs from `camptix_payment_result`, so it must not take the request down when
 * the PDF is unavailable -- the attendee has already paid by this point.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Email
 */
class Test_CampTix_Visa_Letters_Email extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;
	use Visa_Letter_Fixtures;

	/**
	 * Mail captured during a test.
	 *
	 * @var array
	 */
	protected $mail = array();

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
	 * Capture outgoing mail instead of sending it.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_up_visa_fixtures();

		$this->mail = array();

		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $args ) {
				$this->mail[] = $args;

				return true;
			},
			10,
			2
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->tear_down_visa_fixtures();
		parent::tear_down();
	}

	/**
	 * A letter with no PDF must not be emailed, and must not kill the request.
	 */
	public function test_email_is_skipped_when_the_letter_has_no_document() {
		list( , $letter_id ) = $this->make_paid_letter( 'nomail' );

		$this->assertSame( '', get_post_meta( $letter_id, 'visa_letter_document', true ) );

		$sent = CampTix_Addon_Visa_Letters::send_visa_letter( $letter_id );

		$this->assertFalse( $sent );
		$this->assertSame( array(), $this->mail, 'No "please find attached" email may go out with nothing attached.' );
		$this->assertNotEmpty(
			preg_grep( '/document|pdf/i', $this->logged_messages() ),
			'The skipped delivery must be logged. Got: ' . implode( ' | ', $this->logged_messages() )
		);
	}

	/**
	 * A recorded document whose file has since vanished must also fail soft.
	 */
	public function test_email_is_skipped_when_the_document_file_is_missing() {
		list( , $letter_id ) = $this->make_paid_letter( 'gonemail' );
		$filename            = $this->attach_stub_pdf( $letter_id );

		wp_delete_file( ctx_vl_get_letters_dir() . '/' . $filename );
		$this->mail   = array();
		$this->logged = array();

		$sent = CampTix_Addon_Visa_Letters::send_visa_letter( $letter_id );

		$this->assertFalse( $sent );
		$this->assertSame( array(), $this->mail );
	}

	/**
	 * The happy path emails the letter with the PDF attached.
	 */
	public function test_letter_is_emailed_with_the_pdf_attached() {
		list( , $letter_id ) = $this->make_paid_letter( 'withmail' );
		$filename            = $this->attach_stub_pdf( $letter_id );

		$this->mail = array();

		$sent = CampTix_Addon_Visa_Letters::send_visa_letter( $letter_id );

		$this->assertNotFalse( $sent );
		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'eva@example.org', $this->mail[0]['to'] );
		$this->assertSame(
			array( ctx_vl_get_letters_dir() . '/' . $filename ),
			$this->mail[0]['attachments']
		);
	}

	/**
	 * An unusable email address is not a send.
	 */
	public function test_email_is_skipped_when_the_address_is_not_an_email() {
		list( , $letter_id ) = $this->make_paid_letter( 'bademail', array( 'email' => 'not-an-email' ) );
		$this->attach_stub_pdf( $letter_id );

		$this->mail = array();

		$this->assertFalse( CampTix_Addon_Visa_Letters::send_visa_letter( $letter_id ) );
		$this->assertSame( array(), $this->mail );
	}
}
