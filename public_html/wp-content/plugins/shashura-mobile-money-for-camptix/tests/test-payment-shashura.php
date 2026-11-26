<?php
/**
 * Integration tests for Shashura Mobile Money for CampTix.
 *
 * @package CampTix_Shashura
 */

require_once dirname( __DIR__, 2 ) . '/camptix/tests/trait-wordcamp-root-blog.php';

/**
 * Exercise Shashura phone helpers, API client, webhook settlement, refunds, timeout, and privacy tools.
 *
 * @covers Shasmomo_Phone
 * @covers Shasmomo_API
 * @covers Shasmomo_Payment_Method
 */
class Test_Camptix_Payment_Shashura extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/**
	 * Provision the WordCamp root blog for attendee post hooks.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::create_wordcamp_root_blog( $factory );
	}

	/**
	 * Remove the shared root blog after the suite.
	 */
	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	/**
	 * Original CampTix global.
	 *
	 * @var CampTix_Plugin
	 */
	private $original_camptix;

	/**
	 * Original REST server global.
	 *
	 * @var WP_REST_Server|null
	 */
	private $original_rest_server;

	/**
	 * Gateway instance under test.
	 *
	 * @var Shasmomo_Payment_Method
	 */
	private $gateway;

	/**
	 * Payment token for the current test order.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * HTTP mock callback.
	 *
	 * @var callable|null
	 */
	private $http_handler;

	/**
	 * Captured wp_mail calls.
	 *
	 * @var array
	 */
	private $emails = array();

	/**
	 * Configure an isolated UGX CampTix instance with mocked HTTP transport.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_camptix     = $GLOBALS['camptix'];
		$this->original_rest_server = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server']  = null;
		$GLOBALS['camptix']         = clone $this->original_camptix;
		$this->token                = bin2hex( random_bytes( 16 ) );
		$this->emails               = array();
		$this->http_handler         = null;

		$options = array_merge(
			$GLOBALS['camptix']->get_options(),
			array(
				'currency'                 => 'UGX',
				'payment_methods'          => array( 'shashura' => 1 ),
				'payment_options_shashura' => array(
					'client_id'      => 'TID_test_client',
					'client_secret'  => 'PSK_test_secret',
					'environment'    => 'sandbox',
					'webhook_secret' => 'whsec_test_secret',
					'name_display'   => 'partial',
					'ip_source'      => 'remote_addr',
					'proxy_hops'     => 1,
					'show_branding'  => false,
					'last_check'     => '',
				),
			)
		);
		( new ReflectionProperty( CampTix_Plugin::class, 'options' ) )->setValue( $GLOBALS['camptix'], $options );

		$this->gateway = new Shasmomo_Payment_Method();
		$this->gateway->camptix_init();

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Restore globals and transients after each test.
	 */
	public function tear_down() {
		$GLOBALS['camptix']        = $this->original_camptix;
		$GLOBALS['wp_rest_server'] = $this->original_rest_server;
		delete_transient( 'shasmomo_ids_' . $this->token );
		parent::tear_down();
	}

	/**
	 * Intercept all outbound HTTP requests.
	 *
	 * @param false|array|WP_Error $preempt Existing preemptive response.
	 * @param array                $args    HTTP request arguments.
	 * @param string               $url     Request URL.
	 * @return array|WP_Error
	 */
	public function intercept_http( $preempt, $args, $url ) {
		if ( str_ends_with( $url, '/auth' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'access_token' => 'tok_test_123',
							'expires_in'   => 3600,
						),
					)
				),
			);
		}
		if ( is_callable( $this->http_handler ) ) {
			return call_user_func( $this->http_handler, $args, $url );
		}
		return new WP_Error( 'unexpected_http', 'Unexpected HTTP call: ' . $url );
	}

	/**
	 * Capture outgoing emails without invoking mail transport.
	 *
	 * @param null|bool $short_circuit Short-circuit value.
	 * @param array     $atts          Email arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ) {
		$this->emails[] = $atts;
		return true;
	}

	/**
	 * Create a draft CampTix attendee order for Shashura.
	 *
	 * @param int   $total    Order total in UGX.
	 * @param array $attempts Attempt records to store on the order.
	 * @return int Attendee post ID.
	 */
	private function create_order( $total, $attempts = array() ) {
		$ticket_id = self::factory()->post->create(
			array(
				'post_type'   => 'tix_ticket',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $ticket_id, 'tix_price', $total );
		update_post_meta( $ticket_id, 'tix_available', 10 );

		$attendee_id = self::factory()->post->create(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $attendee_id, 'tix_ticket_id', $ticket_id );
		update_post_meta( $attendee_id, 'tix_payment_token', $this->token );
		update_post_meta( $attendee_id, 'tix_payment_method', 'shashura' );
		update_post_meta( $attendee_id, 'tix_access_token', bin2hex( random_bytes( 16 ) ) );
		update_post_meta( $attendee_id, 'tix_email', 'buyer@example.org' );
		update_post_meta( $attendee_id, 'tix_timestamp', time() );
		update_post_meta(
			$attendee_id,
			'tix_order',
			array(
				'total' => $total,
				'items' => array(
					array(
						'id'       => $ticket_id,
						'name'     => 'Standard Ticket',
						'price'    => $total,
						'quantity' => 1,
					),
				),
			)
		);

		if ( $attempts ) {
			update_post_meta( $attendee_id, '_shashura_attempts', wp_slash( $attempts ) );
			foreach ( $attempts as $attempt ) {
				add_post_meta( $attendee_id, '_shashura_ref', $attempt['ref'] );
			}
		}

		return $attendee_id;
	}

	/**
	 * Sign a webhook payload with the configured secret.
	 *
	 * @param string $raw Raw JSON body.
	 * @return string
	 */
	private function sign_webhook( $raw ) {
		return hash_hmac( 'sha256', $raw, 'whsec_test_secret' );
	}

	/**
	 * Verify Uganda mobile number normalization, formatting, and masking.
	 */
	public function test_phone_helpers() {
		$this->assertSame( '256772123456', Shasmomo_Phone::normalize( '0772 123 456' ) );
		$this->assertSame( '256772123456', Shasmomo_Phone::normalize( '+256 772 123456' ) );
		$this->assertSame( '256701234567', Shasmomo_Phone::normalize( '701234567' ) );
		$this->assertSame( '', Shasmomo_Phone::normalize( '0414123456' ) );
		$this->assertSame( '', Shasmomo_Phone::normalize( '1234567890123456' ) );
		$this->assertSame( '+256 772 123 456', Shasmomo_Phone::pretty( '256772123456' ) );
		$this->assertSame( '+256 77* *** 456', Shasmomo_Phone::mask( '256772123456' ) );
	}

	/**
	 * Verify API endpoint validation and webhook HMAC verification.
	 */
	public function test_api_base_url_guard_and_hmac() {
		$this->assertTrue( Shasmomo_API::is_allowed_base_url( 'https://shashura.com/api/v1' ) );
		$this->assertFalse( Shasmomo_API::is_allowed_base_url( 'http://shashura.com/api/v1' ) );
		$this->assertFalse( Shasmomo_API::is_allowed_base_url( 'https://user:pass@shashura.com/api/v1' ) );
		$this->assertFalse( Shasmomo_API::is_allowed_base_url( 'https://shashura.com/api/v1?foo=bar' ) );
		$this->assertFalse( Shasmomo_API::is_allowed_base_url( 'https://shashura.com:8443/api/v1' ) );

		$api = new Shasmomo_API( 'TID_test', 'PSK_test', 'sandbox', 'whsec_test_secret' );
		$raw = '{"event":"collection.completed"}';
		$sig = hash_hmac( 'sha256', $raw, 'whsec_test_secret' );

		$this->assertTrue( $api->verify_signature( $raw, $sig ) );
		$this->assertFalse( $api->verify_signature( $raw, 'invalid' ) );
	}

	/**
	 * Verify UGX currency exposure via CampTix currencies.
	 */
	public function test_ugx_currency_is_available() {
		$currencies = CampTix_Currencies::get_currencies();
		$this->assertArrayHasKey( 'UGX', $currencies );
		$this->assertSame( 0, $currencies['UGX']['decimal_point'] );
	}

	/**
	 * Verify webhook signature checks and settlement via /enquiry.
	 */
	public function test_webhook_rejects_bad_signature_and_settles_verified_payment() {
		$attendee_id = $this->create_order(
			50000,
			array(
				array(
					'ref'     => 'CTXTEST1234567890',
					'tid'     => 'SHASHURA_TID_1',
					'msisdn'  => '256772123456',
					'name'    => 'Mugisha Samuel',
					'network' => 'MTN',
					'amount'  => 50000,
					'env'     => 'sandbox',
					'status'  => 'sent',
					'created' => time(),
					'checked' => 0,
				),
			)
		);

		$body = wp_json_encode(
			array(
				'type'         => 'COLLECTION',
				'environment'  => 'sandbox',
				'external_ref' => 'CTXTEST1234567890',
				'status'       => 'TS',
			)
		);

		$bad_req = new WP_REST_Request( 'POST', '/shasmomo/v1/webhook' );
		$bad_req->set_body( $body );
		$bad_req->set_header( 'x_shashura_signature', 'bad' );
		$bad_res = $this->gateway->rest_webhook( $bad_req );
		$this->assertSame( 401, $bad_res->get_status() );

		$this->http_handler = function ( $args, $url ) {
			$this->assertStringEndsWith( '/enquiry/CTXTEST1234567890', $url );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'state'        => 'TS',
							'amount'       => 50000,
							'external_ref' => 'TELCO_REF_IGNORED',
							'tid'          => 'SHASHURA_TID_1',
							'network_ref'  => 'MTN_123',
						),
					)
				),
			);
		};

		$ok_req = new WP_REST_Request( 'POST', '/shasmomo/v1/webhook' );
		$ok_req->set_body( $body );
		$ok_req->set_header( 'x_shashura_signature', $this->sign_webhook( $body ) );
		$ok_res = $this->gateway->rest_webhook( $ok_req );

		$this->assertSame( 200, $ok_res->get_status() );
		$this->assertSame( 'paid', $ok_res->get_data()['state'] );
		$this->assertSame( 'publish', get_post_status( $attendee_id ) );
		$this->assertSame( 'SHASHURA_TID_1', get_post_meta( $attendee_id, 'tix_transaction_id', true ) );

		$attempts = get_post_meta( $attendee_id, '_shashura_attempts', true );
		$this->assertSame( '', $attempts[0]['msisdn'] );
		$this->assertSame( '+256 77* *** 456', $attempts[0]['msisdn_masked'] );
	}

	/**
	 * Verify amount mismatch holds order for reconciliation without completing it.
	 */
	public function test_amount_mismatch_triggers_reconciliation_hold() {
		$attendee_id = $this->create_order(
			50000,
			array(
				array(
					'ref'     => 'CTXMISMATCH12345',
					'tid'     => 'SHASHURA_TID_MISMATCH',
					'msisdn'  => '256772123456',
					'name'    => 'Mugisha Samuel',
					'network' => 'MTN',
					'amount'  => 50000,
					'env'     => 'sandbox',
					'status'  => 'sent',
					'created' => time(),
					'checked' => 0,
				),
			)
		);

		$this->http_handler = function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'state'  => 'TS',
							'amount' => 45000,
							'tid'    => 'SHASHURA_TID_MISMATCH',
						),
					)
				),
			);
		};

		$body = wp_json_encode(
			array(
				'type'         => 'COLLECTION',
				'environment'  => 'sandbox',
				'external_ref' => 'CTXMISMATCH12345',
			)
		);
		$req  = new WP_REST_Request( 'POST', '/shasmomo/v1/webhook' );
		$req->set_body( $body );
		$req->set_header( 'x_shashura_signature', $this->sign_webhook( $body ) );
		$res = $this->gateway->rest_webhook( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'reconciliation', $res->get_data()['state'] );
		$this->assertSame( 'draft', get_post_status( $attendee_id ) );
		$this->assertCount( 1, $this->emails );
		$this->assertStringContainsString( 'reconciliation', $this->emails[0]['subject'] );
	}

	/**
	 * Verify pending refund payout sets a hold that clears on async webhook confirmation.
	 */
	public function test_pending_refund_payout_clears_via_webhook() {
		$attendee_id = $this->create_order( 50000 );
		wp_update_post(
			array(
				'ID'          => $attendee_id,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $attendee_id, 'tix_transaction_id', 'SHASHURA_TID_REFUND' );
		update_post_meta( $attendee_id, 'tix_transaction_details', array() );

		$this->http_handler = function ( $args, $url ) {
			$this->assertStringEndsWith( '/refund', $url );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'amount_refunded'  => 50000,
							'refunded_total'   => 50000,
							'refundable_total' => 0,
							'fully_refunded'   => true,
							'refund_ref'       => 'REF_PENDING_1',
							'payout'           => array(
								'status'  => 'PENDING',
								'ref'     => 'PAYOUT_1',
								'message' => 'Queued with MTN',
							),
						),
					)
				),
			);
		};

		$refund = $this->gateway->send_refund_request( $this->token, null, 'full' );
		$this->assertSame( CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED, $refund['status'] );
		$this->assertNotEmpty( get_post_meta( $attendee_id, '_shashura_refund_hold', true ) );

		$webhook_body = wp_json_encode(
			array(
				'event'           => 'transaction.refunded',
				'environment'     => 'sandbox',
				'tid'             => 'SHASHURA_TID_REFUND',
				'refund_ref'      => 'REF_PENDING_1',
				'amount_refunded' => 50000,
				'payout'          => array(
					'status' => 'TS',
					'ref'    => 'PAYOUT_1',
				),
			)
		);

		$req = new WP_REST_Request( 'POST', '/shasmomo/v1/webhook' );
		$req->set_body( $webhook_body );
		$req->set_header( 'x_shashura_signature', $this->sign_webhook( $webhook_body ) );
		$res = $this->gateway->rest_webhook( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'refunded', $res->get_data()['state'] );
		$this->assertSame( '', get_post_meta( $attendee_id, '_shashura_refund_hold', true ) );
		$this->assertSame( 'refund', get_post_status( $attendee_id ) );
	}

	/**
	 * Verify privacy exporter and eraser redact PII while preserving accounting fields.
	 */
	public function test_privacy_export_and_erase() {
		$attendee_id = $this->create_order(
			25000,
			array(
				array(
					'ref'     => 'CTXPRIVACY123456',
					'tid'     => 'SHASHURA_TID_PRIV',
					'msisdn'  => '256772123456',
					'name'    => 'Mugisha Samuel',
					'network' => 'MTN',
					'amount'  => 25000,
					'env'     => 'sandbox',
					'status'  => 'paid',
					'created' => time(),
					'checked' => time(),
				),
			)
		);

		$export = $this->gateway->privacy_export( 'buyer@example.org' );
		$this->assertCount( 1, $export['data'] );

		$erase = $this->gateway->privacy_erase( 'buyer@example.org' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertTrue( $erase['items_retained'] );

		$attempts = get_post_meta( $attendee_id, '_shashura_attempts', true );
		$this->assertSame( '', $attempts[0]['msisdn'] );
		$this->assertSame( '', $attempts[0]['msisdn_masked'] );
		$this->assertSame( '', $attempts[0]['name'] );
		$this->assertSame( 'CTXPRIVACY123456', $attempts[0]['ref'] );
		$this->assertSame( 25000, $attempts[0]['amount'] );
	}
}
