<?php
/**
 * Tests for passport-number encryption at rest.
 *
 * A plaintext passport number in `postmeta` is readable by anything with database
 * access, including exports and backups, so it is sealed before it is stored and only
 * opened in memory for rendering and for the admin metaboxes.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Encryption
 */
class Test_CampTix_Visa_Letters_Encryption extends WP_UnitTestCase {
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
	 * Sealing then opening a value returns the original.
	 */
	public function test_sealing_round_trips() {
		$sealed = ctx_vl_seal_metas( $this->letter_details() );

		$this->assertStringStartsWith( 'ctxvl1:', $sealed['passport_number'] );
		$this->assertStringNotContainsString( 'AB1234567', maybe_serialize( $sealed ) );
		$this->assertSame( 'AB1234567', ctx_vl_open_metas( $sealed )['passport_number'] );
	}

	/**
	 * Only the passport number is sealed; the rest stays readable for admin display.
	 */
	public function test_only_the_passport_number_is_sealed() {
		$sealed = ctx_vl_seal_metas( $this->letter_details() );

		$this->assertSame( 'Eva', $sealed['first_name'] );
		$this->assertSame( '1990-04-17', $sealed['date_of_birth'] );
	}

	/**
	 * Sealing is idempotent, so a re-save cannot double-encrypt a stored value.
	 */
	public function test_sealing_an_already_sealed_value_is_a_no_op() {
		$once  = ctx_vl_seal_metas( $this->letter_details() );
		$twice = ctx_vl_seal_metas( $once );

		$this->assertSame( $once['passport_number'], $twice['passport_number'] );
		$this->assertSame( 'AB1234567', ctx_vl_open_metas( $twice )['passport_number'] );
	}

	/**
	 * A value stored before encryption existed is passed through unchanged.
	 */
	public function test_legacy_plaintext_is_returned_as_is() {
		$legacy = $this->letter_details();

		$this->assertSame( 'AB1234567', ctx_vl_open_metas( $legacy )['passport_number'] );
	}

	/**
	 * Ciphertext that cannot be opened degrades to an empty string, it does not raise.
	 *
	 * That is what a rotated `auth` salt looks like: the letter renders without the
	 * passport number, and `is_letter_incomplete()` flags it, rather than fatalling.
	 */
	public function test_unopenable_ciphertext_degrades_to_an_empty_string() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- deliberately builds ciphertext this install's key cannot open.
		$tampered = array( 'passport_number' => 'ctxvl1:' . base64_encode( random_bytes( 64 ) ) );

		$this->assertSame( '', ctx_vl_open_metas( $tampered )['passport_number'] );
	}

	/**
	 * Malformed ciphertext is also empty rather than an error.
	 */
	public function test_malformed_ciphertext_degrades_to_an_empty_string() {
		$this->assertSame( '', ctx_vl_open_metas( array( 'passport_number' => 'ctxvl1:not-base64-$$$' ) )['passport_number'] );
		$this->assertSame( '', ctx_vl_open_metas( array( 'passport_number' => 'ctxvl1:' ) )['passport_number'] );
	}

	/**
	 * The checkout write path stores ciphertext on the attendee.
	 */
	public function test_checkout_stores_ciphertext_on_the_attendee() {
		$this->set_visa_options();

		$attendee_id = $this->make_attendee( 'checkout-seal' );
		$attendee    = CampTix_Addon_Visa_Letters::attendee_object(
			new stdClass(),
			$this->posted_fields()
		);

		CampTix_Addon_Visa_Letters::add_meta_visa_letter_on_attendee( $attendee_id, $attendee );

		$stored = get_post_meta( $attendee_id, 'visa_letter_metas', true );

		$this->assertIsArray( $stored );
		$this->assertStringStartsWith( 'ctxvl1:', $stored['passport_number'] );
		$this->assertStringNotContainsString( 'AB1234567', maybe_serialize( $stored ) );
	}

	/**
	 * The issued letter also holds ciphertext, not the plaintext number.
	 */
	public function test_issued_letter_stores_ciphertext() {
		list( , $letter_id ) = $this->make_paid_letter( 'letter-seal' );

		$stored = get_post_meta( $letter_id, 'visa_letter_metas', true );

		$this->assertIsArray( $stored );
		$this->assertStringStartsWith( 'ctxvl1:', $stored['passport_number'] );
		$this->assertSame( 'AB1234567', ctx_vl_open_metas( $stored )['passport_number'] );
	}

	/**
	 * The rendered letter shows the real number, so the seal is transparent downstream.
	 */
	public function test_rendered_letter_contains_the_plaintext_number() {
		list( , $letter_id ) = $this->make_paid_letter( 'render-seal' );

		$html = CampTix_Addon_Visa_Letters::render_letter_html( $letter_id );

		$this->assertStringContainsString( 'AB1234567', $html );
	}
}
