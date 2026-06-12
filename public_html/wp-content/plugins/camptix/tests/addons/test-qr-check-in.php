<?php
defined( 'WPINC' ) || die();

/**
 * @covers CampTix_QR_Check_In
 */
class Test_CampTix_QR_Check_In extends \WP_UnitTestCase {
	/**
	 * The addon instance under test.
	 *
	 * @var CampTix_QR_Check_In
	 */
	protected $addon;

	/**
	 * Ensure the addon class is loaded and provide a fresh instance for each test.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'CampTix_QR_Check_In' ) ) {
			// The addon file calls camptix_register_addon() at the bottom, which emits a user
			// notice if CampTix has already initialized (as it has during the test bootstrap).
			// Swallow just that notice while including the file so it does not become an exception.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Temporary, scoped to the require below and immediately restored.
			set_error_handler( '__return_true', E_USER_NOTICE | E_USER_WARNING );
			require dirname( dirname( __DIR__ ) ) . '/addons/qr-check-in.php';
			restore_error_handler();
		}

		$this->addon = new CampTix_QR_Check_In();

		// Start each test from a known (unset) signing secret.
		delete_option( CampTix_QR_Check_In::SECRET_OPTION );
	}

	/**
	 * Create a tix_attendee with a given status and known name/email.
	 *
	 * @param string $status Post status.
	 * @return int Attendee post ID.
	 */
	protected function create_attendee( $status = 'publish' ) {
		$attendee_id = self::factory()->post->create(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => $status,
				'post_title'  => 'Ada Lovelace',
			)
		);

		update_post_meta( $attendee_id, 'tix_first_name', 'Ada' );
		update_post_meta( $attendee_id, 'tix_last_name', 'Lovelace' );
		update_post_meta( $attendee_id, 'tix_email', 'ada@example.test' );

		return $attendee_id;
	}

	/**
	 * Make the current user an organizer who can manage attendees.
	 *
	 * @return int User ID.
	 */
	protected function login_as_organizer() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * A signed token verifies back to the same attendee ID.
	 */
	public function test_token_round_trips_to_the_same_attendee_id() {
		$token = $this->addon->sign_token( 4242 );

		$this->assertSame( 4242, $this->addon->verify_token( $token ) );
	}

	/**
	 * Flipping a byte of the signature must fail verification.
	 */
	public function test_token_with_tampered_signature_is_rejected() {
		$token = $this->addon->sign_token( 7 );

		// Flip the first character of the signature segment (right after the "."). Its full 6 bits
		// feed the first decoded byte, so the signature is guaranteed to change. Flipping the *last*
		// character instead only toggles low padding bits that base64_decode() discards, so ~1 in 16
		// tokens decoded identically and the test failed at random.
		$dot                  = strpos( $token, '.' );
		$tampered             = $token;
		$tampered[ $dot + 1 ] = ( 'A' === $token[ $dot + 1 ] ) ? 'B' : 'A';

		$this->assertNotSame( $token, $tampered );
		$this->assertSame( 0, $this->addon->verify_token( $tampered ) );
	}

	/**
	 * A signature valid for one attendee must not validate for a different attendee ID.
	 */
	public function test_token_with_tampered_payload_is_rejected() {
		$token     = $this->addon->sign_token( 7 );
		$signature = substr( strstr( $token, '.' ), 1 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building a forged token to assert it is rejected.
		$forged = rtrim( strtr( base64_encode( '8' ), '+/', '-_' ), '=' ) . '.' . $signature;

		$this->assertSame( 0, $this->addon->verify_token( $forged ) );
	}

	/**
	 * Empty and structurally invalid tokens are rejected.
	 */
	public function test_malformed_tokens_are_rejected() {
		$this->assertSame( 0, $this->addon->verify_token( '' ) );
		$this->assertSame( 0, $this->addon->verify_token( 'no-separator' ) );
		$this->assertSame( 0, $this->addon->verify_token( '.' ) );
		$this->assertSame( 0, $this->addon->verify_token( 'not-base64.signature' ) );
	}

	/**
	 * Rotating the signing secret invalidates previously issued tokens.
	 */
	public function test_changing_the_secret_invalidates_existing_tokens() {
		$token = $this->addon->sign_token( 99 );
		$this->assertSame( 99, $this->addon->verify_token( $token ) );

		update_option( CampTix_QR_Check_In::SECRET_OPTION, wp_generate_password( 64, true, true ), false );

		$this->assertSame( 0, $this->addon->verify_token( $token ) );
	}

	/**
	 * A token signed on one site must not validate on another (multisite only).
	 */
	public function test_token_is_scoped_to_the_signing_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Cross-site token scoping requires multisite.' );
		}

		$token      = $this->addon->sign_token( 55 );
		$other_blog = self::factory()->blog->create();

		switch_to_blog( $other_blog );
		$verified = $this->addon->verify_token( $token );
		restore_current_blog();

		$this->assertSame( 0, $verified );
	}

	/**
	 * A published attendee is marked attended on check-in.
	 */
	public function test_publish_attendee_is_checked_in() {
		$this->login_as_organizer();
		$attendee_id = $this->create_attendee( 'publish' );

		$result = $this->addon->process_checkin( $attendee_id );

		$this->assertSame( 'success', $result['status'] );
		$this->assertTrue( (bool) get_post_meta( $attendee_id, 'tix_attended', true ) );
		$this->assertNotEmpty( get_post_meta( $attendee_id, CampTix_QR_Check_In::META_TIME, true ) );
	}

	/**
	 * Any non-publish status fails the scan and does not mark attendance.
	 *
	 * @dataProvider data_voided_statuses
	 *
	 * @param string $status Attendee post status.
	 */
	public function test_non_publish_attendee_fails_the_scan( $status ) {
		$this->login_as_organizer();
		$attendee_id = $this->create_attendee( $status );

		$result = $this->addon->process_checkin( $attendee_id );

		$this->assertSame( 'voided', $result['status'] );
		$this->assertEmpty( get_post_meta( $attendee_id, 'tix_attended', true ) );
	}

	/**
	 * Statuses that represent a voided/invalid ticket.
	 *
	 * @return array[]
	 */
	public function data_voided_statuses() {
		return array(
			'cancelled' => array( 'cancel' ),
			'refunded'  => array( 'refund' ),
			'failed'    => array( 'failed' ),
			'timed out' => array( 'timeout' ),
			'pending'   => array( 'pending' ),
		);
	}

	/**
	 * A nonexistent attendee ID returns "not found".
	 */
	public function test_missing_attendee_is_not_found() {
		$this->login_as_organizer();

		$result = $this->addon->process_checkin( 99999999 );

		$this->assertSame( 'not_found', $result['status'] );
	}

	/**
	 * A user lacking the attendee capability is denied and no attendance is recorded.
	 */
	public function test_user_without_capability_is_denied() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$attendee_id = $this->create_attendee( 'publish' );

		$result = $this->addon->process_checkin( $attendee_id );

		$this->assertSame( 'denied', $result['status'] );
		$this->assertEmpty( get_post_meta( $attendee_id, 'tix_attended', true ) );
	}

	/**
	 * The first successful check-in increments the "attended" stat by one.
	 */
	public function test_first_checkin_increments_attended_stat() {
		global $camptix;

		$this->login_as_organizer();
		$attendee_id = $this->create_attendee( 'publish' );

		$before = (int) $camptix->get_stats( 'attended' );
		$this->addon->process_checkin( $attendee_id );
		$after = (int) $camptix->get_stats( 'attended' );

		$this->assertSame( $before + 1, $after );
	}

	/**
	 * Re-scanning an already checked-in attendee is a soft warning that neither double-counts
	 * the stat nor changes the recorded check-in time.
	 */
	public function test_rescan_is_a_soft_warning_and_does_not_double_count() {
		global $camptix;

		$this->login_as_organizer();
		$attendee_id = $this->create_attendee( 'publish' );

		$this->addon->process_checkin( $attendee_id );
		$first_time  = get_post_meta( $attendee_id, CampTix_QR_Check_In::META_TIME, true );
		$after_first = (int) $camptix->get_stats( 'attended' );

		$second       = $this->addon->process_checkin( $attendee_id );
		$after_second = (int) $camptix->get_stats( 'attended' );

		$this->assertSame( 'already', $second['status'] );
		$this->assertSame( $after_first, $after_second, 'A re-scan must not increment the attended stat again.' );
		$this->assertSame( $first_time, get_post_meta( $attendee_id, CampTix_QR_Check_In::META_TIME, true ), 'A re-scan must preserve the original check-in time.' );
	}
}
