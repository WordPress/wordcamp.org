<?php
/**
 * Tests for requesting a letter after the ticket was already bought.
 *
 * This runs on the front-end attendee edit page, which CampTix core authenticates with
 * its own `tix_edit_token` before either hook fires.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Post_Purchase
 */
class Test_CampTix_Visa_Letters_Post_Purchase extends WP_UnitTestCase {
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
		$this->set_visa_options();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_REQUEST['tix_attendee_id'] );
		$this->tear_down_visa_fixtures();
		parent::tear_down();
	}

	/**
	 * Render the edit-page section for an attendee.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return string
	 */
	protected function render_form_for( $attendee_id ) {
		$_REQUEST['tix_attendee_id'] = $attendee_id;

		ob_start();
		CampTix_Addon_Visa_Letters::edit_attendee_form( array( 'ticket_id' => 0 ) );

		return ob_get_clean();
	}

	/**
	 * An attendee with no request sees an empty, unchecked form.
	 */
	public function test_form_offers_the_request_to_an_attendee_without_one() {
		$html = $this->render_form_for( $this->make_attendee( 'editblank' ) );

		$this->assertStringContainsString( 'camptix-need-visa-letter', $html );
		$this->assertStringContainsString( 'colspan="2"', $html );
		$this->assertDoesNotMatchRegularExpression( '/camptix-need-visa-letter[^>]*checked/', $html );
	}

	/**
	 * Details already staged at checkout are prefilled, decrypted.
	 */
	public function test_form_prefills_previously_submitted_details() {
		$attendee_id = $this->make_attendee( 'editprefill', 'publish', $this->letter_details() );

		$html = $this->render_form_for( $attendee_id );

		$this->assertStringContainsString( 'AB1234567', $html );
		$this->assertMatchesRegularExpression( '/camptix-need-visa-letter[^>]*checked/', $html );
	}

	/**
	 * Once a letter is issued the attendee sees its status, not a second request form.
	 */
	public function test_form_shows_status_instead_of_a_second_request() {
		list( $attendee_id, $letter_id ) = $this->make_paid_letter( 'editissued' );

		$number = get_post_meta( $letter_id, 'visa_letter_number', true );
		$html   = $this->render_form_for( $attendee_id );

		$this->assertStringContainsString( $number, $html );
		$this->assertStringNotContainsString( 'camptix-need-visa-letter', $html );
	}

	/**
	 * Nothing renders while the feature is switched off.
	 */
	public function test_form_renders_nothing_when_inactive() {
		$this->set_visa_options( array( 'visa-letter-active' => 0 ) );

		$this->assertSame( '', $this->render_form_for( $this->make_attendee( 'editoff' ) ) );
	}

	/**
	 * A valid request from a paid attendee issues the letter straight away.
	 */
	public function test_valid_request_from_a_paid_attendee_issues_a_letter() {
		$attendee_id = $this->make_attendee( 'editsave' );

		$_POST = $this->posted_fields();
		CampTix_Addon_Visa_Letters::edit_attendee_save( array(), get_post( $attendee_id ) );

		$letters = $this->letters_for( $attendee_id );
		$stored  = get_post_meta( $attendee_id, 'visa_letter_metas', true );

		$this->assertCount( 1, $letters );
		$this->assertSame( 'publish', $letters[0]->post_status );
		$this->assertStringStartsWith( 'ctxvl1:', $stored['passport_number'] );
		$this->assertSame( $letters[0]->ID, (int) get_post_meta( $attendee_id, 'tix_visa_letter_id', true ) );
	}

	/**
	 * An unpaid attendee's request is stored, and the letter waits for payment.
	 */
	public function test_request_from_an_unpaid_attendee_defers_the_letter() {
		$attendee_id = $this->make_attendee( 'editpending', 'pending' );

		$_POST = $this->posted_fields();
		CampTix_Addon_Visa_Letters::edit_attendee_save( array(), get_post( $attendee_id ) );

		$this->assertNotEmpty( get_post_meta( $attendee_id, 'visa_letter_metas', true ) );
		$this->assertCount( 0, $this->letters_for( $attendee_id ) );

		wp_update_post( array(
			'ID' => $attendee_id, 'post_status' => 'publish',
		) );
		do_action( 'camptix_payment_result', 'token-editpending', 2, array() );

		$this->assertCount( 1, $this->letters_for( $attendee_id ) );
	}

	/**
	 * An incomplete request stores nothing and tells the attendee why.
	 */
	public function test_incomplete_request_stores_nothing_and_reports_an_error() {
		$attendee_id = $this->make_attendee( 'editinvalid' );
		$before      = $this->camptix_error_count();

		$_POST = $this->posted_fields( array( 'visa-letter-passport-number' => null ) );
		CampTix_Addon_Visa_Letters::edit_attendee_save( array(), get_post( $attendee_id ) );

		$this->assertSame( '', get_post_meta( $attendee_id, 'visa_letter_metas', true ) );
		$this->assertCount( 0, $this->letters_for( $attendee_id ) );
		$this->assertGreaterThan( $before, $this->camptix_error_count() );
	}

	/**
	 * Saving the edit page without checking the box changes nothing.
	 */
	public function test_saving_without_the_checkbox_is_a_no_op() {
		$attendee_id = $this->make_attendee( 'editnoop' );

		$_POST = $this->posted_fields( array( 'camptix-need-visa-letter' => null ) );
		CampTix_Addon_Visa_Letters::edit_attendee_save( array(), get_post( $attendee_id ) );

		$this->assertSame( '', get_post_meta( $attendee_id, 'visa_letter_metas', true ) );
		$this->assertCount( 0, $this->letters_for( $attendee_id ) );
	}

	/**
	 * Re-submitting after a letter was issued cannot produce a second one.
	 */
	public function test_resaving_after_issue_does_not_duplicate_the_letter() {
		list( $attendee_id ) = $this->make_paid_letter( 'editresave' );

		$_POST = $this->posted_fields();
		CampTix_Addon_Visa_Letters::edit_attendee_save( array(), get_post( $attendee_id ) );

		$this->assertCount( 1, $this->letters_for( $attendee_id ) );
	}

	/**
	 * A request saved while the feature is off is ignored.
	 */
	public function test_saving_while_inactive_stores_nothing() {
		$this->set_visa_options( array( 'visa-letter-active' => 0 ) );

		$attendee_id = $this->make_attendee( 'editsaveoff' );

		$_POST = $this->posted_fields();
		CampTix_Addon_Visa_Letters::edit_attendee_save( array(), get_post( $attendee_id ) );

		$this->assertSame( '', get_post_meta( $attendee_id, 'visa_letter_metas', true ) );
		$this->assertCount( 0, $this->letters_for( $attendee_id ) );
	}

	/**
	 * A Canadian-mode request with a reversed date range is refused with its own message.
	 */
	public function test_canadian_date_error_is_reported_and_nothing_is_stored() {
		$this->set_visa_options( array( 'visa-letter-canadian' => 1 ) );

		$attendee_id = $this->make_attendee( 'editcanada' );
		$before      = $this->camptix_error_count();

		$_POST = $this->posted_fields(
			array(
				'visa-letter-entry-date' => '2026-11-10',
				'visa-letter-exit-date'  => '2026-11-03',
			)
		);
		CampTix_Addon_Visa_Letters::edit_attendee_save( array(), get_post( $attendee_id ) );

		$this->assertSame( '', get_post_meta( $attendee_id, 'visa_letter_metas', true ) );
		$this->assertGreaterThan( $before, $this->camptix_error_count() );
	}

	/**
	 * A valid Canadian request stores the travel dates alongside the rest.
	 */
	public function test_canadian_request_stores_the_travel_dates() {
		$this->set_visa_options( array( 'visa-letter-canadian' => 1 ) );

		$attendee_id = $this->make_attendee( 'editcanadaok' );

		$_POST = $this->posted_fields(
			array(
				'visa-letter-entry-date'    => '2026-11-03',
				'visa-letter-exit-date'     => '2026-11-10',
				'visa-letter-accommodation' => 'Hotel Vancouver',
			)
		);
		CampTix_Addon_Visa_Letters::edit_attendee_save( array(), get_post( $attendee_id ) );

		$stored = ctx_vl_open_metas( get_post_meta( $attendee_id, 'visa_letter_metas', true ) );

		$this->assertSame( '2026-11-03', $stored['entry_date'] );
		$this->assertSame( '2026-11-10', $stored['exit_date'] );
		$this->assertSame( 'Hotel Vancouver', $stored['accommodation'] );
	}
}
