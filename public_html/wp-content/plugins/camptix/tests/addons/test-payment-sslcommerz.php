<?php
/**
 * Regression coverage for SSLCommerz callback handling and diagnostics.
 */

require_once dirname( __DIR__, 3 ) . '/camptix-bd-payments/includes/gateway/class-gateway-base.php';
require_once dirname( __DIR__, 3 ) . '/camptix-bd-payments/includes/gateway/class-gateway-sslcommerz.php';
require_once dirname( __DIR__, 3 ) . '/camptix-bd-payments/includes/gateway/class-gateway-surjopay.php';

/**
 * @covers \CamptixBD\Gateway\SSLCommerz
 * @covers \CamptixBD\Gateway\Base_Gateway
 */
class Test_Camptix_Payment_SSLCommerz extends WP_UnitTestCase {
	private $original_camptix;
	private $gateway;

	/** Set up. */
	public function set_up() {
		parent::set_up();
		$this->original_camptix = $GLOBALS['camptix'];
		$GLOBALS['camptix']     = $this->getMockBuilder( CampTix_Plugin::class )->disableOriginalConstructor()->onlyMethods( [ 'log', 'error', 'payment_result', 'get_attendees_from_payment_token' ] )->getMock();
		$this->gateway          = $this->getMockBuilder( \CamptixBD\Gateway\SSLCommerz::class )->disableOriginalConstructor()->onlyMethods( [ 'verify_transaction', 'get_order' ] )->getMock();
		$this->gateway->options = [
			'store_password' => 'test-secret', 'merchant_id' => 'Al', 'min_amount' => 10.0, 'sandbox' => true,
		];
		$_POST                  = [];
		$_REQUEST               = [ 'tix_payment_token' => 'order-a' ];
	}

	/** Tear down. */
	public function tear_down() {
		$GLOBALS['camptix'] = $this->original_camptix;
		$_POST              = [];
		$_REQUEST           = [];
		$_GET               = [];
		parent::tear_down();
	}

	/** Invoke a protected gateway helper. */
	private function invoke( $name, ...$args ) {
		return ( new ReflectionMethod( $this->gateway, $name ) )->invoke( $this->gateway, ...$args );
	}

	/** Sign a fixture using the gateway's documented MD5 scheme. */
	private function sign( $data ) {
		$data['verify_key']     = implode( ',', array_keys( $data ) );
		$fields                 = array_intersect_key( $data, array_flip( explode( ',', $data['verify_key'] ) ) );
		$fields['store_passwd'] = md5( 'test-secret' );
		ksort( $fields );
		$pairs = [];
		foreach ( $fields as $key => $value ) {
			$pairs[] = $key . '=' . $value;
		}
		$data['verify_sign'] = md5( implode( '&', $pairs ) );
		return $data;
	}

	/** Test invalid signature does not query or change orders. */
	public function test_invalid_signature_does_not_query_or_change_orders() {
		$GLOBALS['camptix']->expects( $this->never() )->method( 'get_attendees_from_payment_token' );
		$GLOBALS['camptix']->expects( $this->never() )->method( 'payment_result' );
		$GLOBALS['camptix']->expects( $this->once() )->method( 'log' )->with( $this->stringContains( 'hash verification failed' ) );
		$this->gateway->payment_notify();
	}

	/** Test signed replay does not fail another order. */
	public function test_signed_replay_does_not_fail_another_order() {
		$_POST = $this->sign(
			[
				'tran_id' => 'order-b', 'status' => 'VALID',
			]
		);
		$GLOBALS['camptix']->method( 'get_attendees_from_payment_token' )->willReturn( [ (object) [ 'ID' => 42 ] ] );
		$GLOBALS['camptix']->expects( $this->never() )->method( 'payment_result' );
		$GLOBALS['camptix']->expects( $this->once() )->method( 'log' )->with( $this->stringContains( 'transaction ID mismatch' ), 42, $this->anything() );
		$this->gateway->payment_notify();
	}

	/** Test late signed notification is logged. */
	public function test_late_signed_notification_is_logged() {
		$_POST = $this->sign( [ 'tran_id' => 'order-a' ] );
		$GLOBALS['camptix']->method( 'get_attendees_from_payment_token' )->willReturn( [] );
		$GLOBALS['camptix']->expects( $this->once() )->method( 'log' )->with( $this->stringContains( 'no eligible attendee' ) );
		$GLOBALS['camptix']->expects( $this->never() )->method( 'payment_result' );
		$this->gateway->payment_notify();
	}

	/** Test verified notification persists bank reference. */
	public function test_verified_notification_persists_bank_reference() {
		$_POST     = $this->sign(
			[
				'tran_id' => 'order-a', 'bank_tran_id' => 'bank-reference', 'status' => 'VALID', 'cus_email' => 'private@example.com',
			]
		);
		$_REQUEST += $_POST;
		$GLOBALS['camptix']->method( 'get_attendees_from_payment_token' )->willReturn( [ (object) [ 'ID' => 42 ] ] );
		$this->gateway->method( 'verify_transaction' )->willReturn( true );
		$GLOBALS['camptix']->expects( $this->once() )->method( 'payment_result' )->with(
			'order-a',
			CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
			$this->callback(
				function ( $data ) {
					return 'bank-reference' === $data['transaction_details']['bank_tran_id'] && 'order-a' === $data['transaction_details']['tran_id'] && ! isset( $data['transaction_details']['cus_email'] );
				}
			)
		);
		$this->gateway->payment_notify();
	}

	/** Test bound notification validation failure calls payment result. */
	public function test_bound_notification_validation_failure_calls_payment_result() {
		$_POST = $this->sign( [ 'tran_id' => 'order-a' ] );
		$GLOBALS['camptix']->method( 'get_attendees_from_payment_token' )->willReturn( [ (object) [ 'ID' => 42 ] ] );
		$this->gateway->method( 'verify_transaction' )->willReturn( false );
		$GLOBALS['camptix']->expects( $this->once() )->method( 'payment_result' )->with( 'order-a', CampTix_Plugin::PAYMENT_STATUS_FAILED, $this->anything() );
		$this->gateway->payment_notify();
	}

	/** Test browser callbacks reject missing post transaction id. */
	public function test_browser_callbacks_reject_missing_post_transaction_id() {
		$_REQUEST['tran_id'] = 'order-a'; // A query parameter cannot substitute for the signed POST field.
		$GLOBALS['camptix']->method( 'get_attendees_from_payment_token' )->willReturn( [ (object) [ 'ID' => 42 ] ] );
		$GLOBALS['camptix']->expects( $this->never() )->method( 'payment_result' );
		$GLOBALS['camptix']->expects( $this->exactly( 2 ) )->method( 'log' );
		$GLOBALS['camptix']->expects( $this->exactly( 2 ) )->method( 'error' );
		$this->gateway->payment_cancel();
		$this->gateway->payment_failed();
	}

	/** Test bound browser callbacks call payment result. */
	public function test_bound_browser_callbacks_call_payment_result() {
		$_POST = [ 'tran_id' => 'order-a' ];
		$GLOBALS['camptix']->method( 'get_attendees_from_payment_token' )->willReturn( [ (object) [ 'ID' => 42 ] ] );
		$GLOBALS['camptix']->expects( $this->exactly( 2 ) )->method( 'payment_result' )->withConsecutive(
			[ 'order-a', CampTix_Plugin::PAYMENT_STATUS_CANCELLED, $this->anything() ],
			[ 'order-a', CampTix_Plugin::PAYMENT_STATUS_FAILED, $this->anything() ]
		);
		$this->gateway->payment_cancel();
		$this->gateway->payment_failed();
	}

	/** Test settings preserve password bytes and clear minimum. */
	public function test_settings_preserve_password_bytes_and_clear_minimum() {
		$password = ' <secret>%41\\\\" pass ';
		$options  = $this->gateway->validate_options(
			[
				'store_password' => $password, 'min_amount' => '',
			]
		);
		$this->assertSame( $password, $options['store_password'] );
		$this->assertSame( 0.0, $options['min_amount'] );
		$this->assertSame( 'Al', $options['merchant_id'] );
		$this->assertSame( $this->gateway->options, $this->gateway->validate_options( [] ) );
		$options = $this->gateway->validate_options(
			[
				'store_password' => "bad\nsecret", 'min_amount' => '1e999',
			]
		);
		$this->assertSame( $this->gateway->options, $options );
	}

	/** Test redaction preserves words and hides short full names. */
	public function test_redaction_preserves_words_and_hides_short_full_names() {
		$message = 'Transaction amount is not allowed as per admin configuration!';
		$this->assertSame( $message, $this->invoke( 'prepare_gateway_message_for_log', $message, [ 'Al' ] ) );
		$this->assertSame( 'Customer [redacted] failed', $this->invoke( 'prepare_gateway_message_for_log', 'Customer Al failed', [ 'Al' ] ) );
		$this->assertSame( '[redacted]', $this->invoke( 'prepare_gateway_message_for_log', 'long-secret', [ 'long-secret', 'red' ] ) );
	}

	/** Test both gateways keep diagnostics without secrets. */
	public function test_both_gateways_keep_diagnostics_without_secrets() {
		foreach ( [ \CamptixBD\Gateway\SSLCommerz::class, \CamptixBD\Gateway\SurjoPay::class ] as $class ) {
			$gateway = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
			$method  = new ReflectionMethod( $gateway, 'prepare_api_diagnostics' );
			$result  = $method->invoke(
				$gateway,
				[
					'message' => 'cURL error 28: timeout for secret-password https://example.com/?token=hidden', 'nested' => [
				'password' => 'secret-password', 'error' => 'invalid credentials',
					],
				],
				[ 'password' => 'secret-password' ]
			);
			$this->assertStringContainsString( 'cURL error 28: timeout', $result['message'] );
			$this->assertStringNotContainsString( 'secret-password', wp_json_encode( $result ) );
			$this->assertStringNotContainsString( 'token=hidden', wp_json_encode( $result ) );
			$this->assertSame( 'invalid credentials', $result['nested']['error'] );
		}
		$this->assertArrayNotHasKey(
			'tran_id',
			$this->invoke(
				'prepare_transaction_for_log',
				[
					'tran_id' => 'order-a', 'bank_tran_id' => 'bank-reference',
				]
			)
		);
	}
	/** Session-key queries return a flat transaction and retain reconciliation data. */
	public function test_timeout_recovery_stores_references_but_redacts_its_log() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		update_post_meta( $id, '_sslcommerz_session_key', 'session-secret' );
		update_post_meta( $id, 'tix_payment_token', 'order-a' );
		$this->gateway->method( 'get_order' )->willReturn( [ 'total' => 20 ] );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->assertStringContainsString( 'sessionkey=session-secret', $url );
				$this->assertStringContainsString( 'format=json', $url );
				$transaction = [
					'status' => 'VALID', 'tran_id' => 'order-a', 'bank_tran_id' => 'bank-reference', 'amount' => 20, 'currency' => 'BDT', 'currency_type' => 'BDT',
				];
				return [
					'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $transaction ),
				];
			},
			10,
			3
		);
		$GLOBALS['camptix']->expects( $this->once() )->method( 'log' )->with(
			$this->anything(),
			$id,
			$this->callback(
				function ( $data ) {
					return ! str_contains( wp_json_encode( $data ), 'bank-reference' );
				}
			)
		);
		$GLOBALS['camptix']->expects( $this->once() )->method( 'payment_result' )->with(
			'order-a',
			CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
			$this->callback(
				function ( $data ) {
					return 'bank-reference' === $data['transaction_details']['bank_tran_id'];
				}
			),
			false
		);
		$this->gateway->pre_attendee_timeout( $id );
	}

	/** Both HTTP clients retain useful errors without exposing echoed secrets. */
	public function test_api_error_paths_for_both_gateways() {
		$reply = new WP_Error( 'http_request_failed', 'cURL error 28: timeout for secret-password' );
		add_filter(
			'pre_http_request',
			static function () use ( &$reply ) {
				return $reply;
			}
		);
		$GLOBALS['camptix']->expects( $this->exactly( 4 ) )->method( 'log' )->with(
			$this->anything(),
			null,
			$this->callback(
				function ( $data ) {
					$text = wp_json_encode( $data );
					return ! str_contains( $text, 'secret-password' ) && ( str_contains( $text, 'cURL error 28' ) || str_contains( $text, 'invalid credentials' ) );
				}
			)
		);
		foreach ( [ \CamptixBD\Gateway\SSLCommerz::class, \CamptixBD\Gateway\SurjoPay::class ] as $class ) {
			$gateway = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
			( new ReflectionProperty( $gateway, 'options' ) )->setValue( $gateway, $this->gateway->options );
			$api   = new ReflectionMethod( $gateway, 'api' );
			$reply = new WP_Error( 'http_request_failed', 'cURL error 28: timeout for secret-password' );
			$this->assertFalse( $api->invoke( $gateway, 'POST', 'https://example.com/api', [ 'password' => 'secret-password' ] ) );
			$reply = [
				'response' => [ 'code' => 401 ], 'body' => wp_json_encode(
					[
					'error' => 'invalid credentials secret-password', 'password' => 'secret-password',
					]
				),
			];
			$this->assertFalse( $api->invoke( $gateway, 'POST', 'https://example.com/api', [ 'password' => 'secret-password' ] ) );
		}
	}
	/** Accept the observed live checkout host without accepting arbitrary subdomains. */
	public function test_gateway_redirect_hosts_are_exact_and_mode_specific() {
		$this->gateway->options['sandbox'] = false;
		$this->assertTrue( $this->invoke( 'is_allowed_gateway_url', 'https://epay-gw.sslcommerz.com/checkout' ) );
		$this->assertTrue( $this->invoke( 'is_allowed_gateway_url', 'https://securepay.sslcommerz.com/checkout' ) );
		foreach ( [
			'http://epay-gw.sslcommerz.com/checkout',
			'https://sandbox.sslcommerz.com/checkout',
			'https://other.sslcommerz.com/checkout',
			'https://child.epay-gw.sslcommerz.com/checkout',
			'https://epay-gw.sslcommerz.com.evil.example/checkout',
			'https://epay-gw.sslcommerz.com@evil.example/checkout',
		] as $url ) {
			$this->assertFalse( $this->invoke( 'is_allowed_gateway_url', $url ), $url );
		}
		$this->gateway->options['sandbox'] = true;
		$this->assertTrue( $this->invoke( 'is_allowed_gateway_url', 'https://sandbox.sslcommerz.com/checkout' ) );
		$this->assertFalse( $this->invoke( 'is_allowed_gateway_url', 'https://epay-gw.sslcommerz.com/checkout' ) );
		$this->assertFalse( $this->invoke( 'is_allowed_gateway_url', 'https://securepay.sslcommerz.com/checkout' ) );
	}
}
