<?php
/** Mayar contract fixtures are based on public v2 documentation, not live transactions. */

use WordCamp\CampTix\IndonesianPayments\Mayar_Client;
use WordCamp\CampTix\IndonesianPayments\Payment_Method_Mayar;
use WordCamp\CampTix\IndonesianPayments\Payment_Store;
use WordCamp\CampTix\IndonesianPayments\Reconciliation;
use WordCamp\CampTix\IndonesianPayments\Webhook_Controller;

require_once dirname( __DIR__, 2 ) . '/camptix/tests/trait-wordcamp-root-blog.php';

/** Exercise real WordPress persistence and CampTix settlement with intercepted HTTP. */
class Test_Camptix_Payment_Mayar extends WP_UnitTestCase {
	use CampTix_Root_Blog_Fixture;

	/** CampTix's attendee hooks consult the WordCamp root blog. */
	public static function wpSetUpBeforeClass( $factory ) {
		self::create_wordcamp_root_blog( $factory );
	}

	/** Remove the shared root fixture after the suite. */
	public static function wpTearDownAfterClass() {
		self::delete_wordcamp_root_blog();
	}

	private $original_camptix;
	private $gateway;
	private $store;
	private $token;
	private $merchant;
	private $payment_id;
	private $transaction_id;
	private $request_body;
	private $requests;
	private $status;
	private $override;
	private $results;
	private $attendee_ids;
	private $original_rest_server;

	/** Configure an isolated IDR merchant without changing the registered gateway's settings. */
	public function set_up() {
		parent::set_up();
		$this->original_camptix     = $GLOBALS['camptix'];
		$this->original_rest_server = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server']  = null;
		$GLOBALS['camptix']         = clone $this->original_camptix;
		$this->merchant             = wp_generate_uuid4();
		$this->payment_id           = wp_generate_uuid4();
		$this->transaction_id       = wp_generate_uuid4();
		$this->token                = bin2hex( random_bytes( 16 ) );
		$options                    = array_merge(
			$GLOBALS['camptix']->get_options(),
			array(
				'currency'              => 'IDR',
				'payment_methods'       => array( 'mayar' => 1 ),
				'payment_options_mayar' => array(
					'sandbox'                => true,
					'sandbox_api_key'        => 'secret-' . wp_generate_uuid4(),
					'sandbox_merchant_id'    => $this->merchant,
					'production_api_key'     => 'production-secret',
					'production_merchant_id' => wp_generate_uuid4(),
				),
			)
		);
		( new ReflectionProperty( CampTix_Plugin::class, 'options' ) )->setValue( $GLOBALS['camptix'], $options );
		$this->gateway = new Payment_Method_Mayar();
		$this->gateway->load_options();
		$this->store        = new Payment_Store();
		$this->requests     = array();
		$this->request_body = array();
		$this->results      = array();
		$this->status       = 'unpaid';
		$this->override     = null;
		$this->attendee_ids = array();
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
		add_action( 'camptix_payment_result', array( $this, 'record_result' ), 10, 4 );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	/** Restore global state and scheduled events. */
	public function tear_down() {
		wp_clear_scheduled_hook( 'camptix_mayar_reconcile', array( $this->token ) );
		$GLOBALS['camptix']        = $this->original_camptix;
		$GLOBALS['wp_rest_server'] = $this->original_rest_server;
		unset( $_POST['tix_payment_method'] );
		parent::tear_down();
	}

	/** Observe actual core settlement, including whether a notification changes state. */
	public function record_result( $token, $result, $data, $changed ) {
		$this->results[] = compact( 'token', 'result', 'data', 'changed' );
	}

	/** Intercept every HTTP request; no tests can contact Mayar or create a real payment. */
	public function http( $preempt, $args, $url ) {
		$this->requests[] = compact( 'args', 'url' );
		if ( is_callable( $this->override ) ) {
			return call_user_func( $this->override, $args, $url );
		}
		if ( str_ends_with( $url, '/payments/create' ) ) {
			$this->request_body = json_decode( $args['body'], true );
			return $this->response( array(
				'id' => $this->payment_id, 'transactionId' => $this->transaction_id, 'link' => 'https://wordcamp.myr.id/pl/example',
			) );
		}
		if ( str_contains( $url, '/transactions/' ) ) {
			return $this->response( $this->transaction() );
		}
		if ( str_ends_with( $url, '/close' ) ) {
			return $this->response( array() );
		}
		if ( str_contains( $url, '/payments/' ) ) {
			return $this->response( $this->payment() );
		}
		return new WP_Error( 'unexpected_http', 'Unexpected HTTP request in a test.' );
	}

	/** Minimal documented v2 envelope. */
	private function response( $data, $status = 200 ) {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array(),
			'body'     => wp_json_encode( array(
				'statusCode' => $status,
				'messages'   => 'success',
				'data'       => $data,
			) ),
		);
	}

	/** Minimal documented transaction shape. */
	private function transaction() {
		$attempt = $this->store->get( $this->token );
		return array(
			'id' => $this->transaction_id, 'amount' => $attempt['amount'], 'status' => $this->status, 'paymentLink' => array( 'id' => $this->payment_id ), 'user' => array( 'id' => $this->merchant ), 'extraData' => array( 'camptix_reference' => $attempt['reference'] ), 'customer' => array( 'email' => 'private@example.org' ),
		);
	}

	/** Minimal documented payment request shape. */
	private function payment() {
		$attempt = $this->store->get( $this->token );
		return array(
			'id' => $this->payment_id, 'type' => 'payment_request', 'userId' => $this->merchant, 'amount' => $attempt['amount'], 'status' => $this->status,
		);
	}

	/** Create real CampTix attendee posts with a discounted, immutable order snapshot. */
	private function order( $amount = 190000, $quantity = 2 ) {
		$ticket = self::factory()->post->create( array(
			'post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'Conference',
		) );
		$order  = array(
			'total' => $amount, 'coupon' => 'DISCOUNT', 'items' => array(
		array(
		'id' => $ticket, 'name' => 'Conference', 'quantity' => $quantity, 'price' => 100000,
			),
		),
		);
		for ( $i = 0; $i < $quantity; ++$i ) {
			$id = self::factory()->post->create( array(
				'post_type' => 'tix_attendee', 'post_status' => 'draft',
			) );
			foreach ( array(
				'tix_payment_token' => $this->token, 'tix_payment_method' => 'mayar', 'tix_order' => $order, 'tix_timestamp' => time(), 'tix_ticket_id' => $ticket, 'tix_email' => 'private@example.org', 'tix_receipt_email' => 'private@example.org', 'tix_first_name' => 'Test', 'tix_last_name' => 'Attendee', 'tix_access_token' => wp_generate_uuid4(),
			) as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}
			$this->attendee_ids[] = $id;
		}
		return $this->gateway->start_payment( $this->token );
	}

	/** A repeated checkout reuses the existing charge and sends whole rupiah, not cents. */
	public function test_discounted_multi_ticket_checkout_is_persisted_and_reused() {
		$attempt = $this->order();
		$this->assertIsArray( $attempt );
		$this->assertSame( 190000, $this->request_body['amount'] );
		$this->assertSame( $attempt['reference'], $this->request_body['extraData']['camptix_reference'] );
		$this->assertArrayNotHasKey( 'redirectUrl', $this->request_body );
		$this->assertStringNotContainsString( $this->token, wp_json_encode( $this->request_body ) );
		$this->assertSame( $attempt, $this->gateway->start_payment( $this->token ) );
		$this->assertCount( 1, $this->requests );
		foreach ( $this->attendee_ids as $id ) {
			$this->assertSame( $attempt['expires_at'], (int) get_post_meta( $id, 'tix_session_expires_at', true ) );
		}
		$this->assertNotFalse( wp_next_scheduled( 'camptix_mayar_reconcile', array( $this->token ) ) );
	}

	/** Whole-rupiah validation rejects fractions, invalid types, and unsafe numeric ranges. */
	public function test_amount_validation() {
		foreach ( array( 0, -1, 100.5, '100.01', '1e5', INF, NAN, array(), true, 9007199254740992 ) as $invalid ) {
			$this->assertSame( 0, Mayar_Client::amount( $invalid ) );
		}
		$this->assertSame( 100000, Mayar_Client::amount( '100000.00' ) );
		$this->assertWPError( $this->order( 100.5 ) );
		$this->assertCount( 0, $this->requests );
	}

	/** Transport uncertainty must not create a second charge. */
	public function test_uncertain_creation_is_not_retried_and_does_not_leak_errors() {
		$this->override = static function () {
			return new WP_Error( 'http_request_failed', 'Bearer private-secret and private@example.org' );
		};
		$error          = $this->order();
		$this->assertWPError( $error );
		$this->assertStringNotContainsString( 'private-secret', $error->get_error_message() );
		$this->assertSame( 'uncertain', $this->store->get( $this->token )['state'] );
		$this->assertWPError( $this->gateway->start_payment( $this->token ) );
		$this->assertCount( 1, $this->requests );
	}

	/** API-verified success settles all tickets once and excludes personal data from stored details. */
	public function test_paid_transaction_completes_once_and_preserves_refunds() {
		$this->order();
		$this->status = 'paid';
		$this->assertSame( 'complete', $this->gateway->verify( $this->token ) );
		$this->assertCount( 1, $this->results );
		foreach ( $this->attendee_ids as $id ) {
			$this->assertSame( 'publish', get_post_status( $id ) );
			$this->assertSame( $this->transaction_id, get_post_meta( $id, 'tix_transaction_id', true ) );
			$this->assertStringNotContainsString( 'private@example.org', wp_json_encode( get_post_meta( $id, 'tix_transaction_details', true ) ) );
		}
		wp_update_post( array(
			'ID' => $this->attendee_ids[0], 'post_status' => 'refund',
		) );
		$this->status = 'unpaid';
		$this->assertSame( 'complete', $this->gateway->verify( $this->token ) );
		$this->assertSame( 'refund', get_post_status( $this->attendee_ids[0] ) );
		$this->assertCount( 1, $this->results );
		$this->assertFalse( wp_next_scheduled( 'camptix_mayar_reconcile', array( $this->token ) ) );
	}

	/** Similar prices and untrusted callback metadata cannot bind a different transaction. */
	public function test_wrong_amount_merchant_association_currency_and_reference_are_rejected() {
		$this->order();
		$this->status = 'paid';
		foreach ( array( 'amount', 'merchant', 'association', 'currency', 'reference' ) as $mismatch ) {
			$this->override = function ( $args, $url ) use ( $mismatch ) {
				if ( str_contains( $url, '/transactions/' ) ) {
					$transaction = $this->transaction();
					switch ( $mismatch ) {
						case 'amount':
							++$transaction['amount'];
							break;
						case 'merchant':
							$transaction['user']['id'] = wp_generate_uuid4();
							break;
						case 'association':
							$transaction['paymentLink']['id'] = wp_generate_uuid4();
							break;
						case 'currency':
							$transaction['currency'] = 'USD';
							break;
						case 'reference':
							$transaction['extraData']['camptix_reference'] = wp_generate_uuid4();
							break;
					}
					return $this->response( $transaction );
				}
				return $this->response( $this->payment() );
			};
			$this->assertWPError( $this->gateway->verify( $this->token ), $mismatch );
		}
		$this->assertCount( 0, $this->results );
		$this->assertSame( 'draft', get_post_status( $this->attendee_ids[0] ) );
	}

	/** Expired/released seats are never restored blindly, even after a verified payment. */
	public function test_late_payment_requires_review_without_issuing_tickets() {
		$this->order();
		$this->status = 'paid';
		foreach ( $this->attendee_ids as $id ) {
			update_post_meta( $id, 'tix_session_expires_at', time() - HOUR_IN_SECONDS );
			wp_update_post( array(
				'ID' => $id, 'post_status' => 'timeout',
			) );
		}
		$this->assertSame( 'review', $this->gateway->verify( $this->token ) );
		$this->assertCount( 0, $this->results );
		$this->assertSame( 'timeout', get_post_status( $this->attendee_ids[0] ) );
		$this->assertSame( 'paid_after_release', get_post_meta( $this->attendee_ids[0], '_camptix_mayar_review', true ) );
	}

	/** An unpaid session is closed and never marked paid. */
	public function test_expired_unpaid_payment_is_closed() {
		$attempt               = $this->order();
		$attempt['expires_at'] = time() - 1;
		$this->store->save( $attempt );
		$this->assertSame( 'expired', $this->gateway->verify( $this->token ) );
		$this->assertStringEndsWith( '/payments/' . $this->payment_id . '/close', end( $this->requests )['url'] );
		$this->assertCount( 0, $this->results );
	}

	/** Mode switching does not switch credentials for outstanding payments. */
	public function test_pending_payment_uses_its_original_environment_after_gateway_is_disabled() {
		$this->order();
		$this->gateway->options['sandbox']                          = false;
		$this->gateway->camptix_options['payment_methods']['mayar'] = 0;
		$this->status = 'paid';
		$this->assertSame( 'complete', $this->gateway->verify( $this->token ) );
		$this->assertStringStartsWith( 'https://api.mayar.io/', end( $this->requests )['url'] );
	}

	/** Changed credentials cannot accidentally verify using a different merchant. */
	public function test_changed_key_leaves_an_actionable_review_marker() {
		$this->order();
		$this->gateway->options['sandbox_api_key'] = 'different-key';
		$this->assertWPError( $this->gateway->verify( $this->token ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'credentials_changed', get_post_meta( $this->attendee_ids[0], '_camptix_mayar_review', true ) );
	}

	/** Attendee timeout checks do not repeat API requests for every ticket in an order. */
	public function test_timeout_hook_deduplicates_order_checks() {
		$this->order();
		foreach ( $this->attendee_ids as $id ) {
			$this->gateway->pre_attendee_timeout( $id );
		}
		$this->assertCount( 3, $this->requests );
	}

	/** Sandbox and production webhook secrets cannot be substituted. */
	public function test_webhook_authentication_and_api_verification() {
		$this->order();
		$this->status = 'paid';
		$controller   = new Webhook_Controller( $this->gateway );
		// Register inside the actual REST action, including when another suite already created a server.
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ), 100 );
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
		$request = new WP_REST_Request( 'POST', '/camptix-mayar/v1/webhook/sandbox' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'event' => 'payment.received',
			'data'  => array(
				'productId' => $this->payment_id,
				'status'    => 'SUCCESS',
				'amount'    => 1,
			),
		) ) );
		$request->set_query_params( array( 'secret' => $this->store->webhook_secret( 'production' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertCount( 1, $this->requests );
		$request->set_query_params( array( 'secret' => $this->store->webhook_secret( 'sandbox' ) ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 'publish', get_post_status( $this->attendee_ids[0] ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertCount( 1, $this->results );
	}

	/** A webhook success assertion alone must never publish tickets. */
	public function test_forged_success_keeps_an_api_unpaid_order_unpaid() {
		$this->order();
		$controller = new Webhook_Controller( $this->gateway );
		$request    = new WP_REST_Request( 'POST' );
		$request->set_url_params( array( 'environment' => 'sandbox' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'event' => 'payment.received',
			'data'  => array(
				'productId' => $this->payment_id,
				'status'    => 'SUCCESS',
			),
		) ) );
		$this->assertSame( 200, $controller->receive( $request )->get_status() );
		$this->assertSame( 'draft', get_post_status( $this->attendee_ids[0] ) );
		$this->assertCount( 0, $this->results );
	}

	/** A transaction created before a transport timeout can be recovered by authenticated correlation. */
	public function test_unknown_create_outcome_can_be_recovered_without_creating_another_payment() {
		$this->override = static function () {
			return new WP_Error( 'timeout', 'Timeout' );
		};
		$this->order();
		$this->override = null;
		$this->status   = 'paid';
		$this->assertSame( 'complete', $this->gateway->verify( $this->token, $this->transaction_id ) );
		$this->assertCount( 3, $this->requests );
	}

	/** Site-scoped indexes reject another blog even if the provider ID or reference is known. */
	public function test_indexes_are_isolated_between_sites() {
		$this->order();
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		try {
			$this->assertSame( array(), $this->store->find( 'sandbox', 'payment_id', $this->payment_id ) );
			$this->assertWPError( $this->gateway->verify( $this->token ) );
		} finally {
			restore_current_blog();
		}
	}

	/** Secret fields do not disclose saved credentials; blank submissions preserve them. */
	public function test_settings_and_checkout_host_validation() {
		ob_start();
		$this->gateway->field_secret( array(
			'name' => 'test', 'value' => 'private-key',
		) );
		$this->assertStringNotContainsString( 'private-key', ob_get_clean() );
		$output = $this->gateway->validate_options( array(
			'sandbox_api_key' => '', 'expiry_minutes' => 1, 'unexpected' => 'discard',
		) );
		$this->assertSame( $this->gateway->options['sandbox_api_key'], $output['sandbox_api_key'] );
		$this->assertSame( 5, $output['expiry_minutes'] );
		$this->assertArrayNotHasKey( 'unexpected', $output );
		foreach ( array( 'http://x.myr.id/pay', 'https://x.myr.id.evil.test/pay', 'https://evil.test/pay', 'https://user@x.myr.id/pay', 'https://x.myr.id:444/pay', array() ) as $url ) {
			$this->assertFalse( Mayar_Client::checkout_url_allowed( $url ) );
		}
		$this->assertTrue( Mayar_Client::checkout_url_allowed( 'https://test.myr.id/pl/example' ) );
	}

	/** A database lock held by a different request prevents double finalization. */
	public function test_a_second_connection_cannot_finalize_the_same_order_concurrently() {
		global $wpdb;
		$this->order();
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name  = 'mayar_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $this->token ), 0, 56 );
		$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			$this->assertSame( 'mayar_busy', $this->gateway->verify( $this->token )->get_error_code() );
			$this->assertCount( 1, $this->requests );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$other->close();
		}
	}

	/** Respect Retry-After, and do not send provider error text to customers. */
	public function test_rate_limit_prevents_immediate_followup_requests() {
		$this->override = function () {
			$response            = $this->response( array(), 429 );
			$response['headers'] = array( 'retry-after' => '120' );
			$response['body']    = 'private provider response';
			return $response;
		};
		$client         = new Mayar_Client( 'sandbox', 'rate-limit-test-' . wp_generate_uuid4() );
		$this->assertWPError( $client->request( 'GET', '/test' ) );
		$this->assertSame( 'mayar_rate_limit', $client->request( 'GET', '/test' )->get_error_code() );
		$this->assertCount( 1, $this->requests );
	}

	/** Failed verifications schedule a bounded retry rather than changing attendee status. */
	public function test_reconciliation_retries_api_outages() {
		$this->order();
		$this->override = static function () {
			return new WP_Error( 'offline', 'Offline' );
		};
		wp_clear_scheduled_hook( 'camptix_mayar_reconcile', array( $this->token ) );
		( new Reconciliation( $this->gateway ) )->run( $this->token );
		$this->assertNotFalse( wp_next_scheduled( 'camptix_mayar_reconcile', array( $this->token ) ) );
		$this->assertSame( 'draft', get_post_status( $this->attendee_ids[0] ) );
	}

	/** Reject invalid checkout data before reserving seats, while keeping the free-ticket flow. */
	public function test_checkout_validation_preserves_free_orders_and_rejects_fractional_totals() {
		$_POST['tix_payment_method'] = 'mayar';
		$this->gateway->validate_checkout( array(), array( 'total' => 0 ) );
		$flags = new ReflectionProperty( CampTix_Plugin::class, 'error_flags' );
		$this->assertEmpty( $flags->getValue( $GLOBALS['camptix'] ) );
		$this->gateway->validate_checkout( array(), array( 'total' => 100.5 ) );
		$this->assertNotEmpty( $flags->getValue( $GLOBALS['camptix'] )['payment_failed'] );
		$this->assertCount( 0, $this->requests );
	}

	/** Do not contact a provider when Mayar is disabled, currency differs, or credentials are absent. */
	public function test_disabled_currency_and_credentials_checks() {
		$this->gateway->camptix_options['currency'] = 'USD';
		$this->assertWPError( $this->order() );
		$this->gateway->camptix_options['currency']                 = 'IDR';
		$this->gateway->camptix_options['payment_methods']['mayar'] = 0;
		$this->assertWPError( $this->gateway->start_payment( $this->token ) );
		$this->gateway->camptix_options['payment_methods']['mayar'] = 1;
		$this->gateway->options['sandbox_api_key']                  = '';
		$this->assertWPError( $this->gateway->start_payment( $this->token ) );
		$this->assertCount( 0, $this->requests );
	}

	/** Missing API fields, invalid JSON, and non-array data remain unconfirmed. */
	public function test_malformed_provider_responses_do_not_settle_tickets() {
		$this->order();
		foreach ( array( 'not JSON', '{"statusCode":200,"messages":"success","data":"bad"}', '{"statusCode":200,"messages":"failed","data":{}}', '{"statusCode":200,"messages":"success","data":{}}' ) as $body ) {
			$this->override = function () use ( $body ) {
				$response         = $this->response( array() );
				$response['body'] = $body;
				return $response;
			};
			$this->assertWPError( $this->gateway->verify( $this->token ) );
		}
		$this->assertCount( 0, $this->results );
	}

	/** No recovery is allowed when correlation exists only in the webhook, not the provider API. */
	public function test_uncertain_creation_recovery_requires_provider_metadata() {
		$this->override = static function () {
			return new WP_Error( 'timeout', 'Timeout' );
		};
		$this->order();
		$this->status   = 'paid';
		$this->override = function ( $args, $url ) {
			if ( str_contains( $url, '/transactions/' ) ) {
				$transaction              = $this->transaction();
				$transaction['extraData'] = null;
				return $this->response( $transaction );
			}
			return $this->response( $this->payment() );
		};
		$this->assertWPError( $this->gateway->verify( $this->token, $this->transaction_id ) );
		$this->assertCount( 0, $this->results );
	}

	/** Oversized and malformed notifications do not cause provider traffic. */
	public function test_webhook_payload_bounds_and_unknown_references() {
		$controller = new Webhook_Controller( $this->gateway );
		$request    = new WP_REST_Request( 'POST' );
		$request->set_url_params( array( 'environment' => 'sandbox' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( str_repeat( 'x', 65537 ) );
		$this->assertWPError( $controller->receive( $request ) );
		$request->set_body( 'broken JSON' );
		$this->assertWPError( $controller->receive( $request ) );
		$request->set_body( wp_json_encode( array(
			'event' => 'payment.received', 'data' => array( 'transactionId' => wp_generate_uuid4() ),
		) ) );
		$this->assertSame( 200, $controller->receive( $request )->get_status() );
		$this->assertCount( 0, $this->requests );
	}

	/** Diagnostics are not displayed to ordinary subscribers. */
	public function test_payment_diagnostics_require_organizer_permissions() {
		$this->order();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		( new Reconciliation( $this->gateway ) )->render_meta_box( get_post( $this->attendee_ids[0] ) );
		$this->assertSame( '', ob_get_clean() );
	}

	/** A provider reference cannot be reused for a second order of the same amount. */
	public function test_provider_ids_cannot_be_assigned_to_multiple_orders() {
		$first              = $this->order();
		$this->token        = bin2hex( random_bytes( 16 ) );
		$this->attendee_ids = array();
		$this->assertWPError( $this->order() );
		$this->assertSame( $first['token'], $this->store->find( 'sandbox', 'transaction_id', $this->transaction_id )['token'] );
		wp_clear_scheduled_hook( 'camptix_mayar_reconcile', array( $first['token'] ) );
	}
}
