<?php

/**
 * Centralized Stripe webhook handling for the CampTix Stripe payment method.
 */
trait CampTix_Payment_Method_Stripe_Webhook {
	/**
	 * Register the centralized Stripe webhook endpoint.
	 */
	public function register_rest_routes() {
		if ( ! defined( 'WORDCAMP_ROOT_BLOG_ID' ) || (int) WORDCAMP_ROOT_BLOG_ID !== get_current_blog_id() ) {
			return;
		}

		register_rest_route(
			'camptix/v1',
			'/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_stripe_webhook' ),
				'permission_callback' => array( $this, 'rest_stripe_webhook_permissions_check' ),
			)
		);
	}

	/**
	 * Verify the Stripe webhook signature before dispatching to the route callback.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 *
	 * @return true|WP_Error
	 */
	public function rest_stripe_webhook_permissions_check( $request ) {
		$payload          = $request->get_body();
		$signature_header = $request->get_header( 'stripe-signature' );
		$webhook_secret   = defined( 'WORDCAMP_CAMPTIX_STRIPE_LIVE_WEBHOOK_SECRET' ) ? trim( WORDCAMP_CAMPTIX_STRIPE_LIVE_WEBHOOK_SECRET ) : '';
		$error            = new WP_Error(
			'camptix_stripe_webhook_invalid_signature',
			'Invalid Stripe webhook signature.',
			array( 'status' => 403 )
		);

		if ( ! $webhook_secret || ! $signature_header ) {
			return $error;
		}

		preg_match( '/(?:^|,)\s*t=(\d+)/', $signature_header, $timestamp_match );
		preg_match_all( '/(?:^|,)\s*v1=([^,]+)/', $signature_header, $signature_matches );

		$timestamp  = absint( $timestamp_match[1] ?? 0 );
		$signatures = $signature_matches[1] ?? array();

		if ( ! $timestamp || ! $signatures ) {
			return $error;
		}

		if ( abs( time() - $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
			return $error;
		}

		$signed_payload = $timestamp . '.' . $payload;
		$expected_signature = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected_signature, $signature ) ) {
				return true;
			}
		}

		return $error;
	}

	/**
	 * Handle Stripe webhook notifications.
	 *
	 * This is intended to be called on central.wordcamp.org, then it switches
	 * into the site that created the Checkout Session before updating tickets.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_stripe_webhook( $request ) {
		$payload = $request->get_body();
		$event   = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return new WP_Error(
				'camptix_stripe_webhook_invalid_json',
				'The Stripe webhook payload was not valid JSON.',
				array( 'status' => 400 )
			);
		}

		$event_type      = $event['type'] ?? '';
		$supported_types = array(
			'checkout.session.completed',
			'checkout.session.async_payment_succeeded',
			'checkout.session.async_payment_failed',
			'checkout.session.expired',
		);

		// Only gate webhook noise here; the fetched Checkout Session determines
		// the CampTix payment result.
		if ( ! in_array( $event_type, $supported_types, true ) ) {
			return new WP_REST_Response(
				array(
					'status' => 'ignored',
					'type'   => $event_type,
				),
				200
			);
		}

		$webhook_session = $event['data']['object'] ?? false;
		if ( ! is_array( $webhook_session ) ) {
			return new WP_Error(
				'camptix_stripe_webhook_missing_session',
				'The Stripe webhook payload did not contain a Checkout Session.',
				array( 'status' => 400 )
			);
		}

		$stripe_session_id = sanitize_text_field( $webhook_session['id'] ?? '' );
		$payment_token     = $this->get_payment_token_from_webhook_session( $webhook_session );
		$site_id           = $this->get_site_id_from_webhook_session( $webhook_session );

		if ( ! $stripe_session_id || ! $payment_token || ! $site_id ) {
			return new WP_Error(
				'camptix_stripe_webhook_missing_session_data',
				'The Stripe webhook payload did not contain enough session data to find the ticket order.',
				array( 'status' => 400 )
			);
		}

		if ( ! get_site( $site_id ) ) {
			return new WP_Error(
				'camptix_stripe_webhook_invalid_site',
				'The Stripe webhook payload referenced an unknown site.',
				array( 'status' => 400 )
			);
		}

		/** @var CampTix_Plugin $camptix */
		global $camptix;

		switch_to_blog( $site_id );

		// Load this site's options. CampTix and the Stripe addon both cache
		// options on the request, and many code paths read those caches
		// directly (notably `$camptix->options` in payment_result() and
		// email_tickets()). Refreshing both caches here keeps those reads
		// pointing at the switched-to site.
		$camptix->load_options();
		$this->load_options();

		$response = $this->process_webhook_session_for_current_site( $event, $stripe_session_id, $payment_token );

		restore_current_blog();

		// Restore the caches to the original site for any later code in this
		// request that reads the cached options directly.
		$camptix->load_options();
		$this->load_options();

		return $response;
	}

	/**
	 * Process a webhook session after switching to the site that owns it.
	 *
	 * @param array  $event             The decoded Stripe event.
	 * @param string $stripe_session_id Stripe Checkout Session ID.
	 * @param string $payment_token     CampTix payment token.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	protected function process_webhook_session_for_current_site( $event, $stripe_session_id, $payment_token ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$order = $this->get_order( $payment_token );
		if ( ! $order ) {
			$camptix->log( 'Stripe webhook could not find an order for the payment token.', null, $event, 'stripe' );

			return new WP_REST_Response(
				array(
					'status' => 'no_order',
				),
				200
			);
		}

		$stripe  = new CampTix_Stripe_API_Client( $payment_token, $this->get_api_credentials()['api_secret_key'] );
		$session = $this->get_webhook_session_with_retry( $stripe, $stripe_session_id );

		if ( is_wp_error( $session ) ) {
			$camptix->log(
				'Stripe webhook failed to fetch the latest Checkout Session.',
				$order['attendee_id'],
				$session,
				'stripe'
			);

			return new WP_Error(
				'camptix_stripe_webhook_session_lookup_failed',
				'Could not fetch the latest Stripe Checkout Session.',
				array( 'status' => 500 )
			);
		}

		$event_type = $event['type'] ?? '';
		$event_data = array(
			'stripe_webhook_event' => array(
				'id'   => sanitize_text_field( $event['id'] ?? '' ),
				'type' => sanitize_text_field( $event_type ),
			),
		);

		$status_changed = $this->process_payment_return_session(
			$payment_token,
			$session,
			$order,
			false, /* non-interactive */
			$event_data
		);

		$camptix->log(
			'Processed Stripe webhook.',
			$order['attendee_id'],
			array(
				'event_id'       => $event['id'] ?? '',
				'event_type'     => $event_type,
				'status_changed' => $status_changed,
			),
			'stripe'
		);

		return new WP_REST_Response(
			array(
				'status'         => 'processed',
				'status_changed' => (bool) $status_changed,
			),
			200
		);
	}

	/**
	 * Retrieve a Stripe checkout session for webhook processing, retrying once on transient API errors.
	 *
	 * @param CampTix_Stripe_API_Client $stripe            The Stripe API client.
	 * @param string                    $stripe_session_id The Stripe Checkout Session ID.
	 *
	 * @return array|WP_Error
	 */
	protected function get_webhook_session_with_retry( $stripe, $stripe_session_id ) {
		$session = $stripe->get_session( $stripe_session_id );

		if ( is_wp_error( $session ) ) {
			$session = $stripe->get_session( $stripe_session_id );
		}

		return $session;
	}

	/**
	 * Get the site ID from a Checkout Session.
	 *
	 * @param array $session Stripe Checkout Session.
	 *
	 * @return int
	 */
	protected function get_site_id_from_webhook_session( $session ) {
		$client_reference = $this->get_client_reference_data_from_webhook_session( $session );

		if ( ! empty( $client_reference['site_id'] ) && get_site( $client_reference['site_id'] ) ) {
			return $client_reference['site_id'];
		}

		$url_parts = wp_parse_url( $session['success_url'] );
		if ( ! $url_parts || empty( $url_parts['host'] ) || empty( $url_parts['path'] ) ) {
			return 0;
		}

		$site = get_site_by_path( $url_parts['host'], $url_parts['path'] );

		return $site ? (int) $site->blog_id : 0;
	}

	/**
	 * Get the CampTix payment token from a Checkout Session.
	 *
	 * @param array $session Stripe Checkout Session.
	 *
	 * @return string
	 */
	protected function get_payment_token_from_webhook_session( $session ) {
		$client_reference = $this->get_client_reference_data_from_webhook_session( $session );
		if ( ! empty( $client_reference['payment_token'] ) ) {
			return $client_reference['payment_token'];
		}

		$url_parts = wp_parse_url( $session['success_url'] );
		parse_str( $url_parts['query'] ?? '', $query_args );

		return sanitize_text_field( $query_args['tix_payment_token'] ?? '' );
	}

	/**
	 * Read the CampTix data from a `site_id:payment_token` client reference ID.
	 *
	 * @param array $session Stripe Checkout Session.
	 *
	 * @return array
	 */
	protected function get_client_reference_data_from_webhook_session( $session ) {
		if ( empty( $session['client_reference_id'] ) ) {
			return array();
		}

		list( $site_id, $payment_token ) = explode( ':', $session['client_reference_id'], 2 );

		return array(
			'site_id'       => absint( $site_id ),
			'payment_token' => sanitize_text_field( $payment_token ),
		);
	}
}
