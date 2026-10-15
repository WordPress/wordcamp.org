<?php
/**
 * Tests that visa letter details stay out of the CampTix log.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Log_Redaction
 *
 * CampTix logs the raw checkout and edit-page `$_POST`, and this add-on's form posts the
 * passport number, date of birth and address with it. The log is network-wide, shown in
 * wp-admin, never purged and untouched by the eraser, so none of those values may reach it.
 */
class Test_CampTix_Visa_Letters_Log_Redaction extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;
	use Visa_Letter_Fixtures;

	/**
	 * Saving an attendee calls get_wordcamp_post(), which switches to the root blog.
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
		$this->set_visa_options();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->tear_down_visa_fixtures();
		parent::tear_down();
	}

	/**
	 * Fail if any captured log entry holds a visa letter detail, plain or encrypted.
	 */
	private function assert_log_holds_no_visa_details() {
		$logged = wp_json_encode( $this->logged );

		$details = array(
			'AB1234567',
			'1990-04-17',
			'1 Example Street, Zagreb, Croatia',
			'Croatian',
			'2027-05-01',
			'2027-05-09',
			'Hotel Example, Toronto',
			'ctxvl1:',
		);
		foreach ( $details as $detail ) {
			$this->assertStringNotContainsString( $detail, $logged, "The CampTix log holds '$detail'." );
		}
	}

	/**
	 * The captured entries with a given message.
	 *
	 * @param string $message Log message.
	 * @return array
	 */
	private function logged_entries( $message ) {
		return array_values( wp_list_filter( $this->logged, array( 'message' => $message ) ) );
	}

	/**
	 * Checkout logs the posted form once per attendee draft, and the add-on logs the request.
	 */
	public function test_checkout_keeps_visa_details_out_of_the_log() {
		global $camptix;

		$_POST    = $this->posted_fields();
		$attendee = (object) array(
			'ticket_id'   => 0,
			'first_name'  => 'Eva',
			'last_name'   => 'Horvat',
			'email'       => 'eva@example.org',
			'username'    => 'eva',
			'answers'     => array(),
			'visa_letter' => $this->letter_details(),
		);

		$camptix->insert_attendee_drafts( array( $attendee ), 'stub', 'eva@example.org', 'access-eva', 'token-eva' );

		$this->assertCount( 1, $this->logged_entries( 'Created attendee draft.' ) );
		$this->assertCount( 1, $this->logged_entries( 'This attendee requested a visa letter.' ) );
		$this->assert_log_holds_no_visa_details();
	}

	/**
	 * Redaction replaces only this add-on's fields; the rest of the checkout log is unchanged.
	 */
	public function test_checkout_log_keeps_the_other_fields() {
		global $camptix;

		$_POST    = $this->posted_fields( array( 'tix_coupon' => 'EARLYBIRD' ) );
		$attendee = (object) array(
			'ticket_id'  => 0,
			'first_name' => 'Eva',
			'last_name'  => 'Horvat',
			'email'      => 'eva@example.org',
			'username'   => 'eva',
			'answers'    => array(),
		);

		$camptix->insert_attendee_drafts( array( $attendee ), 'stub', 'eva@example.org', 'access-eva', 'token-eva' );

		$posted = $this->logged_entries( 'Created attendee draft.' )[0]['data']['post'];
		$this->assertSame( 'EARLYBIRD', $posted['tix_coupon'] );
		$this->assertSame( '1', $posted['camptix-need-visa-letter'] );
		$this->assertSame( '[redacted]', $posted['visa-letter-passport-number'] );
	}

	/**
	 * The edit page logs the raw posted form (camptix.php "Changed attendee data from frontend."),
	 * and the add-on logs the request it saved from it.
	 */
	public function test_edit_page_request_keeps_visa_details_out_of_the_log() {
		global $camptix;

		$attendee_id = $this->make_attendee( 'eva' );
		$_POST       = $this->posted_fields();

		CampTix_Addon_Visa_Letters::edit_attendee_save( array(), get_post( $attendee_id ) );
		$camptix->log( 'Changed attendee data from frontend.', $attendee_id, $_POST );

		$this->assertCount( 1, $this->logged_entries( 'Attendee requested a visa letter from the ticket edit page.' ) );
		$this->assert_log_holds_no_visa_details();
	}

	/**
	 * Canadian mode posts travel dates and accommodation, which are redacted too.
	 */
	public function test_canadian_fields_are_kept_out_of_the_log() {
		global $camptix;

		$_POST = $this->posted_fields(
			array(
				'visa-letter-entry-date'    => '2027-05-01',
				'visa-letter-exit-date'     => '2027-05-09',
				'visa-letter-accommodation' => 'Hotel Example, Toronto',
			)
		);

		$camptix->log( 'Changed attendee data from frontend.', 0, $_POST );

		$this->assert_log_holds_no_visa_details();
	}
}
