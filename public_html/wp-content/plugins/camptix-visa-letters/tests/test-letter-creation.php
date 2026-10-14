<?php
/**
 * Tests for issuing a visa letter off the payment-completion path.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Class Test_CampTix_Visa_Letters_Creation
 */
class Test_CampTix_Visa_Letters_Creation extends WP_UnitTestCase {
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
	 * A completed payment issues exactly one letter, linked both ways.
	 */
	public function test_completed_payment_issues_one_linked_letter() {
		list( $attendee_id, $letter_id ) = $this->make_paid_letter( 'issue' );

		$this->assertNotEmpty( $letter_id );
		$this->assertSame( 'publish', get_post_status( $letter_id ) );
		$this->assertSame( $attendee_id, (int) get_post_meta( $letter_id, 'attendee_id', true ) );
		$this->assertSame( $letter_id, (int) get_post_meta( $attendee_id, 'tix_visa_letter_id', true ) );
	}

	/**
	 * A gateway reporting the same completed payment twice must not issue two letters.
	 *
	 * Gateways report a result on both the interactive return and the webhook, and a
	 * refresh can replay it.
	 */
	public function test_repeated_payment_result_does_not_duplicate_the_letter() {
		list( $attendee_id ) = $this->make_paid_letter( 'idempotent' );

		do_action( 'camptix_payment_result', 'token-idempotent', 2, array() );
		do_action( 'camptix_payment_result', 'token-idempotent', 2, array() );

		$this->assertCount( 1, $this->letters_for( $attendee_id ) );
	}

	/**
	 * An incomplete payment result issues nothing.
	 */
	public function test_unfinished_payment_issues_no_letter() {
		$this->set_visa_options();

		$attendee_id = $this->make_attendee( 'unpaid', 'publish', $this->letter_details() );

		do_action( 'camptix_payment_result', 'token-unpaid', 1, array() );

		$this->assertCount( 0, $this->letters_for( $attendee_id ) );
	}

	/**
	 * An attendee who never asked for a letter never gets one.
	 */
	public function test_attendee_without_a_request_gets_no_letter() {
		$this->set_visa_options();

		$attendee_id = $this->make_attendee( 'norequest' );

		do_action( 'camptix_payment_result', 'token-norequest', 2, array() );

		$this->assertCount( 0, $this->letters_for( $attendee_id ) );
	}

	/**
	 * Letter numbers are sequential and carry the blog and year.
	 */
	public function test_letter_numbers_are_sequential_and_scoped() {
		list( , $first_id )  = $this->make_paid_letter( 'number-one' );
		list( , $second_id ) = $this->make_paid_letter( 'number-two' );

		$first  = get_post_meta( $first_id, 'visa_letter_number', true );
		$second = get_post_meta( $second_id, 'visa_letter_number', true );

		$pattern = '/^' . get_current_blog_id() . '-VL-' . wp_date( 'Y' ) . '-(\d+)$/';

		$this->assertMatchesRegularExpression( $pattern, $first );
		$this->assertMatchesRegularExpression( $pattern, $second );

		preg_match( $pattern, $first, $first_match );
		preg_match( $pattern, $second, $second_match );

		$this->assertSame( (int) $first_match[1] + 1, (int) $second_match[1] );
	}

	/**
	 * A letter missing required details is held as a draft rather than issued.
	 */
	public function test_letter_missing_required_details_is_held_as_a_draft() {
		$this->set_visa_options();

		$attendee_id = $this->make_attendee(
			'incomplete',
			'publish',
			$this->letter_details( array( 'passport_number' => '' ) )
		);

		do_action( 'camptix_payment_result', 'token-incomplete', 2, array() );

		$letters = $this->letters_for( $attendee_id );

		$this->assertCount( 1, $letters );
		$this->assertSame( 'draft', get_post_status( $letters[0]->ID ) );
		$this->assertTrue( CampTix_Addon_Visa_Letters::is_letter_incomplete( $letters[0]->ID ) );
	}

	/**
	 * Moving a letter to draft removes its document from disk.
	 */
	public function test_moving_a_letter_to_draft_deletes_its_document() {
		list( , $letter_id ) = $this->make_paid_letter( 'todraft' );
		$filename            = $this->attach_stub_pdf( $letter_id );
		$path                = ctx_vl_get_letters_dir() . '/' . $filename;

		$this->assertFileExists( $path );

		wp_update_post( array(
			'ID' => $letter_id, 'post_status' => 'draft',
		) );

		$this->assertFileDoesNotExist( $path );
		$this->assertSame( '', get_post_meta( $letter_id, 'visa_letter_document', true ) );
	}
	/**
	 * The visa form is shared by the whole order, so checkout stores the request on the
	 * first attendee only. Copying it to every attendee would show attendee 1's passport
	 * details on attendee 2's edit page.
	 */
	public function test_checkout_stores_the_request_on_the_first_attendee_only() {
		$this->set_visa_options();
		list( $first, $second ) = $this->make_order( 'shared', 2 );

		// What attendee_object() gives every attendee of the order.
		foreach ( array( $first, $second ) as $attendee_id ) {
			$attendee              = new stdClass();
			$attendee->visa_letter = $this->letter_details();
			CampTix_Addon_Visa_Letters::add_meta_visa_letter_on_attendee( $attendee_id, $attendee );
		}

		$this->assertNotEmpty( get_post_meta( $first, 'visa_letter_metas', true ) );
		$this->assertSame( '', get_post_meta( $second, 'visa_letter_metas', true ) );
	}

	/**
	 * A completed payment issues the letter for the attendee who requested it, whatever
	 * order the attendees are read in.
	 */
	public function test_completed_payment_issues_the_letter_for_the_requesting_attendee() {
		$this->set_visa_options();
		list( $first, $second ) = $this->make_order( 'buyer', 2 );
		update_post_meta( $first, 'visa_letter_metas', ctx_vl_seal_metas( $this->letter_details() ) );

		// A checkout that crosses a second: the buyer is no longer the newest post.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 1 ) ), array( 'ID' => $second ) );
		clean_post_cache( $second );

		do_action( 'camptix_payment_result', 'token-buyer', 2, array() );

		$this->assertCount( 1, $this->letters_for( $first ) );
		$this->assertCount( 0, $this->letters_for( $second ) );
	}

	/**
	 * Another attendee of the order can ask for their own letter before payment, from their
	 * edit page. A completed payment issues both letters.
	 */
	public function test_completed_payment_issues_each_attendees_own_request() {
		$this->set_visa_options();
		list( $first, $second ) = $this->make_order( 'both', 2 );
		update_post_meta( $first, 'visa_letter_metas', ctx_vl_seal_metas( $this->letter_details() ) );
		update_post_meta( $second, 'visa_letter_metas', ctx_vl_seal_metas( $this->letter_details( array( 'passport_number' => 'ZZ9876543' ) ) ) );

		do_action( 'camptix_payment_result', 'token-both', 2, array() );
		do_action( 'camptix_payment_result', 'token-both', 2, array() );

		$this->assertCount( 1, $this->letters_for( $first ) );
		$this->assertCount( 1, $this->letters_for( $second ) );
	}
}
