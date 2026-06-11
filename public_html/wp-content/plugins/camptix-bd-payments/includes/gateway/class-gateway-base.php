<?php
namespace CamptixBD\Gateway;

use CampTix_Plugin, CampTix_Payment_Method;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared base class for BD payment gateways
 */
abstract class Base_Gateway extends CampTix_Payment_Method {

	/**
	 * Build callback URLs for the payment gateway
	 *
	 * @param string $payment_token The payment token.
	 *
	 * @return array Associative array with success_url, fail_url, cancel_url, ipn_url.
	 */
	protected function build_callback_urls( $payment_token ) {
		$tickets_url = $GLOBALS['camptix']->get_tickets_url();

		$base_args = [
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => $this->id,
		];

		return [
			'success_url' => add_query_arg( array_merge( $base_args, [ 'tix_action' => 'payment_return' ] ), $tickets_url ),
			'fail_url'    => add_query_arg( array_merge( $base_args, [ 'tix_action' => 'payment_failed' ] ), $tickets_url ),
			'cancel_url'  => add_query_arg( array_merge( $base_args, [ 'tix_action' => 'payment_cancel' ] ), $tickets_url ),
			'ipn_url'     => add_query_arg( array_merge( $base_args, [ 'tix_action' => 'payment_notify' ] ), $tickets_url ),
		];
	}

	/**
	 * Strip sensitive fields from transaction data before logging
	 *
	 * @param array $data Transaction data.
	 *
	 * @return array Sanitized data safe for logging.
	 */
	protected function prepare_transaction_for_log( $data ) {
		$sensitive_keys = [
			'authorization',
			'store_passwd',
			'store_password',
			'password',
			'card_number',
			'card_exp',
			'card_cvv',
			'pin',
			'token',
			'sp_token',
			'cus_phone',
			'customer_phone',
			'shipping_phone_number',
		];

		foreach ( $data as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $sensitive_keys, true ) ) {
				$data[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = $this->prepare_transaction_for_log( $value );
			} elseif ( is_object( $value ) ) {
				$data[ $key ] = (object) $this->prepare_transaction_for_log( (array) $value );
			}
		}

		return $data;
	}

	/**
	 * Check that a redirect URL uses HTTPS and belongs to an allowed host.
	 *
	 * @param string $url           Redirect URL.
	 * @param array  $allowed_hosts Exact hostnames whose subdomains are also allowed.
	 *
	 * @return bool
	 */
	protected function is_allowed_https_host( $url, $allowed_hosts ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( 'https' !== $scheme || ! $host ) {
			return false;
		}

		foreach ( $allowed_hosts as $allowed_host ) {
			$allowed_host = strtolower( $allowed_host );

			if ( $allowed_host === $host || str_ends_with( $host, '.' . $allowed_host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render a password input for gateway secrets.
	 *
	 * @param array $args Field arguments from the Settings API.
	 *
	 * @return void
	 */
	public function field_password( $args ) {
		?>
		<input type="password" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo esc_attr( $args['value'] ); ?>" class="regular-text" autocomplete="new-password" />
		<?php
	}


	/**
	 * Check if the gateway is enabled
	 *
	 * @return bool
	 */
	public function gateway_enabled() {
		return ! empty( $this->camptix_options['payment_methods'][ $this->id ] );
	}

	/**
	 * Verify that the current CampTix currency can be used by this gateway.
	 *
	 * @param string $currency Expected currency code.
	 *
	 * @return bool
	 */
	protected function verify_currency( $currency ) {
		return in_array( $currency, $this->supported_currencies, true )
			&& ( $this->camptix_options['currency'] ?? '' ) === $currency;
	}

	/**
	 * Get all attendees for a CampTix payment token.
	 *
	 * Delegates to CampTix core's get_attendees_from_payment_token() instead of
	 * issuing a custom WP_Query, so attendee lookup stays in sync with any future
	 * changes CampTix core makes to that query (caching, post-status filters, etc.).
	 *
	 * @param string $payment_token CampTix payment token.
	 *
	 * @return array
	 */
	protected function get_attendees_by_payment_token( $payment_token ) {
		global $camptix;

		return $camptix->get_attendees_from_payment_token( $payment_token );
	}

	/**
	 * Read gateway customer fields from attendee post meta.
	 *
	 * @param int $attendee_id Attendee post ID.
	 *
	 * @return array
	 */
	protected function get_attendee_customer_info( $attendee_id ) {
		$first_name = get_post_meta( $attendee_id, 'tix_first_name', true );
		$last_name  = get_post_meta( $attendee_id, 'tix_last_name', true );

		return array(
			'name'  => trim( $first_name . ' ' . $last_name ),
			'email' => get_post_meta( $attendee_id, 'tix_email', true ),
			'phone' => get_post_meta( $attendee_id, 'tix_phone', true ),
		);
	}

	/**
	 * Make an HTTP API request
	 *
	 * @param string $method  HTTP method (GET, POST).
	 * @param string $url     Full URL.
	 * @param array  $args    Request arguments.
	 * @param array  $headers Additional headers.
	 *
	 * @return object|false Decoded JSON response or false on failure.
	 */
	protected function api( $method, $url, $args = array(), $headers = array() ) {
		$defaults = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge(
				array( 'Content-Type' => 'application/json' ),
				$headers
			),
		);

		if ( 'POST' === $method && ! empty( $args ) ) {
			$defaults['body'] = wp_json_encode( $args );
		} elseif ( 'GET' === $method && ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		$response = wp_remote_request( $url, $defaults );

		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'API request failed: %s', $response->get_error_message() ) );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$decoded_body = json_decode( $body, true );
			$log_body     = is_array( $decoded_body ) ? $this->prepare_transaction_for_log( $decoded_body ) : array( 'body_length' => strlen( $body ) );

			$this->log( sprintf( 'API request returned HTTP %d', $code ), null, $log_body );
			return false;
		}

		$decoded = json_decode( $body );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->log( sprintf( 'API response JSON error: %s', json_last_error_msg() ) );
			return false;
		}

		return $decoded;
	}
}
