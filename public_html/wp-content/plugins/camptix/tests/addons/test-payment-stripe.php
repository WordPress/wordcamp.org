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
}
