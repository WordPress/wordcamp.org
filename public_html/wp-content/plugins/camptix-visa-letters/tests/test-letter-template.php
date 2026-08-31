<?php
/**
 * Tests for the rendered letter content.
 *
 * The letter is the artifact an embassy reads, so its wording and its date formatting
 * are behaviour, not presentation detail.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Template
 */
class Test_CampTix_Visa_Letters_Template extends WP_UnitTestCase {
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
	 * Nationality reads as an adjective, and the passport is attributed to its issuer.
	 *
	 * The prototype produced "a citizen of Croatian" and did not say who issued the
	 * passport.
	 */
	public function test_nationality_and_passport_issuer_read_correctly() {
		list( , $letter_id ) = $this->make_paid_letter( 'wording' );

		$html = CampTix_Addon_Visa_Letters::render_letter_html( $letter_id );

		$this->assertStringContainsString( 'a Croatian citizen', $html );
		$this->assertStringContainsString( 'issued by Croatia', $html );
		$this->assertStringNotContainsString( 'a citizen of Croatian', $html );
	}

	/**
	 * The closing paragraph appears once.
	 */
	public function test_closing_paragraph_is_not_duplicated() {
		list( , $letter_id ) = $this->make_paid_letter( 'closing' );

		$html = CampTix_Addon_Visa_Letters::render_letter_html( $letter_id );

		$this->assertStringNotContainsString( 'I would be happy to provide any further information', $html );
	}

	/**
	 * The date of birth is presented in the configured letter format, not raw ISO.
	 */
	public function test_date_of_birth_uses_the_configured_format() {
		$this->set_visa_options( array( 'visa-letter-date-format' => 'F j, Y' ) );

		list( , $letter_id ) = $this->make_paid_letter( 'dob' );

		$html = CampTix_Addon_Visa_Letters::render_letter_html( $letter_id );

		$this->assertStringContainsString( 'April 17, 1990', $html );
		$this->assertStringNotContainsString( '1990-04-17', $html );
	}

	/**
	 * A different configured format is honoured too, so the first test is not a fluke.
	 */
	public function test_date_format_setting_is_respected() {
		list( , $letter_id ) = $this->make_paid_letter( 'dobformat' );

		$this->set_visa_options( array( 'visa-letter-date-format' => 'j F Y' ) );

		$html = CampTix_Addon_Visa_Letters::render_letter_html( $letter_id );

		$this->assertStringContainsString( '17 April 1990', $html );
	}

	/**
	 * The letter shows the passport number it was issued with.
	 */
	public function test_letter_states_the_passport_number() {
		list( , $letter_id ) = $this->make_paid_letter( 'passport' );

		$this->assertStringContainsString(
			'AB1234567',
			CampTix_Addon_Visa_Letters::render_letter_html( $letter_id )
		);
	}

	/**
	 * With Canadian mode off, none of the Canada-specific content appears.
	 */
	public function test_canadian_content_is_absent_when_the_mode_is_off() {
		list( , $letter_id ) = $this->make_paid_letter(
			'nocanada',
			array(
				'entry_date' => '2026-11-03',
				'exit_date'  => '2026-11-10',
			)
		);

		$this->set_visa_options( array( 'visa-letter-canadian' => 0 ) );

		$html = CampTix_Addon_Visa_Letters::render_letter_html( $letter_id );

		$this->assertStringNotContainsString( 'enter Canada', $html );
		$this->assertStringNotContainsString( 'Registration confirmation number', $html );
	}

	/**
	 * With Canadian mode on, the letter carries the IRCC additions.
	 */
	public function test_canadian_mode_adds_confirmation_number_and_travel_dates() {
		list( , $letter_id ) = $this->make_paid_letter(
			'canada',
			array(
				'entry_date'      => '2026-11-03',
				'exit_date'       => '2026-11-10',
				'accommodation'   => 'Hotel Vancouver, 900 W Georgia St',
				'transaction_id'  => 'txn-canada',
			)
		);

		$this->set_visa_options(
			array(
				'visa-letter-canadian'    => 1,
				'visa-letter-date-format' => 'F j, Y',
			)
		);

		$html = CampTix_Addon_Visa_Letters::render_letter_html( $letter_id );

		$this->assertStringContainsString( 'Registration confirmation number', $html );
		$this->assertStringContainsString( 'txn-canada', $html );
		$this->assertStringContainsString( 'enter Canada', $html );
		$this->assertStringContainsString( 'November 3, 2026', $html );
		$this->assertStringContainsString( 'November 10, 2026', $html );
		$this->assertStringContainsString( 'Hotel Vancouver', $html );
	}
}
