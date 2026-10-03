<?php
/**
 * Tests for validating a visa letter request at checkout, including Canadian mode.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Checkout_Validation
 */
class Test_CampTix_Visa_Letters_Checkout_Validation extends WP_UnitTestCase {
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
		global $camptix;

		unset( $camptix->error_flags['visa_letter_nope'], $camptix->error_flags['visa_letter_canadian_dates'] );
		$this->camptix_errors( array() );

		$this->tear_down_visa_fixtures();
		parent::tear_down();
	}

	/**
	 * Whether the add-on flagged the request as invalid.
	 *
	 * @return bool
	 */
	protected function request_was_rejected() {
		global $camptix;

		return ! empty( $camptix->error_flags['visa_letter_nope'] ) || ! empty( $camptix->error_flags['visa_letter_canadian_dates'] );
	}

	/**
	 * CampTix's buffered error messages, optionally replacing them first.
	 *
	 * No setAccessible() call: it is a no-op as of PHP 8.1 and deprecated in 8.5.
	 *
	 * @param array|null $replace Messages to store instead, or null to only read.
	 * @return string[]
	 */
	protected function camptix_errors( $replace = null ) {
		global $camptix;

		$errors = new ReflectionProperty( 'CampTix_Plugin', 'errors' );
		if ( null !== $replace ) {
			$errors->setValue( $camptix, $replace );
		}

		return (array) $errors->getValue( $camptix );
	}

	/**
	 * Render the checkout visa form.
	 *
	 * @param array $options CampTix options.
	 * @return string
	 */
	protected function render_checkout_form( $options ) {
		ob_start();
		ctx_vl_letter_form( null, $options );

		return ob_get_clean();
	}

	/**
	 * A complete request is accepted and carried into the attendee info.
	 */
	public function test_complete_request_is_accepted() {
		$_POST = $this->posted_fields();

		$info = CampTix_Addon_Visa_Letters::attendee_info( array() );

		$this->assertFalse( $this->request_was_rejected() );
		$this->assertSame( 'AB1234567', $info['visa-letter-passport-number'] );
		$this->assertSame( 'eva@example.org', $info['visa-letter-email'] );
	}

	/**
	 * Without the checkbox, nothing is collected and nothing is rejected.
	 */
	public function test_unchecked_request_is_a_no_op() {
		$_POST = $this->posted_fields( array( 'camptix-need-visa-letter' => null ) );

		$info = CampTix_Addon_Visa_Letters::attendee_info( array( 'existing' => 'value' ) );

		$this->assertSame( array( 'existing' => 'value' ), $info );
		$this->assertFalse( $this->request_was_rejected() );
	}

	/**
	 * A missing required field is rejected before the purchase completes.
	 */
	public function test_missing_passport_number_is_rejected() {
		$_POST = $this->posted_fields( array( 'visa-letter-passport-number' => null ) );

		CampTix_Addon_Visa_Letters::attendee_info( array() );

		$this->assertTrue( $this->request_was_rejected() );
	}

	/**
	 * An address that is not an email address is rejected.
	 */
	public function test_invalid_email_is_rejected() {
		$_POST = $this->posted_fields( array( 'visa-letter-email' => 'not-an-email' ) );

		CampTix_Addon_Visa_Letters::attendee_info( array() );

		$this->assertTrue( $this->request_was_rejected() );
	}

	/**
	 * Submitted values are sanitized, not stored raw.
	 */
	public function test_submitted_values_are_sanitized() {
		$_POST = $this->posted_fields(
			array( 'visa-letter-first-name' => '<script>alert(1)</script>Eva' )
		);

		$info = CampTix_Addon_Visa_Letters::attendee_info( array() );

		$this->assertStringNotContainsString( '<script>', $info['visa-letter-first-name'] );
	}

	/**
	 * With Canadian mode off, the travel-date fields are neither required nor collected.
	 */
	public function test_canadian_fields_are_ignored_when_the_mode_is_off() {
		$this->set_visa_options( array( 'visa-letter-canadian' => 0 ) );

		$_POST = $this->posted_fields();

		$this->assertFalse( CampTix_Addon_Visa_Letters::validate_canadian_fields() );

		$info = CampTix_Addon_Visa_Letters::attendee_info( array() );

		$this->assertArrayNotHasKey( 'visa-letter-entry-date', $info );
		$this->assertFalse( $this->request_was_rejected() );
	}

	/**
	 * With Canadian mode on, entry and exit dates are required.
	 */
	public function test_canadian_mode_requires_entry_and_exit_dates() {
		$this->set_visa_options( array( 'visa-letter-canadian' => 1 ) );

		$_POST = $this->posted_fields();

		$result = CampTix_Addon_Visa_Letters::validate_canadian_fields();

		$this->assertWPError( $result );
		$this->assertSame( 'visa_letter_canadian_dates', $result->get_error_code() );

		CampTix_Addon_Visa_Letters::attendee_info( array() );

		$this->assertTrue( $this->request_was_rejected() );
	}

	/**
	 * An exit date before the entry date is rejected.
	 */
	public function test_exit_before_entry_is_rejected() {
		$this->set_visa_options( array( 'visa-letter-canadian' => 1 ) );

		$_POST = $this->posted_fields(
			array(
				'visa-letter-entry-date' => '2026-11-10',
				'visa-letter-exit-date'  => '2026-11-03',
			)
		);

		$this->assertWPError( CampTix_Addon_Visa_Letters::validate_canadian_fields() );
	}

	/**
	 * A valid Canadian request is accepted, accommodation included when given.
	 */
	public function test_valid_canadian_request_is_accepted() {
		$this->set_visa_options( array( 'visa-letter-canadian' => 1 ) );

		$_POST = $this->posted_fields(
			array(
				'visa-letter-entry-date'    => '2026-11-03',
				'visa-letter-exit-date'     => '2026-11-10',
				'visa-letter-accommodation' => 'Hotel Vancouver, 900 W Georgia St',
			)
		);

		$fields = CampTix_Addon_Visa_Letters::validate_canadian_fields();

		$this->assertSame( '2026-11-03', $fields['visa-letter-entry-date'] );
		$this->assertSame( '2026-11-10', $fields['visa-letter-exit-date'] );
		$this->assertSame( 'Hotel Vancouver, 900 W Georgia St', $fields['visa-letter-accommodation'] );

		$info = CampTix_Addon_Visa_Letters::attendee_info( array() );

		$this->assertFalse( $this->request_was_rejected() );
		$this->assertSame( '2026-11-03', $info['visa-letter-entry-date'] );
	}

	/**
	 * Accommodation stays optional.
	 */
	public function test_accommodation_is_optional() {
		$this->set_visa_options( array( 'visa-letter-canadian' => 1 ) );

		$_POST = $this->posted_fields(
			array(
				'visa-letter-entry-date' => '2026-11-03',
				'visa-letter-exit-date'  => '2026-11-10',
			)
		);

		$fields = CampTix_Addon_Visa_Letters::validate_canadian_fields();

		$this->assertIsArray( $fields );
		$this->assertArrayNotHasKey( 'visa-letter-accommodation', $fields );
	}

	/**
	 * The Canadian toggles survive the options validator.
	 */
	public function test_canadian_setting_is_validated() {
		$output = CampTix_Addon_Visa_Letters::validate_options(
			array(),
			array( 'visa-letter-canadian' => '1' )
		);

		$this->assertSame( 1, $output['visa-letter-canadian'] );
	}

	/**
	 * The checkout form only renders the Canada fields in Canadian mode.
	 */
	public function test_checkout_form_gates_the_canadian_fields() {
		$options = $this->set_visa_options( array( 'visa-letter-canadian' => 0 ) );

		ob_start();
		ctx_vl_letter_form( null, $options );
		$without = ob_get_clean();

		$options = $this->set_visa_options( array( 'visa-letter-canadian' => 1 ) );

		ob_start();
		ctx_vl_letter_form( null, $options );
		$with = ob_get_clean();

		$this->assertStringNotContainsString( 'visa-letter-entry-date', $without );
		$this->assertStringContainsString( 'visa-letter-entry-date', $with );
		$this->assertStringContainsString( 'visa-letter-exit-date', $with );
	}

	/**
	 * The checkout form renders nothing at all when the feature is inactive.
	 */
	public function test_checkout_form_renders_nothing_when_inactive() {
		$options = $this->set_visa_options( array( 'visa-letter-active' => 0 ) );

		ob_start();
		ctx_vl_letter_form( null, $options );

		$this->assertSame( '', trim( ob_get_clean() ) );
	}

	/**
	 * A checkout sent back with an error keeps the visa details the attendee typed.
	 *
	 * The form only prefilled from stored values, so after any error the box came back
	 * unticked and every field empty, and the passport details had to be typed again.
	 */
	public function test_failed_checkout_keeps_the_submitted_visa_details() {
		$options = $this->set_visa_options();
		$_POST   = $this->posted_fields( array( 'visa-letter-nationality' => '' ) );

		$html = $this->render_checkout_form( $options );

		$this->assertMatchesRegularExpression( '/id="camptix-need-visa-letter"\s+checked=/', $html );
		$this->assertStringContainsString( 'value="AB1234567"', $html );
		$this->assertStringContainsString( 'value="1990-04-17"', $html );
		$this->assertStringContainsString( '1 Example Street, Zagreb, Croatia</textarea>', $html );
	}

	/**
	 * Guard: an untouched checkout form, or one sent with the box unticked, stays empty.
	 */
	public function test_checkout_form_without_a_request_stays_empty() {
		$options = $this->set_visa_options();

		$fresh = $this->render_checkout_form( $options );

		$_POST    = $this->posted_fields( array( 'camptix-need-visa-letter' => null ) );
		$unticked = $this->render_checkout_form( $options );

		foreach ( array( $fresh, $unticked ) as $html ) {
			$this->assertDoesNotMatchRegularExpression( '/id="camptix-need-visa-letter"\s+checked=/', $html );
			$this->assertStringNotContainsString( 'AB1234567', $html );
		}
	}

	/**
	 * Exit-before-entry in Canadian mode shows the dates message, not "fill in all fields".
	 */
	public function test_canadian_dates_error_shows_its_own_message() {
		$this->set_visa_options( array( 'visa-letter-canadian' => 1 ) );
		$_POST = $this->posted_fields(
			array(
				'visa-letter-entry-date' => '2026-11-10',
				'visa-letter-exit-date'  => '2026-11-03',
			)
		);

		CampTix_Addon_Visa_Letters::attendee_info( array() );
		CampTix_Addon_Visa_Letters::error_flag();

		$this->assertTrue( $this->request_was_rejected() );
		$this->assertSame( array( 'Please provide valid entry and exit dates for Canada.' ), $this->camptix_errors() );
	}

	/**
	 * Guard: a missing required field still shows the general message.
	 */
	public function test_missing_fields_still_show_the_general_message() {
		$_POST = $this->posted_fields( array( 'visa-letter-passport-number' => null ) );

		CampTix_Addon_Visa_Letters::attendee_info( array() );
		CampTix_Addon_Visa_Letters::error_flag();

		$this->assertSame( array( 'As you have requested a visa letter, please fill in all required fields.' ), $this->camptix_errors() );
	}

	/**
	 * The visa form is shared by the whole order, so a multi-ticket order shows the message once.
	 */
	public function test_canadian_dates_message_shows_once_for_a_multi_ticket_order() {
		$this->set_visa_options( array( 'visa-letter-canadian' => 1 ) );
		$_POST = $this->posted_fields();

		CampTix_Addon_Visa_Letters::attendee_info( array() );
		CampTix_Addon_Visa_Letters::attendee_info( array() );
		CampTix_Addon_Visa_Letters::attendee_info( array() );
		CampTix_Addon_Visa_Letters::error_flag();

		$this->assertCount( 1, $this->camptix_errors() );
	}
}
