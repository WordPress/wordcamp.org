<?php
namespace WordCamp\CampTix\IndonesianPayments;

use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/** Receive notifications only on the configured site and environment. */
class Webhook_Controller {
	private $gateway;

	/** Use the same gateway verifier as checkout and reconciliation. */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/** Mayar's documented webhook has no signing contract; the secret filters requests, the API proves payment. */
	public static function url( $environment ) {
		$store = new Payment_Store();
		return add_query_arg( 'secret', $store->webhook_secret( $environment ), rest_url( 'camptix-mayar/v1/webhook/' . $environment ) );
	}

	/** Keep environment selection in the route rather than untrusted notification metadata. */
	public function register_routes() {
		register_rest_route(
			'camptix-mayar/v1',
			'/webhook/(?P<environment>sandbox|production)',
			array(
				'methods' => 'POST', 'callback' => array( $this, 'receive' ), 'permission_callback' => array( $this, 'authorize' ),
			),
			true
		);
	}

	/** Do not require a logged-in WordPress user or a WordPress nonce. */
	public function authorize( $request ) {
		$query       = $request->get_query_params();
		$secret      = $query['secret'] ?? '';
		$environment = $request->get_url_params()['environment'] ?? '';
		if ( ! in_array( $environment, array( 'sandbox', 'production' ), true ) || ! is_string( $secret ) || strlen( $secret ) !== 64 ) {
			return new WP_Error( 'mayar_forbidden', __( 'Invalid webhook credentials.', 'camptix-indonesian-payments' ), array( 'status' => 403 ) );
		}
		$store = new Payment_Store();
		return hash_equals( $store->webhook_secret( $environment ), $secret ) ? true : new WP_Error( 'mayar_forbidden', __( 'Invalid webhook credentials.', 'camptix-indonesian-payments' ), array( 'status' => 403 ) );
	}

	/** Never trust notification amount, status, merchant, or site as evidence. */
	public function receive( $request ) {
		if ( strlen( $request->get_body() ) > 65536 || ! $request->is_json_content_type() ) {
			return new WP_Error( 'mayar_payload', __( 'Expected a bounded JSON notification.', 'camptix-indonesian-payments' ), array( 'status' => 400 ) );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'mayar_payload', __( 'Invalid JSON notification.', 'camptix-indonesian-payments' ), array( 'status' => 400 ) );
		}
		$event = $body['event'] ?? $body['event.received'] ?? '';
		if ( is_array( $event ) ) {
			$event = $event['received'] ?? '';
		}
		if ( ! in_array( $event, array( 'payment.received', 'payment.reminder' ), true ) || ! is_array( $body['data'] ?? null ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
		$data        = $body['data'];
		$environment = $request->get_url_params()['environment'];
		$store       = new Payment_Store();
		$attempt     = array();
		foreach ( array(
			'transaction_id' => $data['transactionId'] ?? $data['id'] ?? '', 'payment_id' => $data['productId'] ?? '', 'reference' => $data['extraData']['camptix_reference'] ?? '',
		) as $field => $value ) {
			$attempt = $store->find( $environment, $field, $value );
			if ( $attempt ) {
				break;
			}
		}
		if ( ! $attempt ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
		$candidate = $data['transactionId'] ?? '';
		$result    = $this->gateway->verify( $attempt['token'], is_string( $candidate ) ? $candidate : '' );
		if ( is_wp_error( $result ) ) {
			Reconciliation::schedule( $attempt['token'] );
			return new WP_Error( 'mayar_retry', __( 'Payment verification will be retried.', 'camptix-indonesian-payments' ), array( 'status' => 503 ) );
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/** Optional return URL checks the API, and never treats a browser parameter as proof. */
	public function browser_return() {
		if ( ! isset( $_GET['camptix_mayar_return'] ) ) {
			return;
		}
		$reference = is_string( $_GET['camptix_mayar_return'] ) ? wp_unslash( $_GET['camptix_mayar_return'] ) : '';
		$key       = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wp_unslash( $_GET['key'] ) : '';
		$store     = new Payment_Store();
		$attempt   = $store->find( 'sandbox', 'reference', $reference ) ?: $store->find( 'production', 'reference', $reference );
		if ( ! $attempt || ! hash_equals( $attempt['return_key'], $key ) ) {
			wp_die( esc_html__( 'This payment return link is invalid.', 'camptix-indonesian-payments' ), '', array( 'response' => 403 ) );
		}
		$result  = $this->gateway->verify( $attempt['token'] );
		$message = 'complete' === $result
			? __( 'Your payment is confirmed. Your ticket details are sent by email.', 'camptix-indonesian-payments' )
			: __( 'Your payment is being checked. Please wait for your ticket email or contact the organizers before paying again.', 'camptix-indonesian-payments' );
		nocache_headers();
		wp_die(
			esc_html( $message ),
			esc_html__( 'Mayar payment', 'camptix-indonesian-payments' ),
			array(
				'response'  => 200,
				'back_link' => false,
			)
		);
	}
}
