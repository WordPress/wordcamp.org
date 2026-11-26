<?php
/**
 * Shashura MoMo API client.
 *
 * Base URL https://shashura.com/api/v1. The X-Target-Environment header selects sandbox
 * or production on the same base URL.
 *
 *  POST /auth            {client_id, client_secret}       -> data.access_token (1 hour)
 *  GET  /kyc/{msisdn}                                     -> data.name, data.network, data.msisdn
 *  POST /requestToPay    {msisdn, amount, reference}      -> 202, data.tid
 *  GET  /enquiry/{ref}                                    -> data.state PENDING | TS | TF, data.amount, data.external_ref,
 *                                                           data.failure { code, message } on TF
 *  POST /refund          {tid, amount, reason, send_to_customer} -> data.amount_refunded, data.fully_refunded
 *  Webhook  POST {event, environment, tid, external_ref, type, status, amount, ...}
 *           X-Shashura-Signature = hex HMAC-SHA256(raw body, app webhook secret)
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client for the Shashura Mobile Money API.
 */
class Shasmomo_API {

	const BASE_URL = 'https://shashura.com/api/v1';

	/** Enquiry `state` -> normalized. Anything else is "pending", never "paid". */
	const STATES = array(
		'TS'      => 'paid',
		'TF'      => 'failed',
		'PENDING' => 'pending',
	);

	/**
	 * API base URL, without a trailing slash. Empty when the configured URL isn't allowed.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Shashura client ID.
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * Shashura client secret.
	 *
	 * @var string
	 */
	private $client_secret;

	/**
	 * Target environment: sandbox or production.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * Secret used to sign webhooks.
	 *
	 * @var string
	 */
	private $webhook_secret;

	/**
	 * Set up the client.
	 *
	 * @param string $client_id         Shashura client ID.
	 * @param string $client_secret     Shashura client secret.
	 * @param string $environment       sandbox or production. Anything else means production.
	 * @param string $webhook_secret    Secret used to sign webhooks.
	 * @param string $base_url_override Ignored. Kept so existing callers don't break.
	 */
	public function __construct( $client_id, $client_secret, $environment, $webhook_secret = '', $base_url_override = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Endpoint trust belongs to deployment code, never a site's saved settings.
		// Keep the constructor argument for backwards compatibility, but ignore it.
		$base = defined( 'SHASMOMO_BASE_URL' ) ? (string) SHASMOMO_BASE_URL : self::BASE_URL;
		$base = (string) apply_filters( 'shasmomo_base_url', $base );
		if ( ! self::is_allowed_base_url( $base ) ) {
			$base = ''; // Fail closed; do not silently send credentials to another gateway.
		}

		$this->base_url       = untrailingslashit( $base );
		$this->client_id      = (string) $client_id;
		$this->client_secret  = (string) $client_secret;
		$this->environment    = in_array( $environment, array( 'sandbox', 'production' ), true ) ? $environment : 'production';
		$this->webhook_secret = (string) $webhook_secret;
	}

	/**
	 * Trusted configuration must still use HTTPS without embedded credentials or URL suffixes.
	 *
	 * @param string $url Base URL to check.
	 * @return bool
	 */
	public static function is_allowed_base_url( $url ) {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] )
			&& 'https' === $parts['scheme'] && '' !== $parts['host']
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& ! isset( $parts['query'] ) && ! isset( $parts['fragment'] )
			&& ( ! isset( $parts['port'] ) || 443 === $parts['port'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * Endpoints
	 * ------------------------------------------------------------------
	 */

	/** Check the credentials by fetching a fresh token. @return true|WP_Error */
	public function test_credentials() {
		$token = $this->token( true );
		return is_wp_error( $token ) ? $token : true;
	}

	/**
	 * Look up the registered name and network for a mobile number.
	 *
	 * @param string $msisdn Digits with country code, e.g. 256770000000.
	 * @return array|WP_Error { name, network, msisdn }
	 */
	public function kyc( $msisdn ) {
		$res = $this->authed( 'GET', '/kyc/' . rawurlencode( $msisdn ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data    = $this->data( $res );
		$name    = isset( $data['name'] ) && is_string( $data['name'] ) ? trim( $data['name'] ) : '';
		$network = isset( $data['network'] ) && is_string( $data['network'] ) ? strtoupper( trim( $data['network'] ) ) : '';

		if ( '' === $name && '' === $network ) {
			return new WP_Error( 'shashura_kyc_empty', 'KYC returned no subscriber.' );
		}
		return array(
			'name'    => sanitize_text_field( $name ),
			'network' => sanitize_text_field( $network ),
			'msisdn'  => isset( $data['msisdn'] ) ? preg_replace( '/\D+/', '', (string) $data['msisdn'] ) : $msisdn,
		);
	}

	/**
	 * Push a PIN prompt to the payer's phone.
	 *
	 * @param string $msisdn    Digits with country code.
	 * @param int    $amount    Amount in UGX.
	 * @param string $reference Our reference for this payment attempt.
	 * @return array|WP_Error { tid }
	 */
	public function request_to_pay( $msisdn, $amount, $reference ) {
		$res = $this->authed(
			'POST',
			'/requestToPay',
			array(
				'msisdn'    => (string) $msisdn,
				'amount'    => (int) $amount,
				'reference' => (string) $reference,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = $this->data( $res );
		return array( 'tid' => isset( $data['tid'] ) ? sanitize_text_field( (string) $data['tid'] ) : '' );
	}

	/**
	 * Ask Shashura for the current state of a payment.
	 *
	 * @param string $reference Our reference for the payment attempt.
	 * @return array|WP_Error { state: paid|failed|pending, raw_state, amount: int|null, external_ref, tid, network_ref }
	 */
	public function enquiry( $reference ) {
		$res = $this->authed( 'GET', '/enquiry/' . rawurlencode( $reference ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data    = $this->data( $res );
		$raw     = isset( $data['state'] ) && is_scalar( $data['state'] ) ? strtoupper( trim( (string) $data['state'] ) ) : '';
		$failure = isset( $data['failure'] ) && is_array( $data['failure'] ) ? $data['failure'] : array();

		return array(
			'state'        => isset( self::STATES[ $raw ] ) ? self::STATES[ $raw ] : 'pending',
			'raw_state'    => $raw,
			'amount'       => isset( $data['amount'] ) && is_numeric( $data['amount'] ) ? (int) round( (float) $data['amount'] ) : null,
			'external_ref' => isset( $data['external_ref'] ) ? (string) $data['external_ref'] : '',
			'tid'          => isset( $data['tid'] ) ? sanitize_text_field( (string) $data['tid'] ) : '',
			'network_ref'  => isset( $data['network_ref'] ) ? sanitize_text_field( (string) $data['network_ref'] ) : '',
			// Only sent on TF, e.g. INSUFFICIENT_FUNDS.
			'failure_code' => isset( $failure['code'] ) && is_scalar( $failure['code'] ) ? preg_replace( '/[^A-Z0-9_]/', '', strtoupper( (string) $failure['code'] ) ) : '',
			'failure_text' => isset( $failure['message'] ) && is_scalar( $failure['message'] ) ? sanitize_text_field( (string) $failure['message'] ) : '',
		);
	}

	/**
	 * Reverse a settled collection. Only the principal the merchant received is ever
	 * reversed — Shashura's processing fee is kept, on a full or partial refund.
	 *
	 * @param string     $tid              Our tid, or the reference the collection was made with.
	 * @param float|null $amount           Amount to refund, in UGX (no decimals). Null refunds
	 *                                     whatever remains — pass null for a full refund, or for
	 *                                     the last call of a series of partial refunds that should
	 *                                     clear the balance exactly.
	 * @param string     $reason           Required by the gateway; never sent empty.
	 * @param bool       $send_to_customer Whether to also push a real payout of the refunded
	 *                                     amount back to the original msisdn.
	 * @return array|WP_Error {
	 *     amount_refunded, refunded_total, refundable_total, fully_refunded, refund_ref,
	 *     payout: null|{ status: TS|PENDING|FAILED, ref, message? }
	 * }
	 */
	public function refund( $tid, $amount, $reason, $send_to_customer = true ) {
		$res = $this->authed(
			'POST',
			'/refund',
			array(
				'tid'              => (string) $tid,
				'amount'           => null === $amount ? 0 : (float) $amount,
				'reason'           => '' !== trim( (string) $reason ) ? (string) $reason : 'Refund requested.',
				'send_to_customer' => (bool) $send_to_customer,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data   = $this->data( $res );
		$payout = null;
		if ( isset( $data['payout'] ) && is_array( $data['payout'] ) ) {
			$payout = array(
				'status'  => isset( $data['payout']['status'] ) ? sanitize_text_field( (string) $data['payout']['status'] ) : '',
				'ref'     => isset( $data['payout']['ref'] ) ? sanitize_text_field( (string) $data['payout']['ref'] ) : '',
				'message' => isset( $data['payout']['message'] ) ? sanitize_text_field( (string) $data['payout']['message'] ) : '',
			);
		}
		return array(
			'amount_refunded'  => isset( $data['amount_refunded'] ) && is_numeric( $data['amount_refunded'] ) ? (float) $data['amount_refunded'] : 0.0,
			'refunded_total'   => isset( $data['refunded_total'] ) && is_numeric( $data['refunded_total'] ) ? (float) $data['refunded_total'] : 0.0,
			'refundable_total' => isset( $data['refundable_total'] ) && is_numeric( $data['refundable_total'] ) ? (float) $data['refundable_total'] : 0.0,
			'fully_refunded'   => ! empty( $data['fully_refunded'] ),
			'refund_ref'       => isset( $data['refund_ref'] ) ? sanitize_text_field( (string) $data['refund_ref'] ) : '',
			'payout'           => $payout,
		);
	}

	/**
	 * Constant-time check of X-Shashura-Signature against the raw request body.
	 *
	 * @param string $raw_body  Raw webhook request body.
	 * @param string $signature Value of the X-Shashura-Signature header.
	 * @return bool
	 */
	public function verify_signature( $raw_body, $signature ) {
		if ( '' === $this->webhook_secret || ! is_string( $signature ) || '' === $signature ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', (string) $raw_body, $this->webhook_secret );
		return hash_equals( $expected, strtolower( trim( $signature ) ) );
	}

	/**
	 * Shashura error code (e.g. GATEWAY_REJECTED) carried on a WP_Error, or ''.
	 *
	 * @param WP_Error|mixed $error Result of an API call.
	 * @return string
	 */
	public static function error_code( $error ) {
		$d = is_wp_error( $error ) ? $error->get_error_data() : null;
		return is_array( $d ) && ! empty( $d['api_code'] ) ? (string) $d['api_code'] : '';
	}

	/**
	 * HTTP status carried on a WP_Error (0 for transport errors).
	 *
	 * @param WP_Error|mixed $error Result of an API call.
	 * @return int
	 */
	public static function http_status( $error ) {
		$d = is_wp_error( $error ) ? $error->get_error_data() : null;
		return is_array( $d ) && ! empty( $d['http'] ) ? (int) $d['http'] : 0;
	}

	/*
	 * ------------------------------------------------------------------
	 * Auth and transport
	 * ------------------------------------------------------------------
	 */

	/**
	 * Transient key for the cached access token.
	 *
	 * @return string
	 */
	private function token_key() {
		// Tied to the secret and environment, so changing either invalidates the cached token.
		return 'shasmomo_tok_' . md5( $this->base_url . '|' . $this->client_id . '|' . $this->client_secret . '|' . $this->environment );
	}

	/**
	 * Get an access token, from the cache unless forced.
	 *
	 * @param bool $force Fetch a new token even if one is cached.
	 * @return string|WP_Error
	 */
	private function token( $force = false ) {
		if ( '' === $this->client_id || '' === $this->client_secret ) {
			return new WP_Error( 'shashura_not_configured', 'Shashura credentials are not set.' );
		}
		if ( ! $force ) {
			$cached = get_transient( $this->token_key() );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$res = $this->send(
			'POST',
			'/auth',
			array(
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
			),
			array(
				'X-CLIENT-ID'          => $this->client_id,
				'X-Target-Environment' => $this->environment,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data  = $this->data( $res );
		$token = isset( $data['access_token'] ) && is_string( $data['access_token'] ) ? $data['access_token'] : '';
		if ( '' === $token ) {
			return new WP_Error( 'shashura_auth', 'Authentication returned no access token.' );
		}
		$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : HOUR_IN_SECONDS;
		set_transient( $this->token_key(), $token, max( 60, min( $ttl, HOUR_IN_SECONDS ) - 120 ) );
		return $token;
	}

	/**
	 * Send an authenticated request, refreshing the token once if it was rejected.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Endpoint path, e.g. /enquiry/REF.
	 * @param array|null $body   JSON body, or null for none.
	 * @return array|WP_Error Decoded response.
	 */
	private function authed( $method, $path, $body = null ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = $this->send( $method, $path, $body, $this->headers( $token ) );

		// Token expired or revoked early: refresh once and retry.
		if ( is_wp_error( $res ) && ( 401 === self::http_status( $res ) || in_array( self::error_code( $res ), array( 'EXPIRED_TOKEN', 'UNAUTHORIZED' ), true ) ) ) {
			delete_transient( $this->token_key() );
			$token = $this->token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$res = $this->send( $method, $path, $body, $this->headers( $token ) );
		}
		return $res;
	}

	/**
	 * Headers for an authenticated request.
	 *
	 * @param string $token Access token.
	 * @return array
	 */
	private function headers( $token ) {
		return array(
			'Authorization'        => 'Bearer ' . $token,
			'X-CLIENT-ID'          => $this->client_id,
			'X-Target-Environment' => $this->environment,
			'X-COUNTRY'            => 'UG',
		);
	}

	/**
	 * Send a request to the Shashura API.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Endpoint path.
	 * @param array|null $body    JSON body, or null for none.
	 * @param array      $headers Request headers.
	 * @return array|WP_Error Decoded response.
	 */
	private function send( $method, $path, $body, $headers ) {
		if ( '' === $this->base_url ) {
			return new WP_Error( 'shashura_endpoint', 'Invalid trusted Shashura API endpoint.' );
		}
		$args = array(
			'method'      => $method,
			'timeout'     => (int) apply_filters( 'shasmomo_http_timeout', 25 ),
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array_merge(
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_safe_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'shashura_transport',
				$response->get_error_message(),
				array(
					'http'     => 0,
					'api_code' => '',
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$api_code = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '';
			$message  = is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
			return new WP_Error(
				'shashura_http',
				$message,
				array(
					'http'     => $code,
					'api_code' => $api_code,
				)
			);
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'shashura_bad_json',
				'Unreadable response from Shashura.',
				array(
					'http'     => $code,
					'api_code' => '',
				)
			);
		}
		return $data;
	}

	/**
	 * The data object from a decoded response.
	 *
	 * @param array $res Decoded response.
	 * @return array
	 */
	private function data( $res ) {
		return isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
	}
}
