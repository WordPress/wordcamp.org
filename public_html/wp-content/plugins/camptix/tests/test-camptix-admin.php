<?php

defined( 'WPINC' ) || die();

require_once __DIR__ . '/trait-wordcamp-root-blog.php';

/**
 * Tests for CampTix_Plugin admin-related functionality.
 *
 * These integration tests cover ticket validation, coupon management,
 * stats tracking, revenue calculations, and save logic -- the core
 * admin methods that will be extracted into dedicated addon files.
 *
 * @covers CampTix_Plugin
 */
class Test_CampTix_Admin extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * @var CampTix_Plugin
	 */
	protected static $camptix;

	/**
	 * Ticket post IDs created for tests.
	 *
	 * @var array
	 */
	protected static $tickets = array();

	/**
	 * Coupon post IDs created for tests.
	 *
	 * @var array
	 */
	protected static $coupons = array();

	/**
	 * Attendee post IDs created for tests.
	 *
	 * @var array
	 */
	protected static $attendees = array();

	/**
	 * Set up shared fixtures before any tests run.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::create_wordcamp_root_blog( $factory );

		self::$camptix = $GLOBALS['camptix'];

		// Ensure options are initialised via the public API.
		update_option(
			'camptix_options',
			array_merge(
				self::$camptix->get_default_options(),
				array(
					'refunds_enabled'  => false,
					'refunds_date_end' => '',
				)
			)
		);

		// Force re-read of options on next access.
		self::$camptix->init();
	}

	/**
	 * Tears down the shared fixtures created in wpSetUpBeforeClass().
	 */
	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		// Clean up posts created in individual tests.
		foreach ( array_merge( self::$tickets, self::$coupons, self::$attendees ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		self::$tickets   = array();
		self::$coupons   = array();
		self::$attendees = array();

		remove_filter( 'camptix_options', array( $this, 'enable_reservations' ) );
		remove_filter( 'query', array( $this, 'record_query' ) );
		remove_filter( 'camptix_stripe_checkout_session_lifetime', array( $this, 'one_hour' ) );
		self::$camptix->load_options();
		self::$camptix->release_checkout_rows();
		$this->seen_queries = array();
		wp_set_current_user( 0 );

		unset(
			$_GET['post_type'],
			$_GET['s'],
			$_GET['tix_coupon_id'],
			$_REQUEST['post_type'],
			$_REQUEST['s'],
			$_REQUEST['tix_coupon_id']
		);

		parent::tear_down();
	}

	/**
	 * Create a ticket post with metadata.
	 *
	 * @param array $args Optional ticket arguments.
	 * @return int Post ID.
	 */
	protected function create_ticket( $args = array() ) {
		$defaults = array(
			'title'    => 'General Admission',
			'price'    => 25.00,
			'quantity' => 100,
			'start'    => '',
			'end'      => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		$post_id = wp_insert_post( array(
			'post_type'   => 'tix_ticket',
			'post_status' => 'publish',
			'post_title'  => $args['title'],
		) );

		update_post_meta( $post_id, 'tix_price', $args['price'] );
		update_post_meta( $post_id, 'tix_quantity', $args['quantity'] );
		update_post_meta( $post_id, 'tix_start', $args['start'] );
		update_post_meta( $post_id, 'tix_end', $args['end'] );

		self::$tickets[] = $post_id;

		return $post_id;
	}

	/**
	 * Enable reservations and attach a quantity-1 'speakertoken' reservation to a ticket.
	 *
	 * @param int $ticket_id Ticket post ID.
	 */
	protected function create_reservation( $ticket_id ) {
		add_filter( 'camptix_options', array( $this, 'enable_reservations' ) );
		self::$camptix->load_options();

		add_post_meta(
			$ticket_id,
			'tix_reservation',
			array(
				'id'        => 'speakers',
				'token'     => 'speakertoken',
				'quantity'  => 1,
				'name'      => 'Speakers',
				'ticket_id' => $ticket_id,
			)
		);
	}

	/**
	 * Create a coupon post with metadata.
	 *
	 * @param array $args Optional coupon arguments.
	 * @return int Post ID.
	 */
	protected function create_coupon( $args = array() ) {
		$defaults = array(
			'code'           => 'TESTCOUPON',
			'discount_price' => 0,
			'discount_pct'   => 0,
			'quantity'       => 10,
			'start'          => '',
			'end'            => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		$post_id = wp_insert_post( array(
			'post_type'   => 'tix_coupon',
			'post_status' => 'publish',
			'post_title'  => $args['code'],
		) );

		if ( $args['discount_price'] > 0 ) {
			update_post_meta( $post_id, 'tix_discount_price', $args['discount_price'] );
		}
		if ( $args['discount_pct'] > 0 ) {
			update_post_meta( $post_id, 'tix_discount_percent', $args['discount_pct'] );
		}
		update_post_meta( $post_id, 'tix_coupon_quantity', $args['quantity'] );
		update_post_meta( $post_id, 'tix_coupon_start', $args['start'] );
		update_post_meta( $post_id, 'tix_coupon_end', $args['end'] );

		self::$coupons[] = $post_id;

		return $post_id;
	}

	/**
	 * Create an attendee post linked to a ticket.
	 *
	 * @param int   $ticket_id Ticket post ID.
	 * @param array $args      Optional attendee arguments.
	 * @return int Post ID.
	 */
	protected function create_attendee( $ticket_id, $args = array() ) {
		$defaults = array(
			'status'           => 'publish',
			'ticket_price'     => 25.00,
			'discounted_price' => 25.00,
			'order_total'      => 25.00,
			'transaction_id'   => '',
			'coupon_id'        => '',
			'payment_method'   => '',
			'reservation'      => '',
			'timestamp'        => 0,
			'username'         => '',
			'payment_token'    => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		$post_id = wp_insert_post( array(
			'post_type'   => 'tix_attendee',
			'post_status' => $args['status'],
			'post_title'  => 'Test Attendee',
		) );

		update_post_meta( $post_id, 'tix_ticket_id', $ticket_id );
		update_post_meta( $post_id, 'tix_ticket_price', $args['ticket_price'] );
		update_post_meta( $post_id, 'tix_ticket_discounted_price', $args['discounted_price'] );
		update_post_meta( $post_id, 'tix_order_total', $args['order_total'] );

		if ( ! empty( $args['transaction_id'] ) ) {
			update_post_meta( $post_id, 'tix_transaction_id', $args['transaction_id'] );
		}
		if ( ! empty( $args['coupon_id'] ) ) {
			update_post_meta( $post_id, 'tix_coupon_id', $args['coupon_id'] );

			$coupon = get_post( $args['coupon_id'] );
			if ( $coupon ) {
				update_post_meta( $post_id, 'tix_coupon', $coupon->post_title );
			}
		}
		if ( ! empty( $args['payment_method'] ) ) {
			update_post_meta( $post_id, 'tix_payment_method', $args['payment_method'] );
		}
		if ( ! empty( $args['reservation'] ) ) {
			update_post_meta( $post_id, 'tix_reservation_token', $args['reservation'] );
		}
		if ( ! empty( $args['timestamp'] ) ) {
			update_post_meta( $post_id, 'tix_timestamp', $args['timestamp'] );
		}
		if ( ! empty( $args['username'] ) ) {
			update_post_meta( $post_id, 'tix_username', $args['username'] );
		}
		if ( ! empty( $args['payment_token'] ) ) {
			update_post_meta( $post_id, 'tix_payment_token', $args['payment_token'] );
			// payment_result() reads the order off the attendee; give it a valid empty one.
			update_post_meta(
				$post_id,
				'tix_order',
				array(
					'items' => array(),
					'total' => 0,
				)
			);
		}

		self::$attendees[] = $post_id;

		return $post_id;
	}

	/**
	 * Set up the $_POST and nonce for simulating a save_post admin action.
	 *
	 * @param int $post_id Post ID to simulate saving.
	 */
	protected function simulate_admin_save( $post_id ) {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		set_current_screen( 'edit' );

		$_POST['action']      = 'editpost';
		$_POST['_wpnonce']    = wp_create_nonce( 'update-post_' . $post_id );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
	}

	/**
	 * Clean up $_POST superglobal after save simulation.
	 */
	protected function cleanup_post_data() {
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Verify a valid ticket is accepted for display.
	 */
	public function test_ticket_valid_for_display_with_valid_ticket() {
		$ticket_id = $this->create_ticket();
		$this->assertTrue( self::$camptix->is_ticket_valid_for_display( $ticket_id ) );
	}

	/**
	 * Verify a non-ticket post is rejected for display.
	 */
	public function test_ticket_valid_for_display_with_non_ticket_post() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertFalse( self::$camptix->is_ticket_valid_for_display( $page_id ) );
	}

	/**
	 * Verify an invalid post ID is rejected for display.
	 */
	public function test_ticket_valid_for_display_with_invalid_id() {
		$this->assertFalse( self::$camptix->is_ticket_valid_for_display( 999999 ) );
	}

	/**
	 * Verify remaining tickets equals quantity when no attendees exist.
	 */
	public function test_remaining_tickets_full_inventory() {
		$ticket_id = $this->create_ticket( array( 'quantity' => 50 ) );
		$this->assertSame( 50, self::$camptix->get_remaining_tickets( $ticket_id ) );
	}

	/**
	 * Verify remaining tickets decreases with published attendees.
	 */
	public function test_remaining_tickets_decreases_with_published_attendees() {
		$ticket_id = $this->create_ticket( array( 'quantity' => 10 ) );
		$this->create_attendee( $ticket_id );
		$this->create_attendee( $ticket_id );

		$this->assertSame( 8, self::$camptix->get_remaining_tickets( $ticket_id ) );
	}

	/**
	 * Verify pending attendees count toward purchased tickets.
	 */
	public function test_remaining_tickets_counts_pending_attendees() {
		$ticket_id = $this->create_ticket( array( 'quantity' => 10 ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'pending' ) );

		$this->assertSame( 9, self::$camptix->get_remaining_tickets( $ticket_id ) );
	}

	/**
	 * A draft attendee is a checkout waiting on the payment gateway. It holds its
	 * seat, so the ticket cannot be sold again underneath it.
	 */
	public function test_remaining_tickets_counts_draft_attendees() {
		$ticket_id = $this->create_ticket( array( 'quantity' => 10 ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'draft' ) );

		$this->assertSame( 9, self::$camptix->get_remaining_tickets( $ticket_id ) );
	}

	/**
	 * A checkout that ended without a sale releases its seat.
	 *
	 * @testWith ["cancel"]
	 *           ["failed"]
	 *           ["timeout"]
	 *           ["refund"]
	 */
	public function test_remaining_tickets_ignores_finished_checkouts( $status ) {
		$ticket_id = $this->create_ticket( array( 'quantity' => 10 ) );
		$this->create_attendee( $ticket_id, array( 'status' => $status ) );

		$this->assertSame( 10, self::$camptix->get_remaining_tickets( $ticket_id ) );
	}

	/**
	 * A checkout abandoned more than 24 hours ago is timed out by the sweep and
	 * its seat goes back on sale. A younger one is left alone.
	 */
	public function test_timeout_sweep_releases_abandoned_checkout() {
		$ticket_id   = $this->create_ticket( array( 'quantity' => 2 ) );
		$abandoned   = $this->create_attendee(
			$ticket_id,
			array(
				'status'    => 'draft',
				'timestamp' => time() - 25 * HOUR_IN_SECONDS,
			)
		);
		$in_progress = $this->create_attendee(
			$ticket_id,
			array(
				'status'    => 'draft',
				'timestamp' => time() - HOUR_IN_SECONDS,
			)
		);

		$this->assertSame( 0, self::$camptix->get_remaining_tickets( $ticket_id ) );

		self::$camptix->review_timeout_payments();

		$this->assertSame( 'timeout', get_post_status( $abandoned ) );
		$this->assertSame( 'draft', get_post_status( $in_progress ) );
		$this->assertSame( 1, self::$camptix->get_remaining_tickets( $ticket_id ) );
	}

	/**
	 * The sweep runs on the ten-minute schedule, so an abandoned checkout holds
	 * its seat for about 24 hours rather than anywhere up to 48.
	 */
	public function test_timeout_sweep_runs_every_ten_minutes() {
		$this->assertNotFalse( has_action( 'tix_scheduled_every_ten_minutes', array( self::$camptix, 'review_timeout_payments' ) ) );
		$this->assertFalse( has_action( 'tix_scheduled_daily', array( self::$camptix, 'review_timeout_payments' ) ) );
	}

	/**
	 * Verify purchased count is zero when no attendees exist.
	 */
	public function test_purchased_tickets_count_with_no_attendees() {
		$ticket_id = $this->create_ticket();
		$this->assertSame( 0, self::$camptix->get_purchased_tickets_count( $ticket_id ) );
	}

	/**
	 * Verify publish, pending and draft attendees count as purchased, and the
	 * statuses a checkout ends in without a sale do not.
	 */
	public function test_purchased_tickets_count_with_mixed_statuses() {
		$ticket_id = $this->create_ticket();
		$this->create_attendee( $ticket_id, array( 'status' => 'publish' ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'pending' ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'draft' ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'cancel' ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'failed' ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'timeout' ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'refund' ) );

		$this->assertSame( 3, self::$camptix->get_purchased_tickets_count( $ticket_id ) );
	}

	/**
	 * A checkout in progress against a reservation uses up one of its seats.
	 */
	public function test_reservation_counts_draft_attendees() {
		add_filter( 'camptix_options', array( $this, 'enable_reservations' ) );
		self::$camptix->load_options();

		$ticket_id = $this->create_ticket( array( 'quantity' => 5 ) );
		add_post_meta(
			$ticket_id,
			'tix_reservation',
			array(
				'id'        => 'speakers',
				'token'     => 'speakertoken',
				'quantity'  => 1,
				'name'      => 'Speakers',
				'ticket_id' => $ticket_id,
			)
		);

		$this->assertTrue( self::$camptix->is_reservation_valid_for_use( 'speakertoken' ) );

		$this->create_attendee(
			$ticket_id,
			array(
				'status'      => 'draft',
				'reservation' => 'speakertoken',
			)
		);

		$this->assertFalse( self::$camptix->is_reservation_valid_for_use( 'speakertoken' ) );
	}

	/**
	 * Filter callback for camptix_options: turn reservations on for a test.
	 *
	 * @param array $options CampTix options.
	 * @return array
	 */
	public function enable_reservations( $options ) {
		$options['reservations_enabled'] = true;

		return $options;
	}

	/**
	 * Verify purchased count can be filtered by reservation token.
	 */
	public function test_purchased_tickets_count_filters_by_reservation() {
		$ticket_id = $this->create_ticket();
		$this->create_attendee( $ticket_id, array( 'reservation' => 'res_abc' ) );
		$this->create_attendee( $ticket_id, array( 'reservation' => 'res_abc' ) );
		$this->create_attendee( $ticket_id, array( 'reservation' => 'res_xyz' ) );
		$this->create_attendee( $ticket_id ); // No reservation.

		$this->assertSame( 2, self::$camptix->get_purchased_tickets_count( $ticket_id, 'res_abc' ) );
		$this->assertSame( 1, self::$camptix->get_purchased_tickets_count( $ticket_id, 'res_xyz' ) );
	}

	/**
	 * Verify get_coupon_by_code returns the correct coupon post.
	 *
	 * @expectedDeprecated get_page_by_title
	 */
	public function test_get_coupon_by_code_returns_coupon() {
		$coupon_id = $this->create_coupon( array( 'code' => 'EARLYBIRD' ) );
		$coupon    = self::$camptix->get_coupon_by_code( 'EARLYBIRD' );

		$this->assertInstanceOf( 'WP_Post', $coupon );
		$this->assertSame( $coupon_id, $coupon->ID );
	}

	/**
	 * Verify get_coupon_by_code returns false for nonexistent codes.
	 *
	 * @expectedDeprecated get_page_by_title
	 */
	public function test_get_coupon_by_code_returns_false_for_nonexistent() {
		$this->assertFalse( self::$camptix->get_coupon_by_code( 'DOESNOTEXIST' ) );
	}

	/**
	 * Verify get_coupon_by_code rejects empty string input.
	 */
	public function test_get_coupon_by_code_rejects_empty_string() {
		$this->assertFalse( self::$camptix->get_coupon_by_code( '' ) );
	}

	/**
	 * Verify get_coupon_by_code rejects whitespace-only input.
	 */
	public function test_get_coupon_by_code_rejects_whitespace() {
		$this->assertFalse( self::$camptix->get_coupon_by_code( '   ' ) );
	}

	/**
	 * Verify get_coupon_by_code rejects non-string input types.
	 */
	public function test_get_coupon_by_code_rejects_non_string() {
		$this->assertFalse( self::$camptix->get_coupon_by_code( 12345 ) );
		$this->assertFalse( self::$camptix->get_coupon_by_code( null ) );
		$this->assertFalse( self::$camptix->get_coupon_by_code( array( 'code' ) ) );
	}

	/**
	 * Verify attendee coupon column links to the coupon filter.
	 */
	public function test_attendee_coupon_column_links_to_coupon_filter() {
		$ticket_id   = $this->create_ticket();
		$coupon_id   = $this->create_coupon( array( 'code' => 'EARLYBIRD' ) );
		$attendee_id = $this->create_attendee( $ticket_id, array( 'coupon_id' => $coupon_id ) );

		ob_start();
		self::$camptix->manage_columns_attendee_action( 'tix_coupon', $attendee_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'tix_coupon_id=' . $coupon_id, $output );
		$this->assertStringNotContainsString( 's=', $output );
	}

	/**
	 * Verify coupon used column links to the coupon filter.
	 */
	public function test_coupon_used_column_links_to_coupon_filter() {
		$ticket_id = $this->create_ticket();
		$coupon_id = $this->create_coupon( array( 'code' => 'EARLYBIRD' ) );
		$this->create_attendee( $ticket_id, array( 'coupon_id' => $coupon_id ) );

		ob_start();
		self::$camptix->manage_columns_coupon_action( 'tix_used', $coupon_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'tix_coupon_id=' . $coupon_id, $output );
		$this->assertStringNotContainsString( 's=', $output );
	}

	/**
	 * Verify the attendee coupon filter dropdown renders coupon names.
	 */
	public function test_attendee_coupon_filter_dropdown_renders_coupon_names() {
		$coupon_id = $this->create_coupon( array( 'code' => 'EARLYBIRD' ) );
		$_GET['tix_coupon_id'] = (string) $coupon_id;

		set_current_screen( 'edit-tix_attendee' );

		ob_start();
		self::$camptix->restrict_manage_attendees_by_coupon();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="tix_coupon_id"', $output );
		$this->assertMatchesRegularExpression(
			'/<option[^>]+value="' . preg_quote( (string) $coupon_id, '/' ) . '"[^>]+selected=[\'"]selected[\'"]/',
			$output
		);
		$this->assertStringContainsString( 'EARLYBIRD', $output );
	}

	/**
	 * Verify the attendee coupon filter adds a coupon meta query.
	 */
	public function test_attendee_coupon_filter_adds_meta_query() {
		$coupon_id = $this->create_coupon( array( 'code' => 'EARLYBIRD' ) );
		$_GET['tix_coupon_id'] = (string) $coupon_id;

		$query = new WP_Query();
		$query->set( 'post_type', 'tix_attendee' );

		set_current_screen( 'edit-tix_attendee' );

		$previous_wp_the_query   = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;
		$GLOBALS['wp_the_query'] = $query;
		self::$camptix->pre_get_posts( $query );
		$GLOBALS['wp_the_query'] = $previous_wp_the_query;

		$meta_query = $query->get( 'meta_query' );
		$this->assertSame( 'tix_coupon_id', $meta_query[0]['key'] );
		$this->assertSame( $coupon_id, $meta_query[0]['value'] );
		$this->assertSame( '=', $meta_query[0]['compare'] );
	}

	/**
	 * Verify malformed coupon filter values are ignored.
	 */
	public function test_attendee_coupon_filter_ignores_malformed_value() {
		$_GET['tix_coupon_id'] = array( 'not-a-coupon' );

		$query = new WP_Query();
		$query->set( 'post_type', 'tix_attendee' );

		set_current_screen( 'edit-tix_attendee' );

		$previous_wp_the_query   = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;
		$GLOBALS['wp_the_query'] = $query;
		self::$camptix->pre_get_posts( $query );
		$GLOBALS['wp_the_query'] = $previous_wp_the_query;

		$this->assertEmpty( $query->get( 'meta_query' ) );
	}

	/**
	 * Verify old coupon search args are rewritten to the coupon filter.
	 */
	public function test_old_coupon_search_query_args_are_rewritten_to_coupon_filter() {
		$coupon_id = $this->create_coupon( array( 'code' => 'EARLYBIRD' ) );

		$_GET['post_type']     = 'tix_attendee';
		$_GET['s']             = 'tix_coupon_id:' . $coupon_id;
		$_REQUEST['post_type'] = $_GET['post_type'];
		$_REQUEST['s']         = $_GET['s'];

		self::$camptix->rewrite_old_attendee_coupon_search_query_args();

		$this->assertSame( (string) $coupon_id, $_GET['tix_coupon_id'] );
		$this->assertSame( (string) $coupon_id, $_REQUEST['tix_coupon_id'] );
		$this->assertArrayNotHasKey( 's', $_GET );
		$this->assertArrayNotHasKey( 's', $_REQUEST );
	}

	/**
	 * Verify malformed old coupon search values are not rewritten.
	 */
	public function test_old_coupon_search_query_args_ignore_malformed_search() {
		$_GET['post_type']     = 'tix_attendee';
		$_GET['s']             = array( 'tix_coupon_id:123' );
		$_REQUEST['post_type'] = $_GET['post_type'];
		$_REQUEST['s']         = $_GET['s'];

		self::$camptix->rewrite_old_attendee_coupon_search_query_args();

		$this->assertArrayNotHasKey( 'tix_coupon_id', $_GET );
		$this->assertArrayNotHasKey( 'tix_coupon_id', $_REQUEST );
		$this->assertSame( array( 'tix_coupon_id:123' ), $_GET['s'] );
		$this->assertSame( array( 'tix_coupon_id:123' ), $_REQUEST['s'] );
	}

	/**
	 * Verify a valid coupon with remaining quantity is accepted.
	 */
	public function test_coupon_valid_for_use_with_valid_coupon() {
		$coupon_id = $this->create_coupon( array(
			'quantity'       => 10,
			'discount_price' => 5.00,
		) );

		$this->assertTrue( self::$camptix->is_coupon_valid_for_use( $coupon_id ) );
	}

	/**
	 * Verify a draft coupon is rejected.
	 */
	public function test_coupon_invalid_when_draft() {
		$coupon_id = $this->create_coupon( array( 'discount_price' => 5.00 ) );
		wp_update_post( array(
			'ID'          => $coupon_id,
			'post_status' => 'draft',
		) );

		$this->assertFalse( self::$camptix->is_coupon_valid_for_use( $coupon_id ) );
	}

	/**
	 * Verify a coupon is rejected when all uses are exhausted.
	 */
	public function test_coupon_invalid_when_all_used() {
		$ticket_id = $this->create_ticket();
		$coupon_id = $this->create_coupon( array(
			'quantity'       => 1,
			'discount_price' => 5.00,
		) );

		// Create one attendee with this coupon -- exhausts the supply.
		$this->create_attendee( $ticket_id, array( 'coupon_id' => $coupon_id ) );

		$this->assertFalse( self::$camptix->is_coupon_valid_for_use( $coupon_id ) );
	}

	/**
	 * Verify a coupon is rejected before its start date.
	 */
	public function test_coupon_invalid_before_start_date() {
		$coupon_id = $this->create_coupon( array(
			'discount_price' => 5.00,
			'start'          => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
		) );

		$this->assertFalse( self::$camptix->is_coupon_valid_for_use( $coupon_id ) );
	}

	/**
	 * Verify a coupon is rejected after its end date.
	 */
	public function test_coupon_invalid_after_end_date() {
		$coupon_id = $this->create_coupon( array(
			'discount_price' => 5.00,
			'end'            => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
		) );

		$this->assertFalse( self::$camptix->is_coupon_valid_for_use( $coupon_id ) );
	}

	/**
	 * Verify a coupon is valid on its end date due to the +1 day grace period.
	 */
	public function test_coupon_valid_on_end_date() {
		$coupon_id = $this->create_coupon( array(
			'discount_price' => 5.00,
			'end'            => gmdate( 'Y-m-d' ),
		) );

		$this->assertTrue( self::$camptix->is_coupon_valid_for_use( $coupon_id ) );
	}

	/**
	 * Verify remaining coupons calculation after some are used.
	 */
	public function test_remaining_coupons_calculation() {
		$ticket_id = $this->create_ticket();
		$coupon_id = $this->create_coupon( array(
			'quantity'       => 5,
			'discount_price' => 5.00,
		) );

		$this->create_attendee( $ticket_id, array( 'coupon_id' => $coupon_id ) );
		$this->create_attendee( $ticket_id, array( 'coupon_id' => $coupon_id ) );

		$this->assertSame( 3, self::$camptix->get_remaining_coupons( $coupon_id ) );
	}

	/**
	 * Verify used coupons count includes draft attendees (a checkout in progress)
	 * and excludes ones whose checkout ended without a sale.
	 */
	public function test_used_coupons_count() {
		$ticket_id = $this->create_ticket();
		$coupon_id = $this->create_coupon( array(
			'quantity'       => 10,
			'discount_price' => 5.00,
		) );

		foreach ( array( 'publish', 'pending', 'draft', 'cancel', 'failed', 'timeout', 'refund' ) as $status ) {
			$this->create_attendee(
				$ticket_id,
				array(
					'coupon_id' => $coupon_id,
					'status'    => $status,
				)
			);
		}

		$this->assertSame( 3, self::$camptix->get_used_coupons_count( $coupon_id ) );
	}

	/**
	 * An order re-checking its own availability (the gateway's final verify_order()
	 * before charging) must not count its own in-progress drafts against it, or buying
	 * the last remaining seat would fail.
	 */
	public function test_remaining_tickets_excludes_the_orders_own_drafts() {
		$ticket_id = $this->create_ticket( array( 'quantity' => 1 ) );
		$own       = $this->create_attendee(
			$ticket_id,
			array(
				'status'    => 'draft',
				'timestamp' => time(),
			)
		);

		// The seat is taken for everyone else.
		$this->assertSame( 0, self::$camptix->get_remaining_tickets( $ticket_id ) );

		// But the order that owns that draft still sees its seat as available to it.
		$this->assertSame( 1, self::$camptix->get_remaining_tickets( $ticket_id, false, array( $own ) ) );
	}

	/**
	 * The same exclusion applies to the coupon and reservation checks.
	 */
	public function test_coupon_and_reservation_exclude_the_orders_own_drafts() {
		add_filter( 'camptix_options', array( $this, 'enable_reservations' ) );
		self::$camptix->load_options();

		$ticket_id = $this->create_ticket( array( 'quantity' => 5 ) );
		add_post_meta(
			$ticket_id,
			'tix_reservation',
			array(
				'id'        => 'speakers',
				'token'     => 'speakertoken',
				'quantity'  => 1,
				'name'      => 'Speakers',
				'ticket_id' => $ticket_id,
			)
		);
		$coupon_id = $this->create_coupon(
			array(
				'quantity'     => 1,
				'discount_pct' => 100,
			)
		);

		$reservation_draft = $this->create_attendee(
			$ticket_id,
			array(
				'status'      => 'draft',
				'reservation' => 'speakertoken',
			)
		);
		$coupon_draft = $this->create_attendee(
			$ticket_id,
			array(
				'status'    => 'draft',
				'coupon_id' => $coupon_id,
			)
		);

		$this->assertFalse( self::$camptix->is_reservation_valid_for_use( 'speakertoken' ) );
		$this->assertTrue( self::$camptix->is_reservation_valid_for_use( 'speakertoken', array( $reservation_draft ) ) );

		$this->assertFalse( self::$camptix->is_coupon_valid_for_use( $coupon_id ) );
		$this->assertTrue( self::$camptix->is_coupon_valid_for_use( $coupon_id, array( $coupon_draft ) ) );
	}

	/**
	 * SQL statements seen by the checkout-lock tests, in order.
	 *
	 * @var string[]
	 */
	protected $seen_queries = array();

	/**
	 * Record each statement. The named locks run for real on the test connection and are
	 * released again, so nothing needs neutralising.
	 *
	 * @param string $query SQL about to run.
	 * @return string
	 */
	public function record_query( $query ) {
		$this->seen_queries[] = trim( $query );

		return $query;
	}

	/**
	 * Return the recorded statements that match a pattern.
	 *
	 * @param string $pattern Regex.
	 * @return string[]
	 */
	protected function seen( $pattern ) {
		return array_values( preg_grep( $pattern, $this->seen_queries ) );
	}

	/**
	 * Locking a checkout takes one named lock per ticket and coupon, in ID order (so two
	 * orders sharing rows cannot deadlock), and releasing lets them all go.
	 */
	public function test_lock_checkout_rows_locks_in_id_order_and_releases() {
		$ticket_a = $this->create_ticket();
		$ticket_b = $this->create_ticket();
		$coupon   = $this->create_coupon();
		$blog_id  = get_current_blog_id();

		add_filter( 'query', array( $this, 'record_query' ) );
		$locked = self::$camptix->lock_checkout_rows( array( $ticket_b, $coupon, $ticket_a, $ticket_a ) );
		self::$camptix->release_checkout_rows();
		self::$camptix->release_checkout_rows(); // A second release is a no-op.
		remove_filter( 'query', array( $this, 'record_query' ) );

		$this->assertTrue( $locked );

		$expected_ids = array( $ticket_a, $ticket_b, $coupon );
		sort( $expected_ids );
		$expected_names = array();
		foreach ( $expected_ids as $id ) {
			$expected_names[] = "camptix_checkout_{$blog_id}_{$id}";
		}

		$locks = $this->seen( '/GET_LOCK/' );
		$this->assertCount( 3, $locks );
		foreach ( $expected_names as $i => $name ) {
			$this->assertStringContainsString( "'{$name}'", $locks[ $i ] );
		}

		$this->assertCount( 3, $this->seen( '/RELEASE_LOCK/' ) );
	}

	/**
	 * Nothing to lock means no lock statements at all.
	 */
	public function test_lock_checkout_rows_with_no_ids_does_nothing() {
		add_filter( 'query', array( $this, 'record_query' ) );
		$locked = self::$camptix->lock_checkout_rows( array( 0, '', null ) );
		self::$camptix->release_checkout_rows();
		remove_filter( 'query', array( $this, 'record_query' ) );

		$this->assertFalse( $locked );
		$this->assertCount( 0, $this->seen( '/GET_LOCK|RELEASE_LOCK/' ) );
	}

	/**
	 * Under the checkout lock the count query must run every call, not be served from the
	 * query cache: the order is counted before checkout and again under the lock, and the
	 * second read has to be live. Outside the lock (ticket form, admin columns) the cached
	 * count is fine. Asserting values would pass either way, because wp_insert_post()
	 * invalidates the posts cache; assert how often the SQL runs instead.
	 */
	public function test_purchased_count_query_is_live_only_under_the_lock() {
		$ticket_id = $this->create_ticket( array( 'quantity' => 5 ) );

		add_filter( 'query', array( $this, 'record_query' ) );

		self::$camptix->get_purchased_tickets_count( $ticket_id );
		self::$camptix->get_purchased_tickets_count( $ticket_id );
		$outside = count( $this->seen( '/FROM.+posts.+tix_ticket_id/is' ) );

		self::$camptix->lock_checkout_rows( array( $ticket_id ) );
		self::$camptix->get_purchased_tickets_count( $ticket_id );
		self::$camptix->get_purchased_tickets_count( $ticket_id );
		self::$camptix->release_checkout_rows();
		$inside = count( $this->seen( '/FROM.+posts.+tix_ticket_id/is' ) ) - $outside;

		remove_filter( 'query', array( $this, 'record_query' ) );

		$this->assertSame( 1, $outside, 'outside the lock the second call is served from the query cache' );
		$this->assertSame( 2, $inside, 'under the lock every call hits the database' );
	}

	/**
	 * The same, for the coupon count.
	 */
	public function test_used_coupons_count_query_is_live_only_under_the_lock() {
		$coupon_id = $this->create_coupon();

		add_filter( 'query', array( $this, 'record_query' ) );

		self::$camptix->get_used_coupons_count( $coupon_id );
		self::$camptix->get_used_coupons_count( $coupon_id );
		$outside = count( $this->seen( '/FROM.+posts.+tix_coupon_id/is' ) );

		self::$camptix->lock_checkout_rows( array( $coupon_id ) );
		self::$camptix->get_used_coupons_count( $coupon_id );
		self::$camptix->get_used_coupons_count( $coupon_id );
		self::$camptix->release_checkout_rows();
		$inside = count( $this->seen( '/FROM.+posts.+tix_coupon_id/is' ) ) - $outside;

		remove_filter( 'query', array( $this, 'record_query' ) );

		$this->assertSame( 1, $outside );
		$this->assertSame( 2, $inside );
	}

	/**
	 * Fail the Nth wp_insert_post() call, to test a draft that cannot be written.
	 *
	 * @var int
	 */
	protected $fail_insert_at = 0;

	/**
	 * New-post inserts seen by fail_nth_insert() in the current test.
	 *
	 * @var int
	 */
	protected $inserts_seen = 0;

	/**
	 * Callback for wp_insert_post_empty_content: fail the Nth new-post insert. Counts only
	 * inserts (empty ID), because wp_update_post() runs the same filter for existing posts.
	 *
	 * @param bool  $maybe_empty Whether the post is considered empty.
	 * @param array $postarr     Post data.
	 * @return bool
	 */
	public function fail_nth_insert( $maybe_empty, $postarr ) {
		if ( ! empty( $postarr['ID'] ) ) {
			return $maybe_empty;
		}

		$this->inserts_seen++;

		return $this->inserts_seen === $this->fail_insert_at ? true : $maybe_empty;
	}

	/**
	 * A draft that cannot be written aborts the order and removes the drafts already written,
	 * so a partial order never holds seats and the buyer is not sent to the gateway with no
	 * attendee rows behind the order.
	 */
	public function test_insert_attendee_drafts_aborts_and_cleans_up_when_a_draft_cannot_be_written() {
		$ticket_id = $this->create_ticket();

		$attendees = array();
		foreach ( array( 'One', 'Two' ) as $name ) {
			$attendee             = new stdClass();
			$attendee->ticket_id  = $ticket_id;
			$attendee->first_name = $name;
			$attendee->last_name  = 'Row';
			$attendee->email      = strtolower( $name ) . '@example.test';
			$attendee->answers    = array();
			$attendees[]          = $attendee;
		}

		// The first attendee's draft is written normally; the second insert fails.
		$this->fail_insert_at = 2;
		add_filter( 'wp_insert_post_empty_content', array( $this, 'fail_nth_insert' ), 10, 2 );
		$result = self::$camptix->insert_attendee_drafts( $attendees, 'stripe', 'r@example.test', 'acc', 'pay' );
		remove_filter( 'wp_insert_post_empty_content', array( $this, 'fail_nth_insert' ), 10 );

		$this->assertFalse( $result );
		$this->assertSame( 0, self::$camptix->get_purchased_tickets_count( $ticket_id ), 'the first draft was removed again' );
	}

	/**
	 * Log a fresh user in and return their login, for the abandoned-draft tests.
	 */
	protected function log_in_a_buyer() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		return get_userdata( $user_id )->user_login;
	}

	/**
	 * Create one of the buyer's own draft attendees.
	 *
	 * @param int      $ticket_id  Ticket.
	 * @param string   $login      Buyer's login (tix_username on the buyer row).
	 * @param string   $token      Payment token of the order.
	 * @param int      $age        Seconds since checkout.
	 * @param int|null $expires_in Seconds until the gateway session dies (negative = already dead); null = none recorded.
	 * @param array    $extra      More create_attendee() args.
	 * @return int
	 */
	protected function create_own_draft( $ticket_id, $login, $token, $age, $expires_in = null, $extra = array() ) {
		$id = $this->create_attendee(
			$ticket_id,
			array_merge(
				array(
					'status'        => 'draft',
					'username'      => $login,
					'payment_token' => $token,
					'timestamp'     => time() - $age,
				),
				$extra
			)
		);

		if ( null !== $expires_in ) {
			update_post_meta( $id, 'tix_session_expires_at', time() + $expires_in );
		}

		return $id;
	}

	/**
	 * A draft releases its seat a grace after its recorded session expiry, or 24 hours after
	 * checkout when no expiry was recorded (drafts from before this, gateways that can't say).
	 */
	public function test_draft_release_at_uses_the_recorded_session_expiry_else_24_hours() {
		$ticket_id = $this->create_ticket();
		$grace     = CampTix_Plugin::DRAFT_LIFETIME_GRACE;
		$now       = time();

		$with_expiry = $this->create_own_draft( $ticket_id, 'a', 'tok_a', 10 * MINUTE_IN_SECONDS, 20 * MINUTE_IN_SECONDS );
		$without     = $this->create_own_draft( $ticket_id, 'b', 'tok_b', 10 * MINUTE_IN_SECONDS, null );

		$this->assertEqualsWithDelta( $now + 20 * MINUTE_IN_SECONDS + $grace, self::$camptix->get_draft_release_at( $with_expiry ), 2 );
		$this->assertEqualsWithDelta( $now - 10 * MINUTE_IN_SECONDS + DAY_IN_SECONDS + $grace, self::$camptix->get_draft_release_at( $without ), 2 );
	}

	/**
	 * Recording an order's session expiry stamps every attendee on the payment token.
	 */
	public function test_set_order_session_expiry_stamps_the_whole_order() {
		$ticket_id = $this->create_ticket();
		$first     = $this->create_own_draft( $ticket_id, 'a', 'tok_order', 0 );
		$second    = $this->create_own_draft( $ticket_id, 'unconfirmed', 'tok_order', 0 );
		$other     = $this->create_own_draft( $ticket_id, 'c', 'tok_other', 0 );

		self::$camptix->set_order_session_expiry( 'tok_order', 1800000000 );

		$this->assertSame( '1800000000', get_post_meta( $first, 'tix_session_expires_at', true ) );
		$this->assertSame( '1800000000', get_post_meta( $second, 'tix_session_expires_at', true ) );
		$this->assertSame( '', get_post_meta( $other, 'tix_session_expires_at', true ) );
	}

	/**
	 * The Stripe addon's session lifetime is 30 minutes, filterable within Stripe's range.
	 */
	public function test_stripe_session_lifetime_is_filterable_within_stripes_range() {
		$stripe = self::$camptix->get_payment_method_by_id( 'stripe' );

		$this->assertSame( 30 * MINUTE_IN_SECONDS, $stripe->get_checkout_session_lifetime() );

		add_filter( 'camptix_stripe_checkout_session_lifetime', array( $this, 'one_hour' ) );
		$this->assertSame( HOUR_IN_SECONDS, $stripe->get_checkout_session_lifetime() );
		remove_filter( 'camptix_stripe_checkout_session_lifetime', array( $this, 'one_hour' ) );

		add_filter( 'camptix_stripe_checkout_session_lifetime', '__return_zero' );
		$this->assertSame( 30 * MINUTE_IN_SECONDS, $stripe->get_checkout_session_lifetime(), 'clamped up to 30 minutes' );
		remove_filter( 'camptix_stripe_checkout_session_lifetime', '__return_zero' );
	}

	/**
	 * Filter callback: one hour.
	 *
	 * @return int
	 */
	public function one_hour() {
		return HOUR_IN_SECONDS;
	}

	/**
	 * An expired gateway session times a draft out at once, and nothing else.
	 */
	public function test_payment_result_timeout_moves_a_draft_to_timeout_and_nothing_else() {
		$ticket_id = $this->create_ticket();
		$draft     = $this->create_own_draft( $ticket_id, 'buyer', 'tok_draft', 0, 30 * MINUTE_IN_SECONDS );
		$paid      = $this->create_attendee(
			$ticket_id,
			array(
				'status'        => 'publish',
				'payment_token' => 'tok_paid',
			)
		);

		self::$camptix->payment_result( 'tok_draft', CampTix_Plugin::PAYMENT_STATUS_TIMEOUT, array(), false );
		self::$camptix->payment_result( 'tok_paid', CampTix_Plugin::PAYMENT_STATUS_TIMEOUT, array(), false );

		$this->assertSame( 'timeout', get_post_status( $draft ) );
		$this->assertSame( 'publish', get_post_status( $paid ) );
	}

	/**
	 * The sweep times a draft out once its recorded session expiry plus the grace has passed,
	 * not before; a draft with no recorded expiry keeps the old 24 hours.
	 */
	public function test_timeout_sweep_uses_each_drafts_recorded_expiry() {
		$ticket_id     = $this->create_ticket();
		$dead          = $this->create_own_draft( $ticket_id, 'a', 'tok_1', 40 * MINUTE_IN_SECONDS, -10 * MINUTE_IN_SECONDS );
		$in_grace      = $this->create_own_draft( $ticket_id, 'b', 'tok_2', 40 * MINUTE_IN_SECONDS, -2 * MINUTE_IN_SECONDS );
		$live          = $this->create_own_draft( $ticket_id, 'c', 'tok_3', 40 * MINUTE_IN_SECONDS, 20 * MINUTE_IN_SECONDS );
		$no_expiry_mid = $this->create_own_draft( $ticket_id, 'd', 'tok_4', 4 * HOUR_IN_SECONDS, null );
		$no_expiry_old = $this->create_own_draft( $ticket_id, 'e', 'tok_5', 25 * HOUR_IN_SECONDS, null );

		self::$camptix->review_timeout_payments();

		$this->assertSame( 'timeout', get_post_status( $dead ) );
		$this->assertSame( 'draft', get_post_status( $in_grace ), 'still within the grace after expiry' );
		$this->assertSame( 'draft', get_post_status( $live ) );
		$this->assertSame( 'draft', get_post_status( $no_expiry_mid ), 'no recorded expiry: 24 hours as before' );
		$this->assertSame( 'timeout', get_post_status( $no_expiry_old ) );
	}

	/**
	 * A buyer's own draft order counts as abandoned only once its recorded session expiry
	 * plus the grace has passed. The whole order is included, a sibling's is not.
	 */
	public function test_abandoned_drafts_are_own_orders_past_their_recorded_expiry() {
		$me        = $this->log_in_a_buyer();
		$ticket_id = $this->create_ticket();

		$dead_buyer   = $this->create_own_draft( $ticket_id, $me, 'tok_dead', 40 * MINUTE_IN_SECONDS, -10 * MINUTE_IN_SECONDS );
		$dead_sibling = $this->create_own_draft( $ticket_id, 'unconfirmed', 'tok_dead', 40 * MINUTE_IN_SECONDS, -10 * MINUTE_IN_SECONDS );
		$live         = $this->create_own_draft( $ticket_id, $me, 'tok_live', 10 * MINUTE_IN_SECONDS, 20 * MINUTE_IN_SECONDS );
		$no_expiry    = $this->create_own_draft( $ticket_id, $me, 'tok_old', 40 * MINUTE_IN_SECONDS, null );
		$this->create_own_draft( $ticket_id, 'someone_else', 'tok_theirs', 40 * MINUTE_IN_SECONDS, -10 * MINUTE_IN_SECONDS );

		$abandoned = self::$camptix->get_abandoned_draft_attendee_ids();
		sort( $abandoned );
		$expected = array( $dead_buyer, $dead_sibling );
		sort( $expected );
		$this->assertSame( $expected, $abandoned );

		$this->assertSame( 'draft', get_post_status( $live ) );
		$this->assertSame( 'draft', get_post_status( $no_expiry ) );
	}

	/**
	 * The form's memoised set is not reused under the checkout lock: a draft that got
	 * published in between must count there, so the lock looks again, live.
	 */
	public function test_abandoned_drafts_are_looked_up_again_under_the_checkout_lock() {
		$me        = $this->log_in_a_buyer();
		$ticket_id = $this->create_ticket();
		$dead      = $this->create_own_draft( $ticket_id, $me, 'tok_dead', 40 * MINUTE_IN_SECONDS, -10 * MINUTE_IN_SECONDS );

		$this->assertSame( array( $dead ), self::$camptix->get_abandoned_draft_attendee_ids() );

		wp_update_post(
			array(
				'ID'          => $dead,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( array( $dead ), self::$camptix->get_abandoned_draft_attendee_ids(), 'memoised for the form' );

		self::$camptix->lock_checkout_rows( array( $ticket_id ) );
		$this->assertSame( array(), self::$camptix->get_abandoned_draft_attendee_ids(), 'live under the lock' );
		self::$camptix->release_checkout_rows();
	}

	/**
	 * A gateway's timeout backstop can find the session still open and push the recorded
	 * expiry out; the sweep then leaves the draft alone.
	 */
	public function test_timeout_sweep_keeps_a_draft_whose_backstop_extended_its_session() {
		$ticket_id = $this->create_ticket();
		$draft     = $this->create_own_draft( $ticket_id, 'a', 'tok_1', 40 * MINUTE_IN_SECONDS, -10 * MINUTE_IN_SECONDS );
		$extend    = function ( $attendee_id ) {
			update_post_meta( $attendee_id, 'tix_session_expires_at', time() + 20 * MINUTE_IN_SECONDS );
		};

		add_action( 'camptix_pre_attendee_timeout', $extend );
		self::$camptix->review_timeout_payments();
		remove_action( 'camptix_pre_attendee_timeout', $extend );

		$this->assertSame( 'draft', get_post_status( $draft ) );
	}

	/**
	 * Logged out, nothing is the buyer's own.
	 */
	public function test_abandoned_drafts_is_empty_when_logged_out() {
		$ticket_id = $this->create_ticket();
		$this->create_own_draft( $ticket_id, 'anyone', 'tok', DAY_IN_SECONDS, -HOUR_IN_SECONDS );

		$this->assertSame( array(), self::$camptix->get_abandoned_draft_attendee_ids() );
	}

	/**
	 * An abandoned draft no longer locks the same buyer out of a single-use coupon or a
	 * quantity-1 reservation, while still counting against everyone else.
	 */
	public function test_abandoned_draft_does_not_lock_the_buyer_out_of_their_coupon_or_reservation() {
		$me        = $this->log_in_a_buyer();
		$ticket_id = $this->create_ticket( array( 'quantity' => 5 ) );
		$this->create_reservation( $ticket_id );
		$coupon_id = $this->create_coupon(
			array(
				'quantity'     => 1,
				'discount_pct' => 100,
			)
		);

		$this->create_own_draft(
			$ticket_id,
			$me,
			'tok_abandoned',
			40 * MINUTE_IN_SECONDS,
			-10 * MINUTE_IN_SECONDS,
			array(
				'coupon_id'   => $coupon_id,
				'reservation' => 'speakertoken',
			)
		);

		$this->assertFalse( self::$camptix->is_coupon_valid_for_use( $coupon_id ) );
		$this->assertFalse( self::$camptix->is_reservation_valid_for_use( 'speakertoken' ) );

		$abandoned = self::$camptix->get_abandoned_draft_attendee_ids();
		$this->assertTrue( self::$camptix->is_coupon_valid_for_use( $coupon_id, $abandoned ) );
		$this->assertTrue( self::$camptix->is_reservation_valid_for_use( 'speakertoken', $abandoned ) );
	}

	/**
	 * A checkout in progress against a single-use coupon uses it up, and a coupon
	 * with redemptions to spare stays valid.
	 */
	public function test_coupon_valid_for_use_counts_draft_attendees() {
		$ticket_id  = $this->create_ticket();
		$single_use = $this->create_coupon(
			array(
				'code'         => 'ONCE',
				'quantity'     => 1,
				'discount_pct' => 100,
			)
		);
		$two_uses   = $this->create_coupon(
			array(
				'code'         => 'TWICE',
				'quantity'     => 2,
				'discount_pct' => 100,
			)
		);

		$this->assertTrue( self::$camptix->is_coupon_valid_for_use( $single_use ) );
		$this->assertTrue( self::$camptix->is_coupon_valid_for_use( $two_uses ) );

		foreach ( array( $single_use, $two_uses ) as $coupon_id ) {
			$this->create_attendee(
				$ticket_id,
				array(
					'coupon_id' => $coupon_id,
					'status'    => 'draft',
				)
			);
		}

		$this->assertFalse( self::$camptix->is_coupon_valid_for_use( $single_use ) );
		$this->assertTrue( self::$camptix->is_coupon_valid_for_use( $two_uses ) );
	}

	/**
	 * Verify have_coupons returns true when valid coupons exist.
	 */
	public function test_have_coupons_returns_true_when_valid_coupon_exists() {
		$this->create_coupon( array(
			'quantity'       => 5,
			'discount_price' => 5.00,
		) );

		$this->assertTrue( self::$camptix->have_coupons() );
	}

	/**
	 * Verify have_coupons returns false when no coupons exist.
	 */
	public function test_have_coupons_returns_false_when_no_coupons() {
		$this->assertFalse( self::$camptix->have_coupons() );
	}

	/**
	 * Verify update_stats sets a single stat key.
	 */
	public function test_update_stats_with_single_key() {
		self::$camptix->update_stats( 'test_sold', 42 );
		$this->assertSame( 42, self::$camptix->get_stats( 'test_sold' ) );
	}

	/**
	 * Verify update_stats sets multiple stat keys from an array.
	 */
	public function test_update_stats_with_array() {
		self::$camptix->update_stats( array(
			'test_sold'      => 10,
			'test_remaining' => 90,
		) );

		$this->assertSame( 10, self::$camptix->get_stats( 'test_sold' ) );
		$this->assertSame( 90, self::$camptix->get_stats( 'test_remaining' ) );
	}

	/**
	 * Verify get_stats returns zero for a missing key.
	 */
	public function test_get_stats_returns_zero_for_missing_key() {
		$this->assertSame( 0, self::$camptix->get_stats( 'nonexistent_key_xyz' ) );
	}

	/**
	 * Verify increment_stats adds to an existing stat value.
	 */
	public function test_increment_stats() {
		self::$camptix->update_stats( 'test_inc', 5 );
		$result = self::$camptix->increment_stats( 'test_inc', 3 );

		$this->assertSame( 8, $result );
		$this->assertSame( 8, self::$camptix->get_stats( 'test_inc' ) );
	}

	/**
	 * Verify increment_stats supports negative step values.
	 */
	public function test_increment_stats_with_negative_step() {
		self::$camptix->update_stats( 'test_dec', 10 );
		$result = self::$camptix->increment_stats( 'test_dec', -3 );

		$this->assertSame( 7, $result );
	}

	/**
	 * Verify increment_stats initialises a missing key to the step value.
	 */
	public function test_increment_stats_initialises_missing_key() {
		$result = self::$camptix->increment_stats( 'test_new_key_' . wp_rand(), 1 );
		$this->assertSame( 1, $result );
	}

	/**
	 * Verify publishing an attendee increments sold count and revenue.
	 */
	public function test_transition_publish_increments_sold() {
		$ticket_id   = $this->create_ticket( array( 'price' => 20.00 ) );
		$attendee_id = $this->create_attendee(
			$ticket_id,
			array(
				'status'           => 'draft',
				'ticket_price'     => 20.00,
				'discounted_price' => 20.00,
			)
		);

		self::$camptix->update_stats( 'sold', 0 );
		self::$camptix->update_stats( 'revenue', 0 );

		$post = get_post( $attendee_id );
		self::$camptix->transition_post_status( 'publish', 'draft', $post );

		$this->assertSame( 1, self::$camptix->get_stats( 'sold' ) );
		$this->assertSame( 20.0, (float) self::$camptix->get_stats( 'revenue' ) );
	}

	/**
	 * Verify unpublishing an attendee decrements sold count and revenue.
	 */
	public function test_transition_unpublish_decrements_sold() {
		$ticket_id   = $this->create_ticket( array( 'price' => 15.00 ) );
		$attendee_id = $this->create_attendee(
			$ticket_id,
			array(
				'ticket_price'     => 15.00,
				'discounted_price' => 15.00,
			)
		);

		self::$camptix->update_stats( 'sold', 5 );
		self::$camptix->update_stats( 'revenue', 75.0 );

		$post = get_post( $attendee_id );
		self::$camptix->transition_post_status( 'draft', 'publish', $post );

		$this->assertSame( 4, self::$camptix->get_stats( 'sold' ) );
		$this->assertSame( 60.0, (float) self::$camptix->get_stats( 'revenue' ) );
	}

	/**
	 * Verify transitioning to the same status does not change stats.
	 */
	public function test_transition_same_status_is_noop() {
		$ticket_id   = $this->create_ticket();
		$attendee_id = $this->create_attendee( $ticket_id );

		// Set stats AFTER creating attendee (which triggers its own transition).
		self::$camptix->update_stats( 'sold', 5 );

		$post = get_post( $attendee_id );
		self::$camptix->transition_post_status( 'publish', 'publish', $post );
		$this->assertSame( 5, self::$camptix->get_stats( 'sold' ) );
	}

	/**
	 * Verify pending-to-publish transition does not change stats.
	 */
	public function test_transition_pending_to_publish_is_noop() {
		// Both are "active" statuses, no stats change expected.
		$ticket_id   = $this->create_ticket();
		$attendee_id = $this->create_attendee( $ticket_id, array( 'status' => 'pending' ) );

		// Set stats AFTER creating attendee.
		self::$camptix->update_stats( 'sold', 3 );

		$post = get_post( $attendee_id );
		self::$camptix->transition_post_status( 'publish', 'pending', $post );

		$this->assertSame( 3, self::$camptix->get_stats( 'sold' ) );
	}

	/**
	 * Verify transition ignores non-attendee post types.
	 */
	public function test_transition_ignores_non_attendee_posts() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		self::$camptix->update_stats( 'sold', 5 );

		$post = get_post( $page_id );
		self::$camptix->transition_post_status( 'publish', 'draft', $post );

		$this->assertSame( 5, self::$camptix->get_stats( 'sold' ) );
	}

	/**
	 * Verify transition tracks discount amounts correctly.
	 */
	public function test_transition_tracks_discount() {
		$ticket_id   = $this->create_ticket( array( 'price' => 50.00 ) );
		$attendee_id = $this->create_attendee(
			$ticket_id,
			array(
				'status'           => 'draft',
				'ticket_price'     => 50.00,
				'discounted_price' => 35.00,
			)
		);

		self::$camptix->update_stats( 'subtotal', 0 );
		self::$camptix->update_stats( 'discounted', 0 );
		self::$camptix->update_stats( 'revenue', 0 );

		$post = get_post( $attendee_id );
		self::$camptix->transition_post_status( 'publish', 'draft', $post );

		$this->assertSame( 50.0, (float) self::$camptix->get_stats( 'subtotal' ) );
		$this->assertSame( 15.0, (float) self::$camptix->get_stats( 'discounted' ) );
		$this->assertSame( 35.0, (float) self::$camptix->get_stats( 'revenue' ) );
	}

	/**
	 * Verify remaining stat does not go below zero.
	 */
	public function test_transition_remaining_does_not_go_below_zero() {
		$ticket_id   = $this->create_ticket();
		$attendee_id = $this->create_attendee( $ticket_id, array( 'status' => 'draft' ) );

		self::$camptix->update_stats( 'remaining', 0 );

		$post = get_post( $attendee_id );
		self::$camptix->transition_post_status( 'publish', 'draft', $post );

		$this->assertSame( 0, self::$camptix->get_stats( 'remaining' ) );
	}

	/**
	 * Verify validate_options sanitises HTML from event name.
	 */
	public function test_validate_options_sanitises_event_name() {
		$result = self::$camptix->validate_options( array(
			'event_name' => '<b>WordCamp</b> Test',
		) );

		$this->assertStringNotContainsString( '<b>', $result['event_name'] );
		$this->assertStringContainsString( 'WordCamp', $result['event_name'] );
	}

	/**
	 * Verify validate_options rejects an invalid currency code.
	 */
	public function test_validate_options_rejects_invalid_currency() {
		$result = self::$camptix->validate_options( array(
			'currency' => 'INVALID',
		) );

		// Should remain the default (USD), not accept the invalid value.
		$this->assertNotSame( 'INVALID', $result['currency'] );
	}

	/**
	 * Verify refunds date is not saved when refunds are disabled.
	 */
	public function test_validate_options_refunds_date_requires_enabled() {
		$result = self::$camptix->validate_options( array(
			'refunds_enabled'  => false,
			'refunds_date_end' => '2026-12-31',
		) );

		$this->assertNotSame( '2026-12-31', $result['refunds_date_end'] ?? '' );
	}

	/**
	 * Verify refunds date is saved when refunds are enabled.
	 */
	public function test_validate_options_refunds_date_saved_when_enabled() {
		$result = self::$camptix->validate_options( array(
			'refunds_enabled'  => true,
			'refunds_date_end' => '2026-12-31',
		) );

		$this->assertSame( '2026-12-31', $result['refunds_date_end'] );
	}

	/**
	 * Verify refunds_enabled is cast to boolean.
	 */
	public function test_validate_options_refunds_enabled_as_bool() {
		$result = self::$camptix->validate_options( array(
			'refunds_enabled' => 1,
		) );

		$this->assertTrue( $result['refunds_enabled'] );
	}

	/**
	 * Verify revenue report with no tickets returns zero totals.
	 */
	public function test_revenue_report_with_no_tickets() {
		if ( ! class_exists( 'NumberFormatter' ) ) {
			$this->markTestSkipped( 'intl extension required for currency formatting.' );
		}

		$results = self::$camptix->generate_revenue_report_data();

		$this->assertSame( 0, $results['totals']->sold );
		$this->assertSame( 0, $results['totals']->remaining );
		$this->assertEquals( 0, $results['totals']->revenue );
	}

	/**
	 * Verify revenue report counts sold and remaining tickets.
	 */
	public function test_revenue_report_counts_sold_and_remaining() {
		if ( ! class_exists( 'NumberFormatter' ) ) {
			$this->markTestSkipped( 'intl extension required for currency formatting.' );
		}

		$ticket_id = $this->create_ticket( array(
			'price'    => 30.00,
			'quantity' => 10,
		) );

		$this->create_attendee(
			$ticket_id,
			array(
				'ticket_price'     => 30.00,
				'discounted_price' => 30.00,
				'order_total'      => 30.00,
				'transaction_id'   => 'txn_001',
			)
		);
		$this->create_attendee(
			$ticket_id,
			array(
				'ticket_price'     => 30.00,
				'discounted_price' => 30.00,
				'order_total'      => 30.00,
				'transaction_id'   => 'txn_002',
			)
		);

		$results = self::$camptix->generate_revenue_report_data();

		$this->assertSame( 2, $results['totals']->sold );
		$this->assertSame( 8, $results['totals']->remaining );
		$this->assertEquals( 60.0, $results['totals']->sub_total );
		$this->assertEquals( 60.0, $results['totals']->revenue );
	}

	/**
	 * Verify revenue report applies fixed discount correctly.
	 */
	public function test_revenue_report_applies_fixed_discount() {
		if ( ! class_exists( 'NumberFormatter' ) ) {
			$this->markTestSkipped( 'intl extension required for currency formatting.' );
		}

		$ticket_id = $this->create_ticket( array(
			'price'    => 50.00,
			'quantity' => 10,
		) );
		$coupon_id = $this->create_coupon( array(
			'code'           => 'FLAT10',
			'discount_price' => 10.00,
			'quantity'       => 10,
		) );

		$this->create_attendee(
			$ticket_id,
			array(
				'ticket_price'     => 50.00,
				'discounted_price' => 40.00,
				'order_total'      => 40.00,
				'transaction_id'   => 'txn_d1',
				'coupon_id'        => $coupon_id,
			)
		);

		$results = self::$camptix->generate_revenue_report_data();

		$this->assertSame( 1, $results['totals']->sold );
		$this->assertEquals( 50.0, $results['totals']->sub_total );
		$this->assertEquals( 10.0, $results['totals']->discounted );
		$this->assertEquals( 40.0, $results['totals']->revenue );
	}

	/**
	 * Verify revenue report applies percentage discount correctly.
	 */
	public function test_revenue_report_applies_percentage_discount() {
		if ( ! class_exists( 'NumberFormatter' ) ) {
			$this->markTestSkipped( 'intl extension required for currency formatting.' );
		}

		$ticket_id = $this->create_ticket( array(
			'price'    => 100.00,
			'quantity' => 10,
		) );
		$coupon_id = $this->create_coupon( array(
			'code'         => 'HALF',
			'discount_pct' => 50,
			'quantity'     => 10,
		) );

		$this->create_attendee(
			$ticket_id,
			array(
				'ticket_price'     => 100.00,
				'discounted_price' => 50.00,
				'order_total'      => 50.00,
				'transaction_id'   => 'txn_p1',
				'coupon_id'        => $coupon_id,
			)
		);

		$results = self::$camptix->generate_revenue_report_data();

		$this->assertEquals( 100.0, $results['totals']->sub_total );
		$this->assertEquals( 50.0, $results['totals']->discounted );
		$this->assertEquals( 50.0, $results['totals']->revenue );
	}

	/**
	 * Verify revenue report caps discount at ticket price.
	 */
	public function test_revenue_report_caps_discount_at_ticket_price() {
		if ( ! class_exists( 'NumberFormatter' ) ) {
			$this->markTestSkipped( 'intl extension required for currency formatting.' );
		}

		$ticket_id = $this->create_ticket( array(
			'price'    => 20.00,
			'quantity' => 10,
		) );
		$coupon_id = $this->create_coupon( array(
			'code'           => 'BIGOFF',
			'discount_price' => 50.00,
			'quantity'       => 10,
		) );

		$this->create_attendee(
			$ticket_id,
			array(
				'ticket_price'     => 20.00,
				'discounted_price' => 0.00,
				'order_total'      => 0.00,
				'transaction_id'   => 'txn_cap',
				'coupon_id'        => $coupon_id,
			)
		);

		$results = self::$camptix->generate_revenue_report_data();

		// Discount should be capped at ticket price (20), not the coupon value (50).
		$this->assertEquals( 20.0, $results['totals']->discounted );
		$this->assertEquals( 0.0, $results['totals']->revenue );
	}

	/**
	 * Verify increment_summary creates a new summary entry.
	 */
	public function test_increment_summary_creates_new_entry() {
		$summary = array();
		self::$camptix->increment_summary( $summary, 'WordPress' );

		$key = 'tix_' . md5( 'WordPress' );
		$this->assertArrayHasKey( $key, $summary );
		$this->assertSame( 1, $summary[ $key ]['count'] );
		$this->assertSame( 'WordPress', $summary[ $key ]['label'] );
	}

	/**
	 * Verify increment_summary increments count for existing entries.
	 */
	public function test_increment_summary_increments_existing() {
		$summary = array();
		self::$camptix->increment_summary( $summary, 'WordPress' );
		self::$camptix->increment_summary( $summary, 'WordPress' );

		$key = 'tix_' . md5( 'WordPress' );
		$this->assertSame( 2, $summary[ $key ]['count'] );
	}

	/**
	 * Verify increment_summary joins array labels with commas.
	 */
	public function test_increment_summary_joins_array_labels() {
		$summary = array();
		self::$camptix->increment_summary( $summary, array( 'Option A', 'Option B' ) );

		$key = 'tix_' . md5( 'Option A, Option B' );
		$this->assertArrayHasKey( $key, $summary );
		$this->assertSame( 'Option A, Option B', $summary[ $key ]['label'] );
	}

	/**
	 * Verify save_coupon_post gives price priority over percent.
	 */
	public function test_save_coupon_price_priority_over_percent() {
		$coupon_id = $this->create_coupon( array( 'code' => 'PRIO' ) );
		$this->simulate_admin_save( $coupon_id );

		$_POST['tix_discount_price']   = '15.00';
		$_POST['tix_discount_percent'] = '50';

		self::$camptix->save_coupon_post( $coupon_id );

		// Price takes priority -- percent should be removed.
		$this->assertEquals( 15.0, (float) get_post_meta( $coupon_id, 'tix_discount_price', true ) );
		$this->assertEmpty( get_post_meta( $coupon_id, 'tix_discount_percent', true ) );

		$this->cleanup_post_data();
	}

	/**
	 * Verify save_coupon_post caps percent discount at 100.
	 */
	public function test_save_coupon_percent_capped_at_100() {
		$coupon_id = $this->create_coupon( array( 'code' => 'CAP' ) );
		$this->simulate_admin_save( $coupon_id );

		$_POST['tix_discount_price']   = '0';
		$_POST['tix_discount_percent'] = '150';

		self::$camptix->save_coupon_post( $coupon_id );

		$this->assertSame( 100, (int) get_post_meta( $coupon_id, 'tix_discount_percent', true ) );

		$this->cleanup_post_data();
	}

	/**
	 * Verify save_coupon_post validates date format and rejects invalid dates.
	 */
	public function test_save_coupon_date_validates_format() {
		$coupon_id = $this->create_coupon( array( 'code' => 'DATES' ) );
		$this->simulate_admin_save( $coupon_id );

		$_POST['tix_coupon_start'] = '2026-06-15';
		$_POST['tix_coupon_end']   = 'not-a-date';

		self::$camptix->save_coupon_post( $coupon_id );

		$this->assertSame( '2026-06-15', get_post_meta( $coupon_id, 'tix_coupon_start', true ) );
		$this->assertSame( '', get_post_meta( $coupon_id, 'tix_coupon_end', true ) );

		$this->cleanup_post_data();
	}

	/**
	 * Verify save_ticket_post validates date format and rejects invalid dates.
	 */
	public function test_save_ticket_date_validation() {
		$ticket_id = $this->create_ticket();
		$this->simulate_admin_save( $ticket_id );

		$_POST['tix_price'] = '25.00';
		$_POST['tix_start'] = '2026-01-15';
		$_POST['tix_end']   = 'invalid';

		self::$camptix->save_ticket_post( $ticket_id );

		$this->assertSame( '2026-01-15', get_post_meta( $ticket_id, 'tix_start', true ) );
		$this->assertSame( '', get_post_meta( $ticket_id, 'tix_end', true ) );

		$this->cleanup_post_data();
	}

	/**
	 * Verify save_ticket_post stores price as float and quantity as int.
	 */
	public function test_save_ticket_price_stored_as_float() {
		$ticket_id = $this->create_ticket();
		$this->simulate_admin_save( $ticket_id );

		$_POST['tix_price']    = '42.50';
		$_POST['tix_quantity'] = '200';

		self::$camptix->save_ticket_post( $ticket_id );

		$this->assertEquals( 42.5, (float) get_post_meta( $ticket_id, 'tix_price', true ) );
		$this->assertSame( 200, (int) get_post_meta( $ticket_id, 'tix_quantity', true ) );

		$this->cleanup_post_data();
	}

	/**
	 * Verify get_beta_features returns expected feature keys.
	 */
	public function test_get_beta_features_returns_expected_keys() {
		$features = self::$camptix->get_beta_features();

		$this->assertContains( 'reservations_enabled', $features );
		$this->assertContains( 'refund_all_enabled', $features );
		$this->assertContains( 'archived', $features );
	}

	/**
	 * Verify is_wordcamp_closed returns false when no WordCamp post exists.
	 */
	public function test_is_wordcamp_closed_returns_false_when_no_wordcamp_post() {
		// In test environment there is no wordcamp post by default.
		$this->assertFalse( self::$camptix->is_wordcamp_closed() );
	}
}
