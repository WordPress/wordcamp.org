<?php

defined( 'WPINC' ) || die();

/**
 * Tests for check-in QR codes: the per-attendee code, looking it up when it's
 * scanned, and showing it to the attendee.
 *
 * @group camptix-attendance
 * @group checkin-qr
 */
class Test_Checkin_QR extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * Provision central when the harness points at a blog the test install lacks.
	 *
	 * @param WP_UnitTest_Factory $factory
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! get_site( WORDCAMP_ROOT_BLOG_ID ) ) {
			self::create_wordcamp_root_blog( $factory );
		}
	}

	/**
	 * Remove the root blog this class provisioned.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$wordcamp_root_blog_id ) {
			self::delete_wordcamp_root_blog();

			self::$wordcamp_root_blog_id = null;
		}
	}

	/**
	 * The addon instance under test.
	 *
	 * @var CampTix_Attendance
	 */
	protected $addon;

	/**
	 * Use a fresh addon instance with QR scanning on.
	 */
	public function set_up() {
		parent::set_up();

		// Set directly: CampTix caches its options object, so flipping the option wouldn't reach camptix_init().
		$this->addon             = new CampTix_Attendance();
		$this->addon->qr_enabled = true;
	}

	/**
	 * Create an attendee.
	 *
	 * @param string $status
	 *
	 * @return WP_Post
	 */
	protected function make_attendee( $status = 'publish' ) {
		$attendee_id = self::factory()->post->create( array(
			'post_type'   => 'tix_attendee',
			'post_status' => $status,
		) );

		update_post_meta( $attendee_id, 'tix_first_name', 'Jane' );
		update_post_meta( $attendee_id, 'tix_last_name', 'Doe' );
		update_post_meta( $attendee_id, 'tix_edit_token', 'edit-token-123' );

		return get_post( $attendee_id );
	}

	/**
	 * @covers CampTix_Attendance::get_checkin_code
	 */
	public function test_code_is_random_hex_and_stable() {
		$first  = $this->make_attendee();
		$second = $this->make_attendee();
		$code   = CampTix_Attendance::get_checkin_code( $first->ID );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $code );
		$this->assertSame( $code, CampTix_Attendance::get_checkin_code( $first->ID ) );
		$this->assertNotSame( $code, CampTix_Attendance::get_checkin_code( $second->ID ) );
	}

	/**
	 * @covers CampTix_Attendance::get_attendee_for_checkin
	 */
	public function test_valid_code_finds_attendee() {
		$attendee = $this->make_attendee();
		$code     = CampTix_Attendance::get_checkin_code( $attendee->ID );

		$this->assertSame( $attendee->ID, $this->addon->get_attendee_for_checkin( $code )->ID );

		// Some scanners report the text upper case.
		$this->assertSame( $attendee->ID, $this->addon->get_attendee_for_checkin( strtoupper( $code ) )->ID );
	}

	/**
	 * A reissued code replaces the old one, so a leaked QR code stops working.
	 *
	 * @covers CampTix_Attendance::generate_checkin_code
	 */
	public function test_reissued_code_replaces_old_one() {
		$attendee = $this->make_attendee();
		$old_code = CampTix_Attendance::get_checkin_code( $attendee->ID );
		$new_code = CampTix_Attendance::generate_checkin_code( $attendee->ID );

		$this->assertNotSame( $old_code, $new_code );
		$this->assertSame( 'unknown_code', $this->addon->get_attendee_for_checkin( $old_code )->get_error_code() );
		$this->assertSame( $attendee->ID, $this->addon->get_attendee_for_checkin( $new_code )->ID );
	}

	/**
	 * Data provider for test_attendee_cannot_check_in().
	 *
	 * @return array
	 */
	public function data_attendee_cannot_check_in() {
		return array(
			'refunded'  => array( 'refund', false, 'invalid_status' ),
			'cancelled' => array( 'cancel', false, 'invalid_status' ),
			'pending'   => array( 'pending', false, 'invalid_status' ),
			'attended'  => array( 'publish', true, 'already_attended' ),
		);
	}

	/**
	 * @covers CampTix_Attendance::get_attendee_for_checkin
	 * @dataProvider data_attendee_cannot_check_in
	 *
	 * @param string $status
	 * @param bool   $attended
	 * @param string $error_code
	 */
	public function test_attendee_cannot_check_in( $status, $attended, $error_code ) {
		$attendee = $this->make_attendee( $status );

		if ( $attended ) {
			update_post_meta( $attendee->ID, 'tix_attended', true );
		}

		$result = $this->addon->get_attendee_for_checkin( CampTix_Attendance::get_checkin_code( $attendee->ID ) );

		$this->assertWPError( $result );
		$this->assertSame( $error_code, $result->get_error_code() );
		$this->assertStringContainsString( 'Jane Doe', $result->get_error_message() );
	}

	/**
	 * Data provider for test_rejects_other_codes().
	 *
	 * @return array
	 */
	public function data_rejects_other_codes() {
		return array(
			'a URL'           => array( 'https://example.org/', 'invalid_code' ),
			'too short'       => array( 'abc123', 'invalid_code' ),
			'SQL wildcard'    => array( '%%%%%%%%%%%%%%%%', 'invalid_code' ),
			'unknown, valid'  => array( '0123456789abcdef', 'unknown_code' ),
		);
	}

	/**
	 * @covers CampTix_Attendance::get_attendee_for_checkin
	 * @dataProvider data_rejects_other_codes
	 *
	 * @param string $code
	 * @param string $error_code
	 */
	public function test_rejects_other_codes( $code, $error_code ) {
		$this->make_attendee();

		$this->assertSame( $error_code, $this->addon->get_attendee_for_checkin( $code )->get_error_code() );
	}

	/**
	 * @covers CampTix_Attendance::get_attendee_for_checkin
	 */
	public function test_nothing_found_when_scanning_is_off() {
		$attendee                = $this->make_attendee();
		$this->addon->qr_enabled = false;

		$result = $this->addon->get_attendee_for_checkin( CampTix_Attendance::get_checkin_code( $attendee->ID ) );

		$this->assertSame( 'qr_disabled', $result->get_error_code() );
	}

	/**
	 * @covers CampTix_Attendance::add_checkin_link_to_email
	 */
	public function test_email_links_to_qr_code() {
		global $camptix;

		$attendee = $this->make_attendee();
		$content  = $this->addon->add_checkin_link_to_email( 'Hi there!', $attendee );

		$this->assertStringStartsWith( 'Hi there!', $content );
		$this->assertStringContainsString( $camptix->get_edit_attendee_link( $attendee->ID, 'edit-token-123' ), $content );
	}

	/**
	 * @covers CampTix_Attendance::add_checkin_link_to_email
	 */
	public function test_email_link_goes_above_sponsors_footer() {
		$content = $this->addon->add_checkin_link_to_email( "Hi there!\n\n===\n\nSponsors", $this->make_attendee() );

		$this->assertStringStartsWith( "Hi there!\n\nShow your check-in QR code", $content );
		$this->assertStringEndsWith( "\n\n===\n\nSponsors", $content );
	}

	/**
	 * Pending orders get the link once the payment completes.
	 *
	 * @covers CampTix_Attendance::add_checkin_link_to_email
	 */
	public function test_email_unchanged_for_pending_attendee() {
		$this->assertSame( 'Hi there!', $this->addon->add_checkin_link_to_email( 'Hi there!', $this->make_attendee( 'pending' ) ) );
	}

	/**
	 * @covers CampTix_Attendance::render_checkin_qr_row
	 */
	public function test_edit_ticket_page_shows_qr_code() {
		ob_start();
		$this->addon->render_checkin_qr_row( $this->make_attendee() );
		$row = ob_get_clean();

		$this->assertStringContainsString( '<svg ', $row );

		ob_start();
		$this->addon->render_checkin_qr_row( $this->make_attendee( 'refund' ) );

		$this->assertSame( '', ob_get_clean() );
	}
}
