<?php

defined( 'WPINC' ) || die();

/**
 * AJAX-layer tests for the sync-bulk endpoint: the CSRF nonce and capability
 * gates, exercised through the real wp_ajax dispatch.
 *
 * @group camptix-attendance
 * @group bulk-attendance
 * @group ajax
 */
class Test_Bulk_Ajax extends WP_Ajax_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	const SECRET = 'test-secret-1234567890abcdef1234';

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
	 * Register the AJAX handler on a fresh addon instance with a known secret.
	 */
	public function set_up() {
		parent::set_up();

		// Register the AJAX handler directly: CampTix caches its options object,
		// so flipping the attendance options here wouldn't reach camptix_init().
		$this->addon         = new CampTix_Attendance();
		$this->addon->secret = self::SECRET;

		add_filter( 'wp_ajax_camptix-attendance', array( $this->addon, 'ajax_callback' ) );
		add_filter( 'wp_ajax_nopriv_camptix-attendance', array( $this->addon, 'ajax_callback' ) );
	}

	/**
	 * Detach the AJAX handlers registered in set_up().
	 */
	public function tear_down() {
		remove_filter( 'wp_ajax_camptix-attendance', array( $this->addon, 'ajax_callback' ) );
		remove_filter( 'wp_ajax_nopriv_camptix-attendance', array( $this->addon, 'ajax_callback' ) );

		parent::tear_down();
	}

	/**
	 * Dispatch the AJAX action and return the decoded JSON response.
	 *
	 * @return array
	 */
	protected function dispatch() {
		try {
			$this->_handleAjax( 'camptix-attendance' );
		} catch ( WPAjaxDieContinueException $e ) {
			// wp_send_json() dies with '' — expected.
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			// A hard die (e.g. secret mismatch returns nothing) — also fine.
			unset( $e );
		}

		return json_decode( $this->_last_response, true ) ?: array();
	}

	/**
	 * Bulk requires nonce even for admins.
	 */
	public function test_bulk_requires_nonce_even_for_admins() {
		$this->_setRole( 'administrator' );

		$_REQUEST = array(
			'action'                 => 'camptix-attendance',
			'camptix_secret'         => self::SECRET,
			'camptix_action'         => 'sync-bulk',
			'camptix_dry_run'        => 1,
			'camptix_set_attendance' => 'true',
		);

		$_POST = $_REQUEST;

		$response = $this->dispatch();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'bad_nonce', $response['data']['error'] );
	}

	/**
	 * Bulk succeeds with nonce and capability.
	 */
	public function test_bulk_succeeds_with_nonce_and_capability() {
		$this->_setRole( 'administrator' );

		$ticket = self::factory()->post->create( array(
			'post_type' => 'tix_ticket', 'post_status' => 'publish',
		) );

		$attendee = self::factory()->post->create( array(
			'post_type' => 'tix_attendee', 'post_status' => 'publish',
		) );
		update_post_meta( $attendee, 'tix_ticket_id', $ticket );

		$_REQUEST = array(
			'action'                 => 'camptix-attendance',
			'camptix_secret'         => self::SECRET,
			'camptix_action'         => 'sync-bulk',
			'camptix_bulk_nonce'     => wp_create_nonce( 'camptix-attendance-bulk' ),
			'camptix_set_attendance' => 'true',
			'camptix_filters'        => array(
				'attendance' => 'none', 'tickets' => array( $ticket ),
			),
		);

		$_POST = $_REQUEST;

		$response = $this->dispatch();

		$this->assertTrue( $response['success'] );
		$this->assertSame( 1, $response['data']['matched'] );
		$this->assertSame( 1, $response['data']['changed'] );
		$this->assertSame( '1', get_post_meta( $attendee, 'tix_attended', true ) );
	}

	/**
	 * Bulk refuses anonymous even with nonce.
	 */
	public function test_bulk_refuses_anonymous_even_with_nonce() {
		$this->logout();

		$_REQUEST = array(
			'action'                 => 'camptix-attendance',
			'camptix_secret'         => self::SECRET,
			'camptix_action'         => 'sync-bulk',
			'camptix_bulk_nonce'     => wp_create_nonce( 'camptix-attendance-bulk' ),
			'camptix_dry_run'        => 1,
			'camptix_set_attendance' => 'true',
		);

		$_POST = $_REQUEST;

		$response = $this->dispatch();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'not_allowed', $response['data']['error'] );
	}

	/**
	 * The on-screen list and the bulk query must match the same attendees.
	 */
	public function test_sync_list_and_query_attendee_ids_match() {
		$this->_setRole( 'administrator' );

		$ticket = self::factory()->post->create( array(
			'post_type'   => 'tix_ticket',
			'post_status' => 'publish',
		) );

		foreach ( array( 'Ada Lovelace', 'Grace Hopper', 'Alan Turing' ) as $name ) {
			$attendee = self::factory()->post->create( array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'publish',
				'post_title'  => $name,
			) );

			update_post_meta( $attendee, 'tix_ticket_id', $ticket );
		}

		$filters = array(
			'attendance' => 'none',
			'tickets'    => array( $ticket ),
		);

		$_REQUEST = array(
			'action'          => 'camptix-attendance',
			'camptix_secret'  => self::SECRET,
			'camptix_action'  => 'sync-list',
			'camptix_filters' => $filters,
		);
		$_POST    = $_REQUEST;

		$response = $this->dispatch();

		$this->assertTrue( $response['success'] );

		// _make_object() returns the attendee post ID under 'id'.
		$listed = array_map( 'absint', wp_list_pluck( $response['data'], 'id' ) );
		$bulk   = $this->addon->query_attendee_ids( $filters );

		sort( $listed );
		sort( $bulk );

		$this->assertSame( $bulk, $listed, 'sync-list and query_attendee_ids disagree' );
	}
}
