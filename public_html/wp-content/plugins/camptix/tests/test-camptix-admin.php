<?php

defined( 'WPINC' ) || die();

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
		}
		if ( ! empty( $args['payment_method'] ) ) {
			update_post_meta( $post_id, 'tix_payment_method', $args['payment_method'] );
		}
		if ( ! empty( $args['reservation'] ) ) {
			update_post_meta( $post_id, 'tix_reservation_token', $args['reservation'] );
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
	 * Verify draft attendees do not count toward purchased tickets.
	 */
	public function test_remaining_tickets_ignores_draft_attendees() {
		$ticket_id = $this->create_ticket( array( 'quantity' => 10 ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'draft' ) );

		$this->assertSame( 10, self::$camptix->get_remaining_tickets( $ticket_id ) );
	}

	/**
	 * Verify purchased count is zero when no attendees exist.
	 */
	public function test_purchased_tickets_count_with_no_attendees() {
		$ticket_id = $this->create_ticket();
		$this->assertSame( 0, self::$camptix->get_purchased_tickets_count( $ticket_id ) );
	}

	/**
	 * Verify only publish and pending attendees count as purchased.
	 */
	public function test_purchased_tickets_count_with_mixed_statuses() {
		$ticket_id = $this->create_ticket();
		$this->create_attendee( $ticket_id, array( 'status' => 'publish' ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'pending' ) );
		$this->create_attendee( $ticket_id, array( 'status' => 'draft' ) );

		// Only publish + pending count as purchased.
		$this->assertSame( 2, self::$camptix->get_purchased_tickets_count( $ticket_id ) );
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
	 * Verify used coupons count excludes draft attendees.
	 */
	public function test_used_coupons_count() {
		$ticket_id = $this->create_ticket();
		$coupon_id = $this->create_coupon( array(
			'quantity'       => 10,
			'discount_price' => 5.00,
		) );

		$this->create_attendee( $ticket_id, array( 'coupon_id' => $coupon_id ) );
		$this->create_attendee(
			$ticket_id,
			array(
				'coupon_id' => $coupon_id,
				'status'    => 'pending',
			)
		);
		// Draft attendee should not count.
		$this->create_attendee(
			$ticket_id,
			array(
				'coupon_id' => $coupon_id,
				'status'    => 'draft',
			)
		);

		$this->assertSame( 2, self::$camptix->get_used_coupons_count( $coupon_id ) );
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
