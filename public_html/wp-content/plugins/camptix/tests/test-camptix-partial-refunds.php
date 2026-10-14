<?php

defined( 'WPINC' ) || die();

require_once __DIR__ . '/trait-wordcamp-root-blog.php';

/**
 * Refunding some of the tickets of a multi-ticket order, through the refund form.
 *
 * @covers CampTix_Plugin::form_refund_request
 */
class Test_CampTix_Partial_Refunds extends \WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * A gateway that records the refunds it is asked for.
	 *
	 * @var object
	 */
	protected $gateway;

	/**
	 * CampTix log messages, keyed by attendee ID.
	 *
	 * @var array
	 */
	protected $logs = array();

	/**
	 * The $data of each camptix_payment_result action.
	 *
	 * @var array
	 */
	protected $results = array();

	/**
	 * Recipients of the e-mails CampTix sent.
	 *
	 * @var string[]
	 */
	protected $mailed = array();

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
	 * Log in as an organizer, on a tickets page, with a stub gateway and spies.
	 */
	public function set_up() {
		global $post;

		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post               = self::factory()->post->get_object_by_id( self::factory()->post->create( array( 'post_content' => '[camptix]' ) ) );
		$_GET['tix_action'] = 'refund_request';

		// template_redirect sets this on a real tickets page, and the form writes it back to the page.
		$this->set_shortcode_str( '[camptix]' );

		$this->gateway = new class() {
			public $supported_features = array(
				'refund-single'  => true,
				'refund-partial' => true,
			);
			public $status             = CampTix_Plugin::PAYMENT_STATUS_REFUNDED;
			public $partial_refunds    = array();
			public $full_refunds       = array();

			/**
			 * @param string $feature
			 *
			 * @return bool
			 */
			public function supports_feature( $feature ) {
				return ! empty( $this->supported_features[ $feature ] );
			}

			/**
			 * Record the refund, and answer with the stubbed status.
			 *
			 * @param string $payment_token
			 * @param float  $amount
			 * @param bool   $refunds_remainder
			 *
			 * @return array
			 */
			public function payment_refund_partial( $payment_token, $amount, $refunds_remainder ) {
				$this->partial_refunds[] = array( $amount, $refunds_remainder );

				return array(
					'status'                     => $this->status,
					'refund_transaction_id'      => 're_' . count( $this->partial_refunds ),
					'refund_transaction_details' => array( 'stub' => true ),
				);
			}

			/**
			 * Record the refund, and refund the whole order.
			 *
			 * @param string $payment_token
			 *
			 * @return int
			 */
			public function payment_refund( $payment_token ) {
				global $camptix;

				$this->full_refunds[] = $payment_token;

				return $camptix->payment_result(
					$payment_token,
					CampTix_Plugin::PAYMENT_STATUS_REFUNDED,
					array(
						'refund_transaction_id'      => 're_full',
						'refund_transaction_details' => array( 'stub' => true ),
					)
				);
			}
		};

		add_filter( 'camptix_get_payment_method_by_id', array( $this, 'resolve_gateway' ), 10, 2 );
		add_action( 'camptix_log_raw', array( $this, 'record_log' ), 10, 2 );
		add_action( 'camptix_payment_result', array( $this, 'record_result' ), 10, 3 );
		add_filter( 'camptix_wp_mail_override', array( $this, 'record_mail' ), 10, 2 );
	}

	/**
	 * Remove the stubs and the request.
	 */
	public function tear_down() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		remove_filter( 'camptix_get_payment_method_by_id', array( $this, 'resolve_gateway' ), 10 );
		remove_action( 'camptix_log_raw', array( $this, 'record_log' ), 10 );
		remove_action( 'camptix_payment_result', array( $this, 'record_result' ), 10 );
		remove_filter( 'camptix_wp_mail_override', array( $this, 'record_mail' ), 10 );

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		delete_option( 'camptix_options' );
		$camptix->load_options();
		$this->set_shortcode_str( null );

		parent::tear_down();
	}

	/**
	 * Set the shortcode CampTix found on the current page.
	 *
	 * @param string|null $shortcode
	 */
	protected function set_shortcode_str( $shortcode ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$property = new ReflectionProperty( $camptix, 'shortcode_str' );
		$property->setAccessible( true );
		$property->setValue( $camptix, $shortcode );
	}

	/**
	 * Answer the stub gateway for its ID.
	 *
	 * @param mixed  $method
	 * @param string $id
	 *
	 * @return mixed
	 */
	public function resolve_gateway( $method, $id ) {
		return 'stub' === $id ? $this->gateway : $method;
	}

	/**
	 * @param string $message
	 * @param int    $post_id
	 */
	public function record_log( $message, $post_id ) {
		$this->logs[ $post_id ][] = $message;
	}

	/**
	 * @param string $payment_token
	 * @param int    $result
	 * @param array  $data
	 */
	public function record_result( $payment_token, $result, $data ) {
		$this->results[] = $data;
	}

	/**
	 * Record an e-mail instead of sending it.
	 *
	 * @param bool  $override
	 * @param array $mail
	 *
	 * @return bool
	 */
	public function record_mail( $override, $mail ) {
		$this->mailed[] = $mail['to'];

		return true;
	}

	/**
	 * Create a paid order, one attendee per price.
	 *
	 * @param float[] $prices Each ticket's price, after any coupon.
	 * @param string  $token  The order's payment and access token.
	 *
	 * @return int[] The attendee IDs.
	 */
	protected function make_order( $prices, $token = 'tokpartial' ) {
		$ticket    = self::factory()->post->create( array(
			'post_type'   => 'tix_ticket',
			'post_status' => 'publish',
			'post_title'  => 'General Admission',
		) );
		$total     = array_sum( $prices );
		$attendees = array();

		foreach ( $prices as $i => $price ) {
			$attendee = self::factory()->post->create( array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'publish',
			) );

			update_post_meta( $attendee, 'tix_payment_token', $token );
			update_post_meta( $attendee, 'tix_access_token', $token );
			update_post_meta( $attendee, 'tix_ticket_id', $ticket );
			update_post_meta( $attendee, 'tix_ticket_discounted_price', $price );
			update_post_meta( $attendee, 'tix_order_total', $total );
			update_post_meta( $attendee, 'tix_transaction_id', 'ch_' . $token );
			update_post_meta( $attendee, 'tix_transaction_details', array( 'raw' => array() ) );
			update_post_meta( $attendee, 'tix_payment_method', 'stub' );
			update_post_meta( $attendee, 'tix_receipt_email', 'buyer@example.test' );
			update_post_meta( $attendee, 'tix_email', 0 === $i ? 'buyer@example.test' : "guest$i@example.test" );
			update_post_meta( $attendee, 'tix_first_name', 'Attendee' );
			update_post_meta( $attendee, 'tix_last_name', (string) $i );
			update_post_meta(
				$attendee,
				'tix_order',
				array(
					'items' => array(
						array(
							'id'       => $ticket,
							'price'    => $price,
							'quantity' => 1,
						),
					),
					'total' => $total,
				)
			);

			$attendees[] = $attendee;
		}

		return $attendees;
	}

	/**
	 * Render the refund form, submitting it when attendees are given.
	 *
	 * @param string     $token        The order's access token.
	 * @param int[]|null $attendee_ids The ticked tickets, or null to only render the form.
	 *
	 * @return string The form's output.
	 */
	protected function refund_form( $token = 'tokpartial', $attendee_ids = null ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$_REQUEST['tix_access_token'] = $token;

		if ( null !== $attendee_ids ) {
			$_POST = array(
				'tix_refund_request_submit'    => 1,
				'tix_refund_request_reason'    => 'Cannot make it',
				'tix_refund_request_confirmed' => 1,
				'tix_refund_attendee_ids'      => array_map( 'strval', $attendee_ids ),
			);
		}

		$output = $camptix->form_refund_request();
		$_POST  = array();

		return $output;
	}

	/**
	 * Refunding one ticket of three refunds its price and leaves the others paid, and
	 * records the refund the way the revenue report and the action listeners read it.
	 */
	public function test_refunds_only_the_selected_tickets() {
		list( $first, $second, $third ) = $this->make_order( array( 10, 15, 20 ) );

		$output = $this->refund_form( 'tokpartial', array( $second ) );

		$this->assertStringContainsString( 'The selected tickets have been successfully refunded.', $output );
		$this->assertSame( array( array( 15.0, false ) ), $this->gateway->partial_refunds );
		$this->assertSame( array(), $this->gateway->full_refunds );

		$this->assertSame( 'publish', get_post_status( $first ) );
		$this->assertSame( 'refund', get_post_status( $second ) );
		$this->assertSame( 'publish', get_post_status( $third ) );

		$this->assertSame( 're_1', get_post_meta( $second, 'tix_refund_transaction_id', true ) );
		$this->assertEquals( 15, get_post_meta( $second, 'tix_refund_amount', true ) );
		$this->assertSame( 'Cannot make it', get_post_meta( $second, 'tix_refund_reason', true ) );

		// The Ticket Revenue report counts refunds from this exact message.
		$this->assertContains( 'Attendee status has been changed to refund', $this->logs[ $second ] );
		$this->assertNotContains( 'Attendee status has been changed to refund', $this->logs[ $first ] ?? array() );

		$this->assertCount( 1, $this->results );
		$this->assertSame( array( $second ), $this->results[0]['refunded_attendee_ids'] );

		$this->assertEqualsCanonicalizing( array( 'guest1@example.test', 'buyer@example.test' ), $this->mailed );
	}

	/**
	 * Once part of an order was refunded, refunding the rest is a partial refund of
	 * whatever is left. Gateways can't do a full refund then, and the tickets refunded
	 * first keep the record of the refund that covered them.
	 */
	public function test_refunding_the_rest_after_a_partial_refund() {
		list( $first, $second, $third ) = $this->make_order( array( 10, 15, 20 ) );

		$this->refund_form( 'tokpartial', array( $first ) );
		$output = $this->refund_form( 'tokpartial', array( $second, $third ) );

		$this->assertStringContainsString( 'The selected tickets have been successfully refunded.', $output );
		$this->assertSame( array( array( 10.0, false ), array( 35.0, true ) ), $this->gateway->partial_refunds );
		$this->assertSame( array(), $this->gateway->full_refunds );

		$this->assertSame( 're_1', get_post_meta( $first, 'tix_refund_transaction_id', true ) );
		$this->assertSame( 're_2', get_post_meta( $second, 'tix_refund_transaction_id', true ) );
		$this->assertSame( 're_2', get_post_meta( $third, 'tix_refund_transaction_id', true ) );
		$this->assertEquals( 15, get_post_meta( $second, 'tix_refund_amount', true ) );
		$this->assertEquals( 20, get_post_meta( $third, 'tix_refund_amount', true ) );
	}

	/**
	 * The last ticket left on the order has no checkbox, but is still refunded as the
	 * remainder of a partially refunded payment.
	 */
	public function test_refunding_the_last_ticket_after_a_partial_refund() {
		list( $first, $second ) = $this->make_order( array( 10, 15 ) );

		$this->refund_form( 'tokpartial', array( $first ) );

		$form = $this->refund_form();
		$this->assertStringNotContainsString( 'tix_refund_attendee_ids', $form );
		$this->assertStringContainsString( 'data-remainder="15"', $form );

		$this->refund_form( 'tokpartial', array() );

		$this->assertSame( array( array( 10.0, false ), array( 15.0, true ) ), $this->gateway->partial_refunds );
		$this->assertSame( 'refund', get_post_status( $second ) );
	}

	/**
	 * The form offers each ticket with its price, and shows how much is left to refund.
	 */
	public function test_form_shows_tickets_and_the_amount_left() {
		list( $first, $second, $third ) = $this->make_order( array( 10, 15, 20 ) );

		$form = $this->refund_form();

		$this->assertStringContainsString( 'value="' . $first . '" data-price="10"', $form );
		$this->assertStringContainsString( 'value="' . $third . '" data-price="20"', $form );
		$this->assertStringContainsString( 'data-remainder="45"', $form );

		$this->refund_form( 'tokpartial', array( $second ) );

		$form = $this->refund_form();

		$this->assertStringNotContainsString( 'value="' . $second . '"', $form );
		$this->assertStringContainsString( 'data-remainder="30"', $form );
	}

	/**
	 * A ticket that cost nothing is refunded without asking the gateway, which would
	 * read an amount of zero as nothing to refund, or the whole charge.
	 */
	public function test_free_ticket_in_a_paid_order_is_refunded_without_the_gateway() {
		list( $paid, $free ) = $this->make_order( array( 10, 0 ) );

		$this->refund_form( 'tokpartial', array( $free ) );

		$this->assertSame( array(), $this->gateway->partial_refunds );
		$this->assertSame( 'refund', get_post_status( $free ) );
		$this->assertSame( 'no-transaction', get_post_meta( $free, 'tix_refund_transaction_id', true ) );
		$this->assertSame( 'publish', get_post_status( $paid ) );
	}

	/**
	 * A gateway that can't refund part of a payment gets no ticket choice. Its refunds
	 * stay whole, even if a request names some tickets.
	 */
	public function test_gateway_without_partial_refunds_refunds_the_whole_order() {
		$this->gateway->supported_features['refund-partial'] = false;
		list( $first, $second ) = $this->make_order( array( 10, 15 ) );

		$this->assertStringNotContainsString( 'tix_refund_attendee_ids', $this->refund_form() );

		$this->refund_form( 'tokpartial', array( $first ) );

		$this->assertSame( array(), $this->gateway->partial_refunds );
		$this->assertSame( array( 'tokpartial' ), $this->gateway->full_refunds );
		$this->assertSame( 'refund', get_post_status( $first ) );
		$this->assertSame( 'refund', get_post_status( $second ) );
	}

	/**
	 * Unticking every ticket refunds nothing, rather than everything.
	 */
	public function test_no_ticket_selected_refunds_nothing() {
		list( $first, $second ) = $this->make_order( array( 10, 15 ) );

		$output = $this->refund_form( 'tokpartial', array() );

		$this->assertStringContainsString( 'Please select at least one ticket to refund.', $output );
		$this->assertSame( array(), $this->gateway->partial_refunds );
		$this->assertSame( array(), $this->gateway->full_refunds );
		$this->assertSame( 'publish', get_post_status( $first ) );
		$this->assertSame( 'publish', get_post_status( $second ) );
	}

	/**
	 * Tickets from another order can't be refunded through this order's form.
	 */
	public function test_tickets_from_another_order_are_ignored() {
		$this->make_order( array( 10, 15 ) );
		list( $other ) = $this->make_order( array( 10, 15 ), 'tokother' );

		$output = $this->refund_form( 'tokpartial', array( $other ) );

		$this->assertStringContainsString( 'Please select at least one ticket to refund.', $output );
		$this->assertSame( array(), $this->gateway->partial_refunds );
		$this->assertSame( 'publish', get_post_status( $other ) );
	}

	/**
	 * A refund the gateway refuses changes nothing.
	 */
	public function test_refused_partial_refund_changes_nothing() {
		$this->gateway->status = CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED;
		list( $first, $second ) = $this->make_order( array( 10, 15 ) );

		$output = $this->refund_form( 'tokpartial', array( $first ) );

		$this->assertStringContainsString( 'Can not refund the transaction at this time.', $output );
		$this->assertSame( 'publish', get_post_status( $first ) );
		$this->assertSame( 'publish', get_post_status( $second ) );
		$this->assertSame( '', get_post_meta( $first, 'tix_refund_amount', true ) );
		$this->assertSame( array(), $this->results );
	}

	/**
	 * A buyer, not only an organizer, can refund some of their tickets.
	 */
	public function test_buyer_can_refund_some_tickets() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$options                     = get_option( 'camptix_options', array() );
		$options['refunds_enabled']  = true;
		$options['refunds_date_end'] = gmdate( 'Y-m-d', strtotime( '+1 week' ) );
		update_option( 'camptix_options', $options );
		$camptix->load_options();

		list( $first, $second ) = $this->make_order( array( 10, 15 ) );

		$this->refund_form( 'tokpartial', array( $second ) );

		$this->assertSame( array( array( 15.0, false ) ), $this->gateway->partial_refunds );
		$this->assertSame( 'publish', get_post_status( $first ) );
		$this->assertSame( 'refund', get_post_status( $second ) );
	}

	/**
	 * A later refund result for the whole order, such as PayPal's notification once the
	 * last tickets were refunded, keeps each earlier refund's record.
	 *
	 * @covers CampTix_Plugin::payment_result
	 */
	public function test_later_refund_result_keeps_earlier_refund_records() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		list( $first, $second ) = $this->make_order( array( 10, 15 ) );
		$this->refund_form( 'tokpartial', array( $first ) );

		$camptix->payment_result(
			'tokpartial',
			CampTix_Plugin::PAYMENT_STATUS_REFUNDED,
			array(
				'refund_transaction_id'      => 're_later',
				'refund_transaction_details' => array(),
			)
		);

		$this->assertSame( 're_1', get_post_meta( $first, 'tix_refund_transaction_id', true ) );
		$this->assertSame( 're_later', get_post_meta( $second, 'tix_refund_transaction_id', true ) );
		$this->assertSame( 'refund', get_post_status( $second ) );
	}
}
