<?php
namespace WordCamp\CampTix\IndonesianPayments;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Minimal Mayar v2 client. Provider responses and credentials are never logged. */
class Mayar_Client {
	private $environment;
	private $api_key;

	/** Bind this client to one fixed environment and credential. */
	public function __construct( $environment, $api_key ) {
		$this->environment = $environment;
		$this->api_key     = $api_key;
	}

	/** Accept integer rupiah amounts without rounding or using minor-unit multipliers. */
	public static function amount( $value ) {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return 0;
		}
		if ( is_string( $value ) && ! preg_match( '/^\d+(?:\.0+)?$/D', $value ) ) {
			return 0;
		}
		$number = (float) $value;
		return is_finite( $number ) && $number > 0 && $number <= 9007199254740991 && floor( $number ) === $number ? (int) $number : 0;
	}

	/** Use only documented Mayar HTTPS checkout hosts. Custom domains are deliberately excluded. */
	public static function checkout_url_allowed( $url ) {
		if ( ! is_string( $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] ?? '' );
		return in_array( $host, array( 'myr.id', 'mayar.id', 'mayar.io' ), true ) || str_ends_with( $host, '.myr.id' );
	}

	/** Make a bounded request; errors intentionally exclude raw provider bodies. */
	public function request( $method, $path, $body = null ) {
		if ( ! in_array( $this->environment, array( 'sandbox', 'production' ), true ) || ! is_string( $this->api_key ) || '' === $this->api_key || preg_match( '/[\r\n]/', $this->api_key ) ) {
			return new WP_Error( 'mayar_credentials', __( 'Mayar credentials are missing or invalid.', 'camptix-indonesian-payments' ) );
		}

		$budget = $this->reserve_request();
		if ( is_wp_error( $budget ) ) {
			return $budget;
		}
		$base = 'sandbox' === $this->environment ? 'https://api.mayar.io/hl/v2' : 'https://api.mayar.id/hl/v2';
		$args = array(
			'method'              => $method,
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => 262144,
			'headers'             => array(
				'Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $base . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mayar_transport', __( 'Mayar could not be reached. The payment will be checked again.', 'camptix-indonesian-payments' ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 429 === $status ) {
			$header = wp_remote_retrieve_header( $response, 'retry-after' );
			$delay  = ctype_digit( (string) $header ) ? (int) $header : max( 60, (int) strtotime( (string) $header ) - time() );
			set_site_transient( $this->budget_key() . '_wait', time() + max( 60, min( DAY_IN_SECONDS, $delay ) ), DAY_IN_SECONDS );
		}
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || 200 !== ( $data['statusCode'] ?? null ) || 'success' !== ( $data['messages'] ?? $data['message'] ?? '' ) ) {
			return new WP_Error( 'mayar_api', __( 'Mayar returned an unsuccessful or invalid response.', 'camptix-indonesian-payments' ), array( 'status' => $status ) );
		}
		return $data['data'] ?? array();
	}

	/** Keep shared credentials within the account-wide limit, including cron requests. */
	private function reserve_request() {
		$store = new Payment_Store();
		$key   = $this->budget_key();
		$lock  = $store->lock( $key, true );
		if ( ! $lock ) {
			return new WP_Error( 'mayar_busy', __( 'Mayar is busy. Please try again shortly.', 'camptix-indonesian-payments' ) );
		}
		try {
			$now      = time();
			$requests = array_filter(
				(array) $this->fresh_budget( $key ),
				static function ( $at ) use ( $now ) {
					return $at > $now - 60;
				}
			);
			if ( (int) $this->fresh_budget( $key . '_wait' ) > $now || count( $requests ) >= 45 ) {
				return new WP_Error( 'mayar_rate_limit', __( 'Mayar verification is queued. Please try again shortly.', 'camptix-indonesian-payments' ) );
			}
			$requests[] = $now;
			set_site_transient( $key, $requests, 60 );
			return true;
		} finally {
			$store->unlock( $lock );
		}
	}

	/** Refresh quota values after acquiring the network lock, including process-local option caches. */
	private function fresh_budget( $key ) {
		wp_cache_delete( $key, 'site-transient' );
		wp_cache_delete( '_site_transient_' . $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( get_current_network_id() . ':_site_transient_' . $key, 'site-options' );
		wp_cache_delete( get_current_network_id() . ':notoptions', 'site-options' );
		return get_site_transient( $key );
	}

	/** Hash credentials so transient names never contain the secret. */
	private function budget_key() {
		return 'camptix_mayar_budget_' . hash( 'sha256', $this->environment . ':' . $this->api_key );
	}
}
