<?php
defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/trait-wordcamp-root-blog.php';

if ( ! defined( 'WORDCAMP_CAMPTIX_STRIPE_LIVE_WEBHOOK_SECRET' ) ) {
	define( 'WORDCAMP_CAMPTIX_STRIPE_LIVE_WEBHOOK_SECRET', 'whsec_test_secret' );
}

/**
 * @covers CampTix_Payment_Method_Stripe
 */
class Test_Camptix_Payment_Stripe_Addon extends \WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
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
	 * Provide a test case for the function "CampTix_Payment_Method_Stripe->get_fractional_unit_amount".
	 **/
	public function currencyAmountProvider() {
		return array(
			array(
				'USD', 10, 1000, // 10USD should be 1000
			),
			array(
				'EUR', 10, 1000, // 10EUR should be 1000
			),
			array(
				'JPY', 10, 10, // 10 JPY should be 10
			),
		);
	}

	/**
	 * @covers CampTix_Payment_Method_Stripe->get_fractional_unit_amount
	 * @dataProvider currencyAmountProvider
	 */
	public function test_get_fractional_unit_amount( $currency, $amount, $expected_result ) {
		$client            = new CampTix_Payment_Method_Stripe();
		$fractional_amount = $client->get_fractional_unit_amount( $currency, $amount );
		$this->assertEquals( $expected_result, $fractional_amount);
	}


	/**
	 * @covers CampTix_Payment_Method_Stripe->get_fractional_unit_amount
	 * @expectedException Exception
	 */
	public function test_get_fractional_unit_amount_with_invalid_currency() {
		$client = new CampTix_Payment_Method_Stripe();
		try {
			$client->get_fractional_unit_amount( 'DUMMY', 100 );
			$this->fail( 'Exception should be thrown.' );
		} catch ( Exception $e ) {
			$this->assertEquals( 'Unknown currency multiplier for DUMMY.', $e->getMessage() );
		}
	}

	/**
	 * @covers CampTix_Payment_Method_Stripe::rest_stripe_webhook_permissions_check
	 */
	public function test_rest_stripe_webhook_permissions_check() {
		$client    = new CampTix_Payment_Method_Stripe();
		$payload   = wp_json_encode( array( 'id' => 'evt_test' ) );
		$timestamp = time();
		$signature = hash_hmac(
			'sha256',
			$timestamp . '.' . $payload,
			WORDCAMP_CAMPTIX_STRIPE_LIVE_WEBHOOK_SECRET
		);
		$request   = new WP_REST_Request( 'POST', '/camptix/v1/stripe-webhook' );

		$request->set_body( $payload );
		$request->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=' . $signature );

		$this->assertTrue( $client->rest_stripe_webhook_permissions_check( $request ) );

		$request->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=invalid' );

		$result = $client->rest_stripe_webhook_permissions_check( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'camptix_stripe_webhook_invalid_signature', $result->get_error_code() );
	}

	/**
	 * Build a draft CampTix order (attendee + tix_order meta) for the given token.
	 *
	 * @return array{0:string,1:int} [ payment_token, attendee_id ]
	 */
	protected function make_stripe_order( $token, $total ) {
		$ticket = self::factory()->post->create(
			array(
				'post_type'   => 'tix_ticket',
				'post_status' => 'publish',
			)
		);

		$attendee = self::factory()->post->create(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'draft',
			)
		);

		update_post_meta( $attendee, 'tix_payment_token', $token );
		update_post_meta( $attendee, 'tix_access_token', 'acc_' . $token );
		update_post_meta(
			$attendee,
			'tix_order',
			array(
				'items' => array(
					array(
						'id'       => $ticket,
						'name'     => 'Ticket',
						'price'    => $total,
						'quantity' => 1,
					),
				),
				'total' => $total,
			)
		);

		return array( $token, $attendee );
	}

	/**
	 * Build a draft order with explicit line items.
	 *
	 * @param string $token
	 * @param array  $items Each element: array( 'price' => float, 'quantity' => int ).
	 *
	 * @return array{0:string,1:int} [ payment_token, attendee_id ]
	 */
	protected function make_stripe_order_items( $token, $items ) {
		$order_items = array();
		$total       = 0;

		foreach ( $items as $i => $item ) {
			$ticket = self::factory()->post->create(
				array(
					'post_type'   => 'tix_ticket',
					'post_status' => 'publish',
				)
			);

			$order_items[] = array(
				'id'       => $ticket,
				'name'     => 'Ticket ' . $i,
				'price'    => $item['price'],
				'quantity' => $item['quantity'],
			);
			$total += $item['price'] * $item['quantity'];
		}

		$attendee = self::factory()->post->create(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'draft',
			)
		);

		update_post_meta( $attendee, 'tix_payment_token', $token );
		update_post_meta( $attendee, 'tix_access_token', 'acc_' . $token );
		update_post_meta(
			$attendee,
			'tix_order',
			array(
				'items' => $order_items,
				'total' => $total,
			)
		);

		return array( $token, $attendee );
	}

	/** A completed/paid Stripe Checkout Session with the given reference + amount. */
	protected function complete_session( $client_reference_id, $amount_total ) {
		return array(
			'id'                  => 'cs_test',
			'status'              => 'complete',
			'payment_status'      => 'paid',
			'amount_total'        => $amount_total,
			'client_reference_id' => $client_reference_id,
			'payment_intent'      => array(
				'status'        => 'succeeded',
				'latest_charge' => 'ch_test',
			),
		);
	}

	/** Invoke the protected return handler directly (non-interactive, like the webhook). */
	protected function invoke_return( $stripe, $payment_token, $session ) {
		$order  = $stripe->get_order( $payment_token );
		$method = new ReflectionMethod( 'CampTix_Payment_Method_Stripe', 'process_payment_return_session' );

		return $method->invoke( $stripe, $payment_token, $session, $order, false, array() );
	}

	/**
	 * A Stripe gateway instance with options loaded and the currency set to USD.
	 *
	 * @return CampTix_Payment_Method_Stripe
	 */
	protected function make_configured_stripe() {
		$stripe = new CampTix_Payment_Method_Stripe();
		$stripe->load_options();
		$stripe->camptix_options['currency'] = 'USD';

		return $stripe;
	}

	/**
	 * Drive the real public entry point payment_return() with the two request
	 * values set independently (as the interactive bypass would) and the Stripe
	 * session fetch stubbed. wp_die() is made catchable so fail-closed paths can
	 * be asserted.
	 *
	 * Only use this for paths expected to wp_die(); a successful completion inside
	 * payment_result() calls die() directly, which cannot be caught here.
	 *
	 * @return string|null The wp_die() message, or null if it did not die.
	 */
	protected function drive_payment_return( $stripe, $payment_token, $stripe_session_id, $session ) {
		$_REQUEST['tix_payment_token']  = $payment_token;
		$_REQUEST['tix_stripe_session'] = $stripe_session_id;

		$http_stub = function () use ( $session ) {
			// A WP_Error stands in for Stripe not answering at all.
			if ( is_wp_error( $session ) ) {
				return $session;
			}

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( $session ),
				'headers'  => array(),
			);
		};
		$die_handler = function () {
			return function ( $message ) {
				throw new Exception( esc_html( 'wp_die: ' . ( is_wp_error( $message ) ? $message->get_error_message() : $message ) ) );
			};
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );
		add_filter( 'wp_die_handler', $die_handler );

		$died = null;
		try {
			$stripe->payment_return();
		} catch ( Exception $e ) {
			$died = $e->getMessage();
		} finally {
			remove_filter( 'pre_http_request', $http_stub, 10 );
			remove_filter( 'wp_die_handler', $die_handler );
			unset( $_REQUEST['tix_payment_token'], $_REQUEST['tix_stripe_session'] );
		}

		return $died;
	}

	/**
	 * A complete/paid session belonging to a DIFFERENT order must not complete
	 * the order named in the request. Regression test for the interactive
	 * payment-return verification bypass.
	 *
	 * @covers CampTix_Payment_Method_Stripe::process_payment_return_session
	 */
	public function test_return_rejects_session_bound_to_a_different_order() {
		$stripe = $this->make_configured_stripe();
		list( $victim_token, $victim_id ) = $this->make_stripe_order( 'tok_victim', 500 );

		// A paid session for the CHEAP order, replayed against the victim's token.
		$session = $this->complete_session( get_current_blog_id() . ':tok_cheap', 500 );
		$result  = $this->invoke_return( $stripe, $victim_token, $session );

		$this->assertFalse( $result );
		$this->assertSame( 'draft', get_post_status( $victim_id ) );
	}

	/**
	 * A correctly-referenced, fully-paid session still completes the order.
	 *
	 * @covers CampTix_Payment_Method_Stripe::process_payment_return_session
	 */
	public function test_return_completes_when_session_matches_order_and_amount() {
		$stripe = $this->make_configured_stripe();
		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_ok', 500 );

		$expected = $stripe->get_fractional_unit_amount( 'USD', 500 ); // 50000
		$session  = $this->complete_session( get_current_blog_id() . ':' . $token, $expected );

		$this->invoke_return( $stripe, $token, $session );

		$this->assertSame( 'publish', get_post_status( $attendee_id ) );
	}

	/**
	 * A session bound to the right order but paid for less than the order total
	 * must not complete it. The buyer may have been charged something, so the
	 * order is parked as `pending` for an organizer to reconcile rather than
	 * completed or left silently in draft.
	 *
	 * @covers CampTix_Payment_Method_Stripe::process_payment_return_session
	 */
	public function test_return_marks_pending_when_underpaid_for_the_correct_order() {
		$stripe = $this->make_configured_stripe();
		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_underpaid', 500 );

		// Right order, but only 500 fractional units paid on a $500 (50000) order.
		$session = $this->complete_session( get_current_blog_id() . ':' . $token, 500 );
		$result  = $this->invoke_return( $stripe, $token, $session );

		$this->assertFalse( $result );
		$this->assertSame( 'pending', get_post_status( $attendee_id ) );
	}

	/**
	 * A multi-quantity order with a fractional per-ticket price must complete when
	 * the session pays what Stripe actually charges: the per-item fractional amount
	 * times quantity (the line item create_session() sends). Converting the whole
	 * order total in one step truncates differently and used to reject this
	 * fully-paid order.
	 *
	 * @covers CampTix_Payment_Method_Stripe::process_payment_return_session
	 * @covers CampTix_Payment_Method_Stripe::get_expected_fractional_total
	 */
	public function test_return_completes_multi_quantity_decimal_order_matching_stripe_line_items() {
		$stripe = $this->make_configured_stripe();
		list( $token, $attendee_id ) = $this->make_stripe_order_items(
			'tok_decimal',
			array(
				array(
					'price'    => 19.99,
					'quantity' => 3,
				),
			)
		);

		$stripe_amount = $stripe->get_fractional_unit_amount( 'USD', 19.99 ) * 3;
		$session       = $this->complete_session( get_current_blog_id() . ':' . $token, $stripe_amount );

		$this->invoke_return( $stripe, $token, $session );

		$this->assertSame( 'publish', get_post_status( $attendee_id ) );
	}

	/**
	 * The reported attack, through the real public entry point: an attacker names
	 * the victim's payment token but supplies a paid session that belongs to a
	 * different (cheap) order. payment_return() must fail closed and not complete
	 * the victim's order.
	 *
	 * @covers CampTix_Payment_Method_Stripe::payment_return
	 * @covers CampTix_Payment_Method_Stripe::process_payment_return_session
	 */
	public function test_payment_return_rejects_replayed_session_for_a_different_order() {
		$stripe = $this->make_configured_stripe();
		list( $victim_token, $victim_id ) = $this->make_stripe_order( 'tok_victim_i', 500 );

		$session = $this->complete_session( get_current_blog_id() . ':tok_cheap', 500 );
		$died    = $this->drive_payment_return( $stripe, $victim_token, 'cs_attacker', $session );

		$this->assertNotNull( $died, 'payment_return() should have failed closed via wp_die().' );
		$this->assertSame( 'draft', get_post_status( $victim_id ) );
	}

	/**
	 * Through the real public entry point: a correctly-referenced session that
	 * paid the wrong amount parks the order as pending and tells the buyer to
	 * contact the organizers, rather than stranding a charged buyer in draft.
	 *
	 * @covers CampTix_Payment_Method_Stripe::payment_return
	 * @covers CampTix_Payment_Method_Stripe::process_payment_return_session
	 */
	public function test_payment_return_parks_pending_and_warns_buyer_on_amount_mismatch() {
		$stripe = $this->make_configured_stripe();
		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_i_underpaid', 500 );

		$session = $this->complete_session( get_current_blog_id() . ':' . $token, 500 );
		$died    = $this->drive_payment_return( $stripe, $token, 'cs_underpaid', $session );

		$this->assertNotNull( $died );
		$this->assertStringContainsString( 'contact the event organizers', $died );
		$this->assertSame( 'pending', get_post_status( $attendee_id ) );
	}

	/**
	 * When Stripe can't be reached on return, the buyer is told not to pay again and the
	 * order is left for the webhook or the timeout sweep, instead of a fatal error.
	 *
	 * @covers CampTix_Payment_Method_Stripe::payment_return
	 */
	public function test_payment_return_leaves_order_when_stripe_cannot_be_reached() {
		$stripe = $this->make_configured_stripe();
		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_return_down', 500 );

		$down = new WP_Error( 'http_request_failed', 'Connection timed out' );
		$died = $this->drive_payment_return( $stripe, $token, 'cs_return_down', $down );

		$this->assertNotNull( $died );
		$this->assertStringContainsString( 'Please do not pay again', $died );
		$this->assertSame( 'draft', get_post_status( $attendee_id ) );
	}

	/**
	 * A Checkout Session is created with an expiry, so an abandoned draft's seat is only
	 * ever held for as long as the session can be paid.
	 */
	public function test_create_session_sends_expires_at() {
		$captured = array();
		$stub     = function ( $pre, $args, $url ) use ( &$captured ) {
			if ( false === strpos( $url, 'api.stripe.com' ) ) {
				return $pre;
			}
			$captured = $args['body'];

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode(
					array(
						'id'  => 'cs_test_1',
						'url' => 'https://checkout.stripe.test/cs_test_1',
					)
				),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $stub, 10, 3 );
		$client     = new CampTix_Stripe_API_Client( 'tok_test', 'sk_test' );
		$expires_at = time() + 30 * MINUTE_IN_SECONDS;
		$client->create_session( 'Event', array(), '', 'https://example.test/return', 'https://example.test/cancel', array(), $expires_at );
		remove_filter( 'pre_http_request', $stub, 10 );

		$this->assertSame( $expires_at, $captured['expires_at'] ?? null );
	}

	/**
	 * Every request is pinned to the API version the code was written against, so Stripe never
	 * falls back to the account default, which it rate-limits once it is over seven years old.
	 *
	 * @covers CampTix_Stripe_API_Client::send_request
	 */
	public function test_requests_pin_the_api_version() {
		$captured = array();
		$stub     = function ( $pre, $args, $url ) use ( &$captured ) {
			if ( false === strpos( $url, 'api.stripe.com' ) ) {
				return $pre;
			}
			$captured[] = $args['headers'];

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( array( 'id' => 'cs_test_1' ) ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $stub, 10, 3 );
		$client = new CampTix_Stripe_API_Client( 'tok_test', 'sk_test' );
		$client->create_session( 'Event', array(), '', 'https://example.test/return', 'https://example.test/cancel', array() );
		$client->get_session( 'cs_test_1' );
		$client->request_refund( 'ch_test_1' );
		remove_filter( 'pre_http_request', $stub, 10 );

		$this->assertCount( 3, $captured );
		foreach ( $captured as $headers ) {
			$this->assertSame( CampTix_Stripe_API_Client::API_VERSION, $headers['Stripe-Version'] ?? null );
		}
	}

	/**
	 * Run the timeout backstop for a draft with the Stripe session fetch stubbed.
	 */
	protected function drive_pre_attendee_timeout( $stripe, $attendee_id, $session ) {
		update_post_meta( $attendee_id, '_stripe_checkout_session_id', $session['id'] );

		$http_stub = function () use ( $session ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( $session ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );
		try {
			$stripe->pre_attendee_timeout( $attendee_id );
		} finally {
			remove_filter( 'pre_http_request', $http_stub, 10 );
		}
	}

	/**
	 * Before a draft is timed out, its session is checked: only an expired one is left to
	 * time out. A paid one completes the order, a delayed payment goes pending, and one Stripe
	 * still reports open keeps its seat with the expiry re-recorded from Stripe.
	 *
	 * @covers CampTix_Payment_Method_Stripe::pre_attendee_timeout
	 */
	public function test_pre_attendee_timeout_settles_any_session_that_is_not_expired() {
		$stripe = $this->make_configured_stripe();

		list( $token, $paid ) = $this->make_stripe_order( 'tok_paid', 500 );
		$this->drive_pre_attendee_timeout( $stripe, $paid, $this->complete_session( get_current_blog_id() . ':' . $token, $stripe->get_fractional_unit_amount( 'USD', 500 ) ) );
		$this->assertSame( 'publish', get_post_status( $paid ) );

		list( $token, $delayed ) = $this->make_stripe_order( 'tok_delayed', 500 );
		$session                 = $this->complete_session( get_current_blog_id() . ':' . $token, 50000 );
		$session['payment_status'] = 'unpaid';
		$this->drive_pre_attendee_timeout( $stripe, $delayed, $session );
		$this->assertSame( 'pending', get_post_status( $delayed ) );

		list( $token, $open ) = $this->make_stripe_order( 'tok_open', 500 );
		$expires_at           = time() + 20 * MINUTE_IN_SECONDS;
		$this->drive_pre_attendee_timeout(
			$stripe,
			$open,
			array(
				'id'                  => 'cs_open',
				'status'              => 'open',
				'expires_at'          => $expires_at,
				'client_reference_id' => get_current_blog_id() . ':' . $token,
			)
		);
		$this->assertSame( 'draft', get_post_status( $open ) );
		$this->assertSame( $expires_at, (int) get_post_meta( $open, 'tix_session_expires_at', true ) );

		list( $token, $expired ) = $this->make_stripe_order( 'tok_expired', 500 );
		$this->drive_pre_attendee_timeout(
			$stripe,
			$expired,
			array(
				'id'                  => 'cs_expired',
				'status'              => 'expired',
				'client_reference_id' => get_current_blog_id() . ':' . $token,
			)
		);
		$this->assertSame( 'draft', get_post_status( $expired ), 'left for the sweep' );
	}

	/**
	 * The session id and expiry are recorded on every attendee of the order, so the sweep can
	 * look the session up from whichever attendee it reaches first.
	 *
	 * @covers CampTix_Payment_Method_Stripe::record_checkout_session
	 */
	public function test_record_checkout_session_stamps_every_attendee_of_the_order() {
		$stripe = $this->make_configured_stripe();
		list( $token, $first )  = $this->make_stripe_order( 'tok_pair', 500 );
		list( $token, $second ) = $this->make_stripe_order( 'tok_pair', 500 );
		$expires_at             = time() + 30 * MINUTE_IN_SECONDS;

		$stripe->record_checkout_session( $token, 'cs_pair', $expires_at );

		foreach ( array( $first, $second ) as $attendee_id ) {
			$this->assertSame( 'cs_pair', get_post_meta( $attendee_id, '_stripe_checkout_session_id', true ) );
			$this->assertSame( $expires_at, (int) get_post_meta( $attendee_id, 'tix_session_expires_at', true ) );
		}
	}

	/**
	 * A multi-attendee order whose confirmation never arrived is settled as a whole by the
	 * sweep, whichever of its attendees the sweep reaches first.
	 *
	 * @covers CampTix_Payment_Method_Stripe::pre_attendee_timeout
	 */
	public function test_timeout_sweep_settles_a_whole_paid_order() {
		global $camptix;

		$stripe = $this->make_configured_stripe();
		list( $token, $first )  = $this->make_stripe_order( 'tok_two', 500 );
		list( $token, $second ) = $this->make_stripe_order( 'tok_two', 500 );
		$this->date_draft( $second, time() + 1 );
		$stripe->record_checkout_session( $token, 'cs_two', time() - 10 * MINUTE_IN_SECONDS );

		$session   = $this->complete_session( get_current_blog_id() . ':' . $token, 50000 );
		$http_stub = function () use ( $session ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( $session ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );
		try {
			$camptix->review_timeout_payments();
		} finally {
			remove_filter( 'pre_http_request', $http_stub, 10 );
		}

		$this->assertSame( 'publish', get_post_status( $first ) );
		$this->assertSame( 'publish', get_post_status( $second ) );
	}

	/**
	 * When Stripe can't be asked, the draft is kept for the next sweep rather than timed out.
	 *
	 * @covers CampTix_Payment_Method_Stripe::pre_attendee_timeout
	 */
	public function test_timeout_sweep_keeps_a_draft_when_stripe_cannot_be_asked() {
		global $camptix;

		$stripe = $this->make_configured_stripe();
		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_apidown', 500 );
		$stripe->record_checkout_session( $token, 'cs_apidown', time() - 10 * MINUTE_IN_SECONDS );

		$calls = 0;
		$down  = function () use ( &$calls ) {
			$calls++;
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		};

		$this->sweep_with( $down );
		$this->assertSame( 'draft', get_post_status( $attendee_id ), 'no checkout time recorded: kept' );

		// With no checkout time recorded the post date is the floor for the cap.
		$this->date_draft( $attendee_id, time() - DAY_IN_SECONDS - 6 * MINUTE_IN_SECONDS );
		update_post_meta( $attendee_id, 'tix_session_expires_at', time() - 10 * MINUTE_IN_SECONDS );
		$this->sweep_with( $down );
		$this->assertSame( 'timeout', get_post_status( $attendee_id ), 'no checkout time recorded, but a day old by post date' );

		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_apidown2', 500 );
		$stripe->record_checkout_session( $token, 'cs_apidown2', time() - 10 * MINUTE_IN_SECONDS );
		update_post_meta( $attendee_id, 'tix_timestamp', time() - 40 * MINUTE_IN_SECONDS );
		update_post_meta( $attendee_id, 'tix_session_expires_at', time() - 10 * MINUTE_IN_SECONDS );
		$this->sweep_with( $down );
		$this->assertSame( 'draft', get_post_status( $attendee_id ) );
		$this->assertGreaterThan( time(), $GLOBALS['camptix']->get_draft_release_at( $attendee_id ), 'retried by a later sweep' );

		// But not forever: past the 24 hours plus grace, the draft is timed out even if Stripe still can't be asked.
		update_post_meta( $attendee_id, 'tix_timestamp', time() - DAY_IN_SECONDS - 6 * MINUTE_IN_SECONDS );
		update_post_meta( $attendee_id, 'tix_session_expires_at', time() - 10 * MINUTE_IN_SECONDS );
		$this->sweep_with( $down );
		$this->assertSame( 'timeout', get_post_status( $attendee_id ) );
	}

	/**
	 * A session Stripe still reports open after its expiry (a UPI or bank confirmation in
	 * flight) keeps its draft for the next sweep, instead of being timed out in the same pass.
	 * As when Stripe can't be asked, that stops after 24 hours.
	 *
	 * @covers CampTix_Payment_Method_Stripe::pre_attendee_timeout
	 */
	public function test_timeout_sweep_keeps_a_draft_whose_session_is_still_open() {
		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_open_late', 500 );
		$expired_at                  = time() - 10 * MINUTE_IN_SECONDS;
		$this->make_configured_stripe()->record_checkout_session( $token, 'cs_open_late', $expired_at );
		update_post_meta( $attendee_id, 'tix_timestamp', time() - 40 * MINUTE_IN_SECONDS );

		$calls = 0;
		$open  = function () use ( &$calls, $token, $expired_at ) {
			$calls++;

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( array(
					'id'                  => 'cs_open_late',
					'status'              => 'open',
					'expires_at'          => $expired_at,
					'client_reference_id' => get_current_blog_id() . ':' . $token,
				) ),
				'headers'  => array(),
			);
		};

		$this->sweep_with( $open );
		$this->assertSame( 'draft', get_post_status( $attendee_id ) );
		$this->assertGreaterThan( time(), $GLOBALS['camptix']->get_draft_release_at( $attendee_id ), 'retried by a later sweep' );
		$this->assertSame( 1, $calls, 'asked once: the kept draft is no longer due in this pass' );

		update_post_meta( $attendee_id, 'tix_timestamp', time() - DAY_IN_SECONDS - 6 * MINUTE_IN_SECONDS );
		update_post_meta( $attendee_id, 'tix_session_expires_at', $expired_at );
		$this->sweep_with( $open );
		$this->assertSame( 'timeout', get_post_status( $attendee_id ), 'not past 24 hours' );
	}

	/**
	 * Give a draft a post date. wp_update_post() resets a draft's date to now, so straight to the row.
	 */
	protected function date_draft( $attendee_id, $timestamp ) {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'post_date' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ) ), array( 'ID' => $attendee_id ) );
		clean_post_cache( $attendee_id );
	}

	/**
	 * Run the timeout sweep with the Stripe session fetch stubbed.
	 */
	protected function sweep_with( $http_stub ) {
		add_filter( 'pre_http_request', $http_stub, 10, 3 );
		try {
			$GLOBALS['camptix']->review_timeout_payments();
		} finally {
			remove_filter( 'pre_http_request', $http_stub, 10 );
		}
	}

	/**
	 * The sweep looks a session up once per order, not once per attendee, and once Stripe has
	 * failed to answer it stops asking for the rest of the pass. Every draft is still kept.
	 *
	 * @covers CampTix_Payment_Method_Stripe::pre_attendee_timeout
	 */
	public function test_timeout_sweep_looks_up_each_order_once_and_stops_asking_once_stripe_is_down() {
		$stripe = $this->make_configured_stripe();

		$attendees = array();
		foreach ( array( 'tok_a', 'tok_a', 'tok_a', 'tok_b', 'tok_c' ) as $token ) {
			list( $token, $attendee_id ) = $this->make_stripe_order( $token, 500 );
			update_post_meta( $attendee_id, 'tix_timestamp', time() - 40 * MINUTE_IN_SECONDS );
			$attendees[] = $attendee_id;
		}
		foreach ( array( 'tok_a', 'tok_b', 'tok_c' ) as $token ) {
			$stripe->record_checkout_session( $token, 'cs_' . $token, time() - 10 * MINUTE_IN_SECONDS );
		}

		$calls = 0;
		$open  = function () use ( &$calls ) {
			$calls++;
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode(
					array(
						'id'         => 'cs_open',
						'status'     => 'open',
						'expires_at' => time() + 20 * MINUTE_IN_SECONDS,
					)
				),
				'headers'  => array(),
			);
		};
		$this->sweep_with( $open );
		$this->assertSame( 3, $calls, 'one lookup per order, three orders' );

		foreach ( $attendees as $attendee_id ) {
			update_post_meta( $attendee_id, 'tix_session_expires_at', time() - 10 * MINUTE_IN_SECONDS );
		}

		$calls = 0;
		$down  = function () use ( &$calls ) {
			$calls++;
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		};
		$this->sweep_with( $down );
		$this->assertSame( 1, $calls, 'after the first failure the rest of the pass is kept without asking' );

		foreach ( $attendees as $attendee_id ) {
			$this->assertSame( 'draft', get_post_status( $attendee_id ) );
		}
	}

	/**
	 * Stripe answering with an error is not Stripe being unreachable: the next order is still
	 * asked, and the draft is kept for the next sweep. A 404 included: it only says the session
	 * isn't on the account and mode of the key asking.
	 *
	 * @covers CampTix_Payment_Method_Stripe::pre_attendee_timeout
	 */
	public function test_timeout_sweep_tells_a_stripe_error_from_stripe_being_down() {
		$stripe = $this->make_configured_stripe();

		$orders = array();
		foreach ( array( 'tok_stale', 'tok_flaky', 'tok_expired' ) as $i => $token ) {
			list( $token, $attendee_id ) = $this->make_stripe_order( $token, 500 );
			update_post_meta( $attendee_id, 'tix_timestamp', time() - 40 * MINUTE_IN_SECONDS );
			// Newest first is the sweep's order, so the stale one is asked first.
			$this->date_draft( $attendee_id, time() - $i );
			$stripe->record_checkout_session( $token, 'cs_' . $token, time() - 10 * MINUTE_IN_SECONDS );
			$orders[ $token ] = $attendee_id;
		}

		$asked  = array();
		$answer = function ( $pre, $args, $url ) use ( &$asked ) {
			$asked[] = $url;
			$code    = 200;
			$body    = array(
				'id'     => 'cs_tok_expired',
				'status' => 'expired',
			);
			if ( false !== strpos( $url, 'cs_tok_stale' ) ) {
				$code = 404;
				$body = array(
					'error' => array(
						'type' => 'invalid_request_error',
						'code' => 'resource_missing',
					),
				);
			} elseif ( false !== strpos( $url, 'cs_tok_flaky' ) ) {
				$code = 500;
				$body = array( 'error' => array( 'type' => 'api_error' ) );
			}

			return array(
				'response' => array(
					'code'    => $code,
					'message' => 'x',
				),
				'body'     => wp_json_encode( $body ),
				'headers'  => array(),
			);
		};
		$this->sweep_with( $answer );

		$this->assertCount( 3, $asked, 'every order is asked' );
		$this->assertSame( 'draft', get_post_status( $orders['tok_stale'] ), '404: not on this key, kept' );
		$this->assertSame( 'draft', get_post_status( $orders['tok_flaky'] ), '500: kept for the next sweep' );
		$this->assertSame( 'timeout', get_post_status( $orders['tok_expired'] ), 'expired: timed out' );
	}

	/**
	 * Venue countries and the gateway name and description a EUR event should show for them.
	 */
	public function eurVenueCountryProvider() {
		return array(
			'Netherlands names iDEAL'  => array( 'NL', 'Credit Card or iDEAL (Stripe)', 'Credit card and iDEAL processing, powered by Stripe.' ),
			'Belgium names Bancontact' => array( 'BE', 'Credit Card or Bancontact (Stripe)', 'Credit card and Bancontact processing, powered by Stripe.' ),
			'Austria names EPS'        => array( 'AT', 'Credit Card or EPS (Stripe)', 'Credit card and EPS processing, powered by Stripe.' ),
			'lower-case country code'  => array( 'nl', 'Credit Card or iDEAL (Stripe)', 'Credit card and iDEAL processing, powered by Stripe.' ),
			'other EUR country'        => array( 'DE', 'Credit Card (Stripe)', 'Credit card processing, powered by Stripe.' ),
			'no venue country'         => array( '', 'Credit Card (Stripe)', 'Credit card processing, powered by Stripe.' ),
		);
	}

	/**
	 * @covers CampTix_Payment_Method_Stripe::camptix_init
	 * @dataProvider eurVenueCountryProvider
	 */
	public function test_eur_gateway_label_follows_venue_country( $country, $expected_name, $expected_description ) {
		$this->set_venue_country( $country );

		$stripe = $this->init_stripe_for_currency( 'EUR' );

		$this->assertSame( $expected_name, $stripe->name );
		$this->assertSame( $expected_description, $stripe->description );
	}

	/**
	 * @covers CampTix_Payment_Method_Stripe::camptix_init
	 */
	public function test_eur_gateway_label_without_a_wordcamp_post() {
		$stripe = $this->init_stripe_for_currency( 'EUR' );

		$this->assertSame( 'Credit Card (Stripe)', $stripe->name );
		$this->assertSame( 'Credit card processing, powered by Stripe.', $stripe->description );
	}

	/**
	 * The venue country only changes the EUR label; other currencies keep their own.
	 *
	 * @covers CampTix_Payment_Method_Stripe::camptix_init
	 */
	public function test_venue_country_does_not_change_other_currencies() {
		$this->set_venue_country( 'NL' );

		$this->assertSame( 'Credit Card or UPI (Stripe)', $this->init_stripe_for_currency( 'INR' )->name );
		$this->assertSame( 'Credit Card (Stripe)', $this->init_stripe_for_currency( 'USD' )->name );
	}

	/**
	 * Point the current site's WordCamp post, on the root blog, at a venue country.
	 *
	 * @param string $country Two-letter country code, or '' for none.
	 */
	protected function set_venue_country( $country ) {
		$site_id = get_current_blog_id();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$wordcamp_id = self::factory()->post->create(
			array(
				'post_type'   => 'wordcamp',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $wordcamp_id, '_site_id', $site_id );
		if ( $country ) {
			update_post_meta( $wordcamp_id, '_venue_country_code', $country );
		}
		restore_current_blog();
	}

	/**
	 * A Stripe gateway run through camptix_init() with the site's currency set.
	 *
	 * @param string $currency Currency code.
	 *
	 * @return CampTix_Payment_Method_Stripe
	 */
	protected function init_stripe_for_currency( $currency ) {
		global $camptix;

		$options             = get_option( 'camptix_options', array() );
		$options['currency'] = $currency;
		update_option( 'camptix_options', $options );
		$camptix->load_options();

		$stripe = new CampTix_Payment_Method_Stripe();
		$stripe->camptix_init();

		return $stripe;
	}

	/**
	 * A webhook signed with any predefined account's secret is accepted, as is one signed with
	 * the original constant. Anything else is still rejected.
	 *
	 * @covers CampTix_Payment_Method_Stripe::rest_stripe_webhook_permissions_check
	 */
	public function test_webhook_accepts_each_predefined_accounts_secret() {
		$client = new class() extends CampTix_Payment_Method_Stripe {
			/**
			 * One account without a webhook secret, one with.
			 */
			public function get_predefined_accounts() {
				return array(
					'first'  => array( 'label' => 'No webhook secret' ),
					'second' => array( 'webhook_secret' => ' whsec_second ' ),
				);
			}
		};

		$this->assertTrue( $client->rest_stripe_webhook_permissions_check( $this->signed_webhook_request( 'whsec_second' ) ) );
		$this->assertTrue( $client->rest_stripe_webhook_permissions_check( $this->signed_webhook_request( WORDCAMP_CAMPTIX_STRIPE_LIVE_WEBHOOK_SECRET ) ) );
		$this->assertWPError( $client->rest_stripe_webhook_permissions_check( $this->signed_webhook_request( 'whsec_other' ) ) );
	}

	/**
	 * A webhook request signed with the given secret.
	 */
	protected function signed_webhook_request( $secret ) {
		$payload   = wp_json_encode( array( 'id' => 'evt_test' ) );
		$timestamp = time();
		$request   = new WP_REST_Request( 'POST', '/camptix/v1/stripe-webhook' );

		$request->set_body( $payload );
		$request->set_header( 'stripe-signature', 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret ) );

		return $request;
	}

	/**
	 * A pending order waiting on a delayed payment, as left by a completed-but-unpaid checkout.
	 *
	 * @param string $token          Payment token.
	 * @param int    $pending_since  When the order went pending.
	 * @param string $payment_status The payment status recorded when it went pending.
	 *
	 * @return int[] The order's attendee IDs.
	 */
	protected function make_pending_order( $token, $pending_since, $payment_status = 'unpaid' ) {
		global $wpdb;

		$attendee_ids = array();
		foreach ( array( 1, 2 ) as $i ) {
			list( , $attendee_id ) = $this->make_stripe_order( $token, 500 );
			$attendee_ids[]        = $attendee_id;
		}

		$this->make_configured_stripe()->record_checkout_session( $token, 'cs_' . $token, time() );

		foreach ( $attendee_ids as $attendee_id ) {
			wp_update_post(
				array(
					'ID'          => $attendee_id,
					'post_status' => 'pending',
				)
			);
			update_post_meta(
				$attendee_id,
				'tix_transaction_details',
				array(
					'raw' => array(
						'id'             => 'cs_' . $token,
						'status'         => 'complete',
						'payment_status' => $payment_status,
					),
				)
			);

			$modified = gmdate( 'Y-m-d H:i:s', $pending_since );
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified_gmt' => $modified,
					'post_modified'     => get_date_from_gmt( $modified ),
				),
				array( 'ID' => $attendee_id )
			);
			clean_post_cache( $attendee_id );
		}

		return $attendee_ids;
	}

	/**
	 * A Checkout Session for a delayed payment, in the given state.
	 */
	protected function delayed_session( $token, $payment_status, $intent_status ) {
		$session                   = $this->complete_session( get_current_blog_id() . ':' . $token, 50000 );
		$session['id']             = 'cs_' . $token;
		$session['payment_status'] = $payment_status;
		$session['payment_intent'] = array(
			'status'        => $intent_status,
			'latest_charge' => 'ch_' . $token,
		);

		return $session;
	}

	/**
	 * Run the pending sweep with the Stripe session fetch stubbed, counting the lookups.
	 *
	 * @param array|WP_Error $session The session Stripe answers with, or a transport error.
	 *
	 * @return int How many times Stripe was asked.
	 */
	protected function pending_sweep_with( $session ) {
		$calls = 0;
		$stub  = function () use ( $session, &$calls ) {
			$calls++;

			if ( is_wp_error( $session ) ) {
				return $session;
			}

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( $session ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $stub, 10, 3 );
		try {
			$this->make_configured_stripe()->review_pending_payments();
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
		}

		return $calls;
	}

	/**
	 * A delayed payment that succeeded, with the webhook lost, is completed by the sweep. One
	 * lookup covers the whole order.
	 *
	 * @covers CampTix_Payment_Method_Stripe::review_pending_payments
	 */
	public function test_pending_sweep_completes_a_paid_order() {
		$attendee_ids = $this->make_pending_order( 'tok_boleto_paid', time() - 2 * HOUR_IN_SECONDS );

		$calls = $this->pending_sweep_with( $this->delayed_session( 'tok_boleto_paid', 'paid', 'succeeded' ) );

		$this->assertSame( 1, $calls );
		foreach ( $attendee_ids as $attendee_id ) {
			$this->assertSame( 'publish', get_post_status( $attendee_id ) );
		}
	}

	/**
	 * A delayed payment that failed, with the webhook lost, fails the order and frees its seats.
	 *
	 * @covers CampTix_Payment_Method_Stripe::review_pending_payments
	 */
	public function test_pending_sweep_fails_a_failed_order() {
		$attendee_ids = $this->make_pending_order( 'tok_boleto_failed', time() - 2 * HOUR_IN_SECONDS );

		$this->pending_sweep_with( $this->delayed_session( 'tok_boleto_failed', 'unpaid', 'requires_payment_method' ) );

		foreach ( $attendee_ids as $attendee_id ) {
			$this->assertSame( 'failed', get_post_status( $attendee_id ) );
		}
	}

	/**
	 * A payment still in flight stays pending, and isn't asked about again for an hour.
	 *
	 * @covers CampTix_Payment_Method_Stripe::review_pending_payments
	 */
	public function test_pending_sweep_asks_about_an_order_in_flight_once_an_hour() {
		$attendee_ids = $this->make_pending_order( 'tok_boleto_waiting', time() - 2 * HOUR_IN_SECONDS );
		$session      = $this->delayed_session( 'tok_boleto_waiting', 'unpaid', 'processing' );

		$this->assertSame( 1, $this->pending_sweep_with( $session ) );
		$this->assertSame( 'pending', get_post_status( $attendee_ids[0] ) );
		$this->assertSame( 'pending', get_post_status( $attendee_ids[1] ) );

		$this->assertSame( 0, $this->pending_sweep_with( $session ), 'checked less than an hour ago' );

		update_post_meta( $attendee_ids[0], '_stripe_pending_checked_at', time() - HOUR_IN_SECONDS - 1 );
		update_post_meta( $attendee_ids[1], '_stripe_pending_checked_at', time() - HOUR_IN_SECONDS - 1 );
		$this->assertSame( 1, $this->pending_sweep_with( $session ), 'checked over an hour ago' );
	}

	/**
	 * An order that went pending less than an hour ago is left for its webhook.
	 *
	 * @covers CampTix_Payment_Method_Stripe::review_pending_payments
	 */
	public function test_pending_sweep_leaves_a_recent_order_to_the_webhook() {
		$attendee_ids = $this->make_pending_order( 'tok_boleto_new', time() - 10 * MINUTE_IN_SECONDS );

		$this->assertSame( 0, $this->pending_sweep_with( $this->delayed_session( 'tok_boleto_new', 'paid', 'succeeded' ) ) );
		$this->assertSame( 'pending', get_post_status( $attendee_ids[0] ) );
	}

	/**
	 * An order parked as pending because the amount paid didn't match is the organizer's to
	 * reconcile. Stripe already said it was paid, so the sweep doesn't ask again.
	 *
	 * @covers CampTix_Payment_Method_Stripe::review_pending_payments
	 */
	public function test_pending_sweep_skips_an_order_held_for_organizer_review() {
		$attendee_ids = $this->make_pending_order( 'tok_mismatch', time() - 2 * HOUR_IN_SECONDS, 'paid' );

		$this->assertSame( 0, $this->pending_sweep_with( $this->delayed_session( 'tok_mismatch', 'paid', 'succeeded' ) ) );
		$this->assertSame( 'pending', get_post_status( $attendee_ids[0] ) );
	}

	/**
	 * When Stripe doesn't answer, the order stays pending and unstamped, so the next pass asks
	 * again, and the rest of this pass doesn't wait on a lookup each.
	 *
	 * @covers CampTix_Payment_Method_Stripe::review_pending_payments
	 */
	public function test_pending_sweep_stops_asking_once_stripe_is_down() {
		$first  = $this->make_pending_order( 'tok_down_1', time() - 2 * HOUR_IN_SECONDS );
		$second = $this->make_pending_order( 'tok_down_2', time() - 2 * HOUR_IN_SECONDS );

		$calls = $this->pending_sweep_with( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$this->assertSame( 1, $calls );
		foreach ( array_merge( $first, $second ) as $attendee_id ) {
			$this->assertSame( 'pending', get_post_status( $attendee_id ) );
			$this->assertSame( '', get_post_meta( $attendee_id, '_stripe_pending_checked_at', true ) );
		}
	}

	/**
	 * Hit the cancel URL for an order with the Stripe session fetch stubbed.
	 *
	 * @param string         $payment_token Payment token.
	 * @param array|WP_Error $session       The session Stripe answers with, or a transport error.
	 *
	 * @return string Where the buyer was redirected to.
	 */
	protected function drive_payment_cancel( $payment_token, $session ) {
		$stub = function () use ( $session ) {
			if ( is_wp_error( $session ) ) {
				return $session;
			}

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( $session ),
				'headers'  => array(),
			);
		};

		$location     = '';
		$stop_redirect = function ( $url ) use ( &$location ) {
			$location = $url;
			throw new WPDieException( 'redirect' );
		};

		$_REQUEST['tix_payment_token'] = $payment_token;
		add_filter( 'pre_http_request', $stub, 10, 3 );
		add_filter( 'wp_redirect', $stop_redirect );

		try {
			$this->make_configured_stripe()->payment_cancel();
			$this->fail( 'payment_cancel() did not redirect.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
			remove_filter( 'wp_redirect', $stop_redirect );
			unset( $_REQUEST['tix_payment_token'] );
		}

		return $location;
	}

	/**
	 * A buyer who reaches the cancel URL after paying keeps the tickets they paid for.
	 *
	 * @covers CampTix_Payment_Method_Stripe::payment_cancel
	 */
	public function test_cancel_completes_an_order_whose_session_is_already_paid() {
		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_cancel_paid', 500 );
		$this->make_configured_stripe()->record_checkout_session( $token, 'cs_cancel_paid', time() + HOUR_IN_SECONDS );

		$location = $this->drive_payment_cancel( $token, $this->complete_session( get_current_blog_id() . ':' . $token, 50000 ) );

		$this->assertSame( 'publish', get_post_status( $attendee_id ) );
		$this->assertStringContainsString( 'tix_action=access_tickets', $location );
	}

	/**
	 * A delayed payment completed at Stripe goes pending rather than being cancelled.
	 *
	 * @covers CampTix_Payment_Method_Stripe::payment_cancel
	 */
	public function test_cancel_keeps_a_delayed_payment_pending() {
		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_cancel_delayed', 500 );
		$this->make_configured_stripe()->record_checkout_session( $token, 'cs_cancel_delayed', time() + HOUR_IN_SECONDS );

		$session                   = $this->complete_session( get_current_blog_id() . ':' . $token, 50000 );
		$session['payment_status'] = 'unpaid';
		$session['payment_intent'] = array( 'status' => 'processing' );

		$this->drive_payment_cancel( $token, $session );

		$this->assertSame( 'pending', get_post_status( $attendee_id ) );
	}

	/**
	 * A session that isn't complete, or one Stripe can't be asked about, is cancelled as before.
	 *
	 * @covers CampTix_Payment_Method_Stripe::payment_cancel
	 */
	public function test_cancel_cancels_an_open_or_unknown_session() {
		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_cancel_open', 500 );
		$this->make_configured_stripe()->record_checkout_session( $token, 'cs_cancel_open', time() + HOUR_IN_SECONDS );

		$session           = $this->complete_session( get_current_blog_id() . ':' . $token, 50000 );
		$session['status'] = 'open';

		$location = $this->drive_payment_cancel( $token, $session );

		$this->assertSame( 'cancel', get_post_status( $attendee_id ) );
		$this->assertStringContainsString( 'payment_cancelled', $location );

		list( $token, $attendee_id ) = $this->make_stripe_order( 'tok_cancel_down', 500 );
		$this->make_configured_stripe()->record_checkout_session( $token, 'cs_cancel_down', time() + HOUR_IN_SECONDS );

		$this->drive_payment_cancel( $token, new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$this->assertSame( 'cancel', get_post_status( $attendee_id ) );
	}

	/**
	 * Reload CampTix's cached options, since the test's option change was rolled back.
	 */
	public function tear_down() {
		global $camptix;

		parent::tear_down();
		$camptix->load_options();
	}

	/**
	 * A partial refund asks Stripe for the tickets' amount. The last one asks for no
	 * amount, so Stripe refunds whatever is left of the charge.
	 *
	 * @dataProvider data_partial_refund_amount
	 *
	 * @covers CampTix_Payment_Method_Stripe::payment_refund_partial
	 * @covers CampTix_Stripe_API_Client::request_refund
	 *
	 * @param float    $amount            The amount to refund.
	 * @param bool     $refunds_remainder Whether this refunds the rest of the order.
	 * @param int|null $expected          The amount sent to Stripe, in cents.
	 */
	public function test_partial_refund_amount( $amount, $refunds_remainder, $expected ) {
		$stripe = $this->make_configured_stripe();
		list( $token, $attendee ) = $this->make_stripe_order( 'tok_partial_refund_' . (int) $refunds_remainder, 30 );
		update_post_meta( $attendee, 'tix_transaction_id', 'ch_test' );

		$sent = null;
		$stub = function ( $pre, $args ) use ( &$sent ) {
			$sent = $args['body'];

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => wp_json_encode( array(
					'id'     => 're_test',
					'charge' => 'ch_test',
				) ),
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		try {
			$result = $stripe->payment_refund_partial( $token, $amount, $refunds_remainder );
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
		}

		$this->assertSame( CampTix_Plugin::PAYMENT_STATUS_REFUNDED, $result['status'] );
		$this->assertSame( 're_test', $result['refund_transaction_id'] );
		$this->assertSame( 'ch_test', $sent['charge'] );
		$this->assertSame( $expected, $sent['amount'] ?? null );
	}

	/**
	 * @return array
	 */
	public function data_partial_refund_amount() {
		return array(
			'some tickets'        => array( 10, false, 1000 ),
			'the rest of the order' => array( 20, true, null ),
		);
	}

	/**
	 * A refused partial refund is reported as failed, not refunded.
	 *
	 * @covers CampTix_Payment_Method_Stripe::payment_refund_partial
	 */
	public function test_partial_refund_reports_a_refused_refund() {
		$stripe = $this->make_configured_stripe();
		list( $token, $attendee ) = $this->make_stripe_order( 'tok_partial_refused', 30 );
		update_post_meta( $attendee, 'tix_transaction_id', 'ch_test' );

		$stub = function () {
			return array(
				'response' => array(
					'code'    => 400,
					'message' => 'Bad Request',
				),
				'body'     => wp_json_encode( array(
					'error' => array(
						'type'    => 'invalid_request_error',
						'message' => 'Refund amount is greater than unrefunded amount on charge.',
					),
				) ),
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		try {
			$result = $stripe->payment_refund_partial( $token, 40, false );
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
		}

		$this->assertSame( CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED, $result['status'] );
	}
}
