<?php

defined( 'WPINC' ) || die();

require_once WP_PLUGIN_DIR . '/camptix/tests/trait-wordcamp-root-blog.php';

/**
 * Covers the binding between a CampTix order and the Instamojo payment it is settled
 * by: only a payment Instamojo confirms was credited against the payment request
 * created for that order, for at least that order's total, may settle it.
 *
 * @covers CampTix_Payment_Method_Instamojo
 */
class Test_CampTix_Payment_Instamojo extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	const SALT = 'instamojo_test_salt';
	const SITE = 'https://example.test/2026/';

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
	 * Clears the request superglobals the gateway reads.
	 */
	public function tear_down() {
		$_POST = array();
		unset( $_GET['tix_payment_token'], $_REQUEST['tix_payment_token'], $_REQUEST['payment_request_id'] );

		parent::tear_down();
	}

	/**
	 * An Instamojo gateway with credentials loaded and the currency set to INR.
	 *
	 * @param string $salt Override the merchant salt, e.g. '' for an unconfigured site.
	 *
	 * @return CampTix_Payment_Method_Instamojo
	 */
	protected function make_configured_gateway( $salt = self::SALT ) {
		$gateway = new CampTix_Payment_Method_Instamojo();

		$gateway->camptix_options['currency'] = 'INR';

		$options = new ReflectionProperty( 'CampTix_Payment_Method_Instamojo', 'options' );
		$options->setAccessible( true );
		$options->setValue(
			$gateway,
			array(
				'Instamojo-Api-Key'    => 'key',
				'Instamojo-Auth-Token' => 'token',
				'Instamojo-salt'       => $salt,
				'sandbox'              => false,
			)
		);

		return $gateway;
	}

	/**
	 * Build a paid-for CampTix order in draft.
	 *
	 * @param string $token
	 * @param float  $total
	 *
	 * @return int The attendee post ID.
	 */
	protected function make_order( $token, $total ) {
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
		update_post_meta( $attendee, 'tix_order_total', (float) $total );
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

		return $attendee;
	}

	/**
	 * An Instamojo payment request as the API returns it, echoing back the redirect
	 * and webhook URLs checkout supplied -- which is where the order binding lives.
	 *
	 * @param string $payment_request_id
	 * @param string $owner_token        The order the request was created for.
	 * @param array  $payments           Payment entries, each [ status, amount ].
	 *
	 * @return array
	 */
	protected function payment_request( $payment_request_id, $owner_token, $payments = array() ) {
		$entries = array();

		foreach ( $payments as $i => $payment ) {
			$entries[] = array(
				'payment_id' => 'MOJO_' . $payment_request_id . '_' . $i,
				'status'     => $payment[0],
				'amount'     => $payment[1],
			);
		}

		return array(
			'success'         => true,
			'payment_request' => array(
				'id'           => $payment_request_id,
				'status'       => $entries ? 'Completed' : 'Pending',
				'redirect_url' => self::SITE . '?tix_action=payment_return&tix_payment_token=' . $owner_token . '&tix_payment_method=instamojo',
				'webhook'      => self::SITE . '?tix_action=payment_notify&tix_payment_token=' . $owner_token . '&tix_payment_method=instamojo',
				'payments'     => $entries,
			),
		);
	}

	/**
	 * Sign a webhook body the way Instamojo does: sha1 HMAC of the values, sorted by
	 * key, joined with a pipe, under the merchant salt.
	 *
	 * @param array  $body
	 * @param string $salt
	 *
	 * @return array The body with its `mac` added.
	 */
	protected function sign( $body, $salt = self::SALT ) {
		$data = $body;
		ksort( $data, SORT_STRING | SORT_FLAG_CASE );

		$body['mac'] = hash_hmac( 'sha1', implode( '|', $data ), $salt );

		return $body;
	}

	/**
	 * Stub the Instamojo API, make wp_die() catchable, and run $callback.
	 *
	 * @param array|null $api_response
	 * @param callable   $callback
	 *
	 * @return string|null The wp_die() message, or null if it did not die.
	 */
	protected function with_instamojo( $api_response, $callback ) {
		$http_stub = function () use ( $api_response ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(),
				'body'     => wp_json_encode( $api_response ),
			);
		};

		$die_handler = function () {
			return function ( $message ) {
				throw new Exception( esc_html( 'wp_die: ' . ( is_wp_error( $message ) ? $message->get_error_message() : $message ) ) );
			};
		};

		// A return ends in a redirect and exit; throwing from the filter unwinds first.
		$stop_redirect = function () {
			throw new Exception( 'redirected to the ticket page' );
		};

		if ( null !== $api_response ) {
			add_filter( 'pre_http_request', $http_stub, 10, 3 );
		}

		add_filter( 'wp_die_handler', $die_handler );
		add_filter( 'wp_redirect', $stop_redirect );

		$died = null;

		try {
			$callback();
		} catch ( Exception $e ) {
			$died = $e->getMessage();
		} finally {
			remove_filter( 'pre_http_request', $http_stub, 10 );
			remove_filter( 'wp_die_handler', $die_handler );
			remove_filter( 'wp_redirect', $stop_redirect );
		}

		return $died;
	}

	/**
	 * Drive payment_notify() with a POST body and a token in the URL.
	 *
	 * @param CampTix_Payment_Method_Instamojo $gateway
	 * @param array                            $post
	 * @param string                           $url_token
	 * @param array|null                       $api_response
	 *
	 * @return string|null The wp_die() message, or null if it did not die.
	 */
	protected function drive_notify( $gateway, $post, $url_token, $api_response = null ) {
		$_POST                         = $post;
		$_GET['tix_payment_token']     = $url_token;
		$_REQUEST['tix_payment_token'] = $url_token;

		return $this->with_instamojo(
			$api_response,
			function () use ( $gateway ) {
				$gateway->payment_notify();
			}
		);
	}

	/**
	 * Drive payment_return().
	 *
	 * Only for paths expected to wp_die(); a confirmed return redirects and calls
	 * die(), which a test process cannot survive.
	 *
	 * @param CampTix_Payment_Method_Instamojo $gateway
	 * @param string                           $url_token
	 * @param string                           $payment_request_id
	 * @param array|null                       $api_response
	 *
	 * @return string|null The wp_die() message, or null if it did not die.
	 */
	protected function drive_return( $gateway, $url_token, $payment_request_id, $api_response = null ) {
		$_REQUEST['tix_payment_token']  = $url_token;
		$_GET['tix_payment_token']      = $url_token;
		$_REQUEST['payment_request_id'] = $payment_request_id;

		return $this->with_instamojo(
			$api_response,
			function () use ( $gateway ) {
				$gateway->payment_return();
			}
		);
	}

	/**
	 * A signed webhook for the order's own payment request, credited for the order's
	 * total, completes it.
	 */
	public function test_notify_completes_the_order_that_owns_the_payment_request() {
		$gateway  = $this->make_configured_gateway();
		$attendee = $this->make_order( 'tok_ok', 500 );

		$body = $this->sign(
			array(
				'payment_request_id' => 'req_ok',
				'payment_id'         => 'MOJO_ok',
				'status'             => 'Credit',
				'amount'             => '500.00',
			)
		);

		$this->drive_notify( $gateway, $body, 'tok_ok', $this->payment_request( 'req_ok', 'tok_ok', array( array( 'Credit', '500.00' ) ) ) );

		$this->assertSame( 'publish', get_post_status( $attendee ) );
	}

	/**
	 * The signature covers the POST body, so the token in the query string is not part
	 * of what Instamojo signed. A signed Credit settles the order that owns its
	 * payment request, whatever token the URL carries.
	 */
	public function test_notify_will_not_settle_an_order_that_does_not_own_the_payment_request() {
		$gateway = $this->make_configured_gateway();
		$large   = $this->make_order( 'tok_large', 5000 );

		// A genuine, correctly signed Credit belonging to a different, cheaper order.
		$body = $this->sign(
			array(
				'payment_request_id' => 'req_small',
				'payment_id'         => 'MOJO_small',
				'status'             => 'Credit',
				'amount'             => '1.00',
			)
		);

		$died = $this->drive_notify( $gateway, $body, 'tok_large', $this->payment_request( 'req_small', 'tok_small', array( array( 'Credit', '1.00' ) ) ) );

		$this->assertNotNull( $died, 'payment_notify() should have failed closed.' );
		$this->assertSame( 'draft', get_post_status( $large ) );
	}

	/**
	 * A webhook whose signature does not verify establishes nothing about an order and
	 * must leave it exactly as it was.
	 */
	public function test_notify_with_an_invalid_signature_does_not_touch_the_order() {
		$gateway  = $this->make_configured_gateway();
		$attendee = $this->make_order( 'tok_badmac', 500 );

		$died = $this->drive_notify(
			$gateway,
			array(
				'payment_request_id' => 'req_badmac',
				'status'             => 'Credit',
				'amount'             => '500.00',
				'mac'                => 'not-a-real-signature',
			),
			'tok_badmac'
		);

		$this->assertNotNull( $died );
		$this->assertSame( 'draft', get_post_status( $attendee ) );
	}

	/**
	 * A webhook with no signature at all is treated the same way.
	 */
	public function test_notify_with_no_signature_does_not_touch_the_order() {
		$gateway  = $this->make_configured_gateway();
		$attendee = $this->make_order( 'tok_nomac', 500 );

		$died = $this->drive_notify(
			$gateway,
			array(
				'payment_request_id' => 'req_nomac',
				'status'             => 'Credit',
				'amount'             => '500.00',
			),
			'tok_nomac'
		);

		$this->assertNotNull( $died );
		$this->assertSame( 'draft', get_post_status( $attendee ) );
	}

	/**
	 * A site with no salt cannot verify anything: hash_hmac() returns a value for an
	 * empty key, but it rests on no secret. Such a webhook is refused, even though the
	 * signature it carries is the one the gateway would compute.
	 */
	public function test_notify_refuses_when_no_salt_is_configured() {
		$gateway  = $this->make_configured_gateway( '' );
		$attendee = $this->make_order( 'tok_nosalt', 500 );

		$body = $this->sign(
			array(
				'payment_request_id' => 'req_nosalt',
				'payment_id'         => 'MOJO_nosalt',
				'status'             => 'Credit',
				'amount'             => '500.00',
			),
			''
		);

		$died = $this->drive_notify( $gateway, $body, 'tok_nosalt', $this->payment_request( 'req_nosalt', 'tok_nosalt', array( array( 'Credit', '500.00' ) ) ) );

		$this->assertNotNull( $died );
		$this->assertSame( 'draft', get_post_status( $attendee ) );
	}

	/**
	 * Only a `Credit` payment means money reached the merchant. A request carrying a
	 * failed attempt for the full amount must not complete the order, even though the
	 * amount on that attempt would satisfy the total.
	 */
	public function test_notify_does_not_complete_on_a_failed_payment_attempt() {
		$gateway  = $this->make_configured_gateway();
		$attendee = $this->make_order( 'tok_declined', 500 );

		$body = $this->sign(
			array(
				'payment_request_id' => 'req_declined',
				'payment_id'         => 'MOJO_declined',
				'status'             => 'Failed',
				'amount'             => '500.00',
			)
		);

		$this->drive_notify( $gateway, $body, 'tok_declined', $this->payment_request( 'req_declined', 'tok_declined', array( array( 'Failed', '500.00' ) ) ) );

		$this->assertNotSame( 'publish', get_post_status( $attendee ) );
	}

	/**
	 * A return for an order Instamojo confirms was never credited must not move it.
	 * Pending is a settled state, so it is not a safe default for an unpaid order.
	 */
	public function test_return_does_not_settle_when_nothing_was_paid() {
		$gateway  = $this->make_configured_gateway();
		$attendee = $this->make_order( 'tok_unpaid', 500 );

		$this->drive_return( $gateway, 'tok_unpaid', 'req_unpaid', $this->payment_request( 'req_unpaid', 'tok_unpaid' ) );

		$this->assertSame( 'draft', get_post_status( $attendee ) );
	}

	/**
	 * A return naming a payment request that belongs to a different order settles
	 * nothing, even though that request was genuinely paid.
	 */
	public function test_return_does_not_settle_from_another_orders_payment_request() {
		$gateway  = $this->make_configured_gateway();
		$attendee = $this->make_order( 'tok_theirs', 5000 );

		$this->drive_return( $gateway, 'tok_theirs', 'req_mine', $this->payment_request( 'req_mine', 'tok_mine', array( array( 'Credit', '5000.00' ) ) ) );

		$this->assertSame( 'draft', get_post_status( $attendee ) );
	}

	/**
	 * A return Instamojo does confirm still records the payment, so an order survives
	 * a webhook that never arrives.
	 */
	public function test_return_settles_a_confirmed_payment() {
		$gateway  = $this->make_configured_gateway();
		$attendee = $this->make_order( 'tok_conf', 500 );

		$this->drive_return( $gateway, 'tok_conf', 'req_conf', $this->payment_request( 'req_conf', 'tok_conf', array( array( 'Credit', '500.00' ) ) ) );

		$this->assertSame( 'pending', get_post_status( $attendee ) );
	}

	/**
	 * The binding is read out of the URLs Instamojo echoes back, so it holds for every
	 * order ever created, and rejects a request belonging to any other.
	 */
	public function test_payment_request_owns_token_reads_the_echoed_urls() {
		$gateway = $this->make_configured_gateway();
		$method  = new ReflectionMethod( 'CampTix_Payment_Method_Instamojo', 'payment_request_owns_token' );
		$method->setAccessible( true );

		$request = json_decode( wp_json_encode( $this->payment_request( 'req_x', 'tok_mine' )['payment_request'] ) );

		$this->assertTrue( $method->invoke( $gateway, $request, 'tok_mine' ) );
		$this->assertFalse( $method->invoke( $gateway, $request, 'tok_theirs' ) );
		$this->assertFalse( $method->invoke( $gateway, $request, '' ) );
		$this->assertFalse( $method->invoke( $gateway, false, 'tok_mine' ) );
	}
}
