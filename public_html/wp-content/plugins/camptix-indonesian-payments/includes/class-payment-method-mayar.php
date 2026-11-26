<?php
namespace WordCamp\CampTix\IndonesianPayments;

use CampTix_Payment_Method;
use CampTix_Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/** CampTix adapter for site-scoped Mayar hosted checkout. */
class Payment_Method_Mayar extends CampTix_Payment_Method {
	public $id                   = 'mayar';
	public $name                 = 'Mayar';
	public $description          = 'Indonesian payments through Mayar hosted checkout.';
	public $supported_currencies = array( 'IDR' );
	public $options              = array();
	private $timeout_checked     = array();

	/** Listen for settlement even when new Mayar checkouts are disabled. */
	public function camptix_init() {
		$this->load_options();
		$webhook        = new Webhook_Controller( $this );
		$reconciliation = new Reconciliation( $this );
		add_action( 'rest_api_init', array( $webhook, 'register_routes' ) );
		add_action( 'camptix_mayar_reconcile', array( $reconciliation, 'run' ) );
		add_action( 'camptix_pre_attendee_timeout', array( $this, 'pre_attendee_timeout' ) );
		add_action( 'camptix_timeout_sweep_start', array( $this, 'reset_timeout_checks' ) );
		add_action( 'template_redirect', array( $webhook, 'browser_return' ), 5 );
		add_action( 'admin_post_camptix_mayar_recheck', array( $reconciliation, 'admin_recheck' ) );
		add_action( 'add_meta_boxes_tix_attendee', array( $reconciliation, 'add_meta_box' ) );
		add_action( 'admin_notices', array( $reconciliation, 'admin_notice' ) );
		add_action( 'camptix_checkout_start', array( $this, 'validate_checkout' ), 10, 2 );
	}

	/** Reject invalid totals/configuration before CampTix creates draft attendees. */
	public function validate_checkout( $attendee_info, $order ) {
		global $camptix;
		if ( 'mayar' !== ( $_POST['tix_payment_method'] ?? '' ) ) {
			return;
		}
		if ( isset( $order['total'] ) && 0.0 === (float) $order['total'] ) {
			return; // CampTix owns the free-ticket flow.
		}
		$environment = $this->options['sandbox'] ? 'sandbox' : 'production';
		$config      = $this->configuration( $environment );
		$error       = null;
		if ( ! Mayar_Client::amount( $order['total'] ?? 0 ) ) {
			$error = $this->error( 'amount' );
		} elseif ( empty( $this->camptix_options['payment_methods']['mayar'] ) || 'IDR' !== ( $this->camptix_options['currency'] ?? '' ) || ! $config['key'] || ! wp_is_uuid( $config['merchant'] ) ) {
			$error = $this->error( 'configuration' );
		}
		if ( $error ) {
			$camptix->error_flag( 'payment_failed' );
			$camptix->error( $error->get_error_message() );
		}
	}

	/** Refresh settings after a site switch. */
	public function load_options() {
		parent::load_options();
		$this->options = array_merge(
			array(
				'sandbox'               => true,
				'sandbox_api_key'       => '',
				'production_api_key'    => '',
				'sandbox_merchant_id'   => '',
				'production_merchant_id' => '',
				'expiry_minutes'        => 30,
				'return_url'            => false,
			),
			$this->get_payment_options()
		);
	}

	/** Register fields inside CampTix's nonce/capability-protected settings form. */
	public function payment_settings_fields() {
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox mode', 'camptix-indonesian-payments' ), array( $this, 'field_yesno' ) );
		foreach ( array(
			'sandbox' => __( 'Sandbox', 'camptix-indonesian-payments' ), 'production' => __( 'Production', 'camptix-indonesian-payments' ),
		) as $environment => $label ) {
			/* translators: %s: environment name. */
			$this->add_settings_field_helper( $environment . '_api_key', sprintf( __( '%s API key', 'camptix-indonesian-payments' ), $label ), array( $this, 'field_secret' ) );
			/* translators: %s: environment name. */
			$this->add_settings_field_helper( $environment . '_merchant_id', sprintf( __( '%s merchant ID', 'camptix-indonesian-payments' ), $label ), array( $this, 'field_text' ) );
		}
		$this->add_settings_field_helper( 'expiry_minutes', __( 'Payment lifetime (minutes)', 'camptix-indonesian-payments' ), array( $this, 'field_lifetime' ) );
		$this->add_settings_field_helper( 'return_url', __( 'Send a return URL', 'camptix-indonesian-payments' ), array( $this, 'field_yesno' ), __( 'Enable only after confirming your Mayar account supports redirectUrl on payment requests.', 'camptix-indonesian-payments' ) );
		$this->add_settings_field_helper( 'webhook', __( 'Webhook setup', 'camptix-indonesian-payments' ), array( $this, 'field_webhooks' ) );
	}

	/** Never echo a saved secret into HTML. A blank input keeps the saved value. */
	public function field_secret( $args ) {
		printf( '<input type="password" name="%s" value="" autocomplete="new-password" class="regular-text" />', esc_attr( $args['name'] ) );
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key. Use a Read & Write API key.', 'camptix-indonesian-payments' ) . '</p>';
	}

	/** Render integer minutes rather than a currency field. */
	public function field_lifetime( $args ) {
		printf( '<input type="number" name="%s" value="%d" min="5" max="1440" step="1" />', esc_attr( $args['name'] ), (int) $args['value'] );
	}

	/** Show per-environment secrets only to users who can access CampTix settings. */
	public function field_webhooks() {
		echo '<p>' . esc_html__( 'Register the matching URL manually in Mayar → Integration → Webhook. Use a dedicated merchant webhook configuration for this site. Keep these secret URLs private. Confirm that the merchant settles in IDR before enabling payments.', 'camptix-indonesian-payments' ) . '</p>';
		foreach ( array( 'sandbox', 'production' ) as $environment ) {
			printf( '<p><strong>%s</strong><br /><code>%s</code></p>', esc_html( ucfirst( $environment ) ), esc_html( Webhook_Controller::url( $environment ) ) );
		}
	}

	/** Retain old credentials on blank/invalid input and whitelist editable fields. */
	public function validate_options( $input ) {
		$output = $this->options;
		if ( ! is_array( $input ) ) {
			return $output;
		}
		foreach ( array( 'sandbox', 'return_url' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
				$output[ $key ] = in_array( (string) $input[ $key ], array( '1', 'yes' ), true );
			}
		}
		foreach ( array( 'sandbox', 'production' ) as $environment ) {
			$key = $environment . '_api_key';
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
				$value = trim( wp_unslash( $input[ $key ] ) );
				if ( '' !== $value && ! preg_match( '/[\x00-\x20\x7f]/', $value ) ) {
					$output[ $key ] = $value;
				}
			}
			$key = $environment . '_merchant_id';
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) && wp_is_uuid( trim( $input[ $key ] ) ) ) {
				$output[ $key ] = trim( $input[ $key ] );
			}
		}
		if ( isset( $input['expiry_minutes'] ) && is_scalar( $input['expiry_minutes'] ) && preg_match( '/^\d+$/D', (string) $input['expiry_minutes'] ) ) {
			$output['expiry_minutes'] = max( 5, min( 1440, (int) $input['expiry_minutes'] ) );
		}
		return $output;
	}

	/** Initiate a payment; redirect only after the provider reference is persisted. */
	public function payment_checkout( $payment_token ) {
		global $camptix;
		$attempt = $this->start_payment( $payment_token );
		if ( is_wp_error( $attempt ) ) {
			$camptix->error( $attempt->get_error_message() );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}
		wp_redirect( $attempt['checkout_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The HTTPS host is allowlisted before persistence and before reuse.
		exit;
	}

	/** Separate initiation from redirect/exit so retries and failure paths can be tested. */
	public function start_payment( $token ) {
		global $camptix;
		if ( ! is_string( $token ) || '' === $token || empty( $this->camptix_options['payment_methods']['mayar'] ) || 'IDR' !== ( $this->camptix_options['currency'] ?? '' ) ) {
			return $this->error( 'configuration' );
		}
		$environment = $this->options['sandbox'] ? 'sandbox' : 'production';
		$config      = $this->configuration( $environment );
		if ( ! $config['key'] || ! wp_is_uuid( $config['merchant'] ) ) {
			return $this->error( 'configuration' );
		}
		$store = new Payment_Store();
		$lock  = $store->lock( $token );
		if ( ! $lock ) {
			return $this->error( 'busy' );
		}
		try {
			$attempt = $store->get( $token );
			if ( $attempt ) {
				if ( 'open' === $attempt['state'] && $attempt['expires_at'] > time() && Mayar_Client::checkout_url_allowed( $attempt['checkout_url'] ) && $attempt['fingerprint'] === $config['fingerprint'] && $attempt['environment'] === $environment ) {
					return $attempt;
				}
				return $this->error( 'existing_payment' );
			}
			$order     = $this->get_order( $token );
			$amount    = Mayar_Client::amount( $order['total'] ?? 0 );
			$attendees = $this->attendees( $token );
			if ( ! $amount || empty( $order['items'] ) || ! $attendees ) {
				return $this->error( 'amount' );
			}
			foreach ( $attendees as $attendee ) {
				if ( 'draft' !== $attendee->post_status || 'mayar' !== get_post_meta( $attendee->ID, 'tix_payment_method', true ) ) {
					return $this->error( 'order' );
				}
			}
			$attempt = array(
				'token'          => $token,
				'reference'      => wp_generate_uuid4(),
				'blog_id'        => get_current_blog_id(),
				'environment'    => $environment,
				'merchant_id'    => $config['merchant'],
				'fingerprint'    => $config['fingerprint'],
				'amount'         => $amount,
				'currency'       => 'IDR',
				'attendee_ids'   => array_map( 'intval', wp_list_pluck( $attendees, 'ID' ) ),
				'created_at'     => time(),
				'expires_at'     => time() + max( 5, min( 1440, (int) $this->options['expiry_minutes'] ) ) * MINUTE_IN_SECONDS,
				'state'          => 'creating',
				'return_key'     => bin2hex( random_bytes( 24 ) ),
				'retries'        => 0,
			);
			if ( ! $store->save( $attempt ) ) {
				return $this->error( 'storage' );
			}
			$camptix->set_order_session_expiry( $token, $attempt['expires_at'] );
			foreach ( $attempt['attendee_ids'] as $id ) {
				update_post_meta( $id, '_camptix_mayar_reference', $attempt['reference'] );
			}
			Reconciliation::schedule( $token );
			$body = array(
				'name'        => sprintf( 'CampTix %s', $attempt['reference'] ),
				'amount'      => $amount,
				'email'       => get_post_meta( $attendees[0]->ID, 'tix_receipt_email', true ),
				'description' => wp_strip_all_tags( $camptix->get_options()['event_name'] ?? 'WordCamp' ),
				'expiredAt'   => gmdate( 'Y-m-d\TH:i:s\Z', $attempt['expires_at'] ),
				'extraData'   => array( 'camptix_reference' => $attempt['reference'] ),
			);
			if ( $this->options['return_url'] ) {
				$body['redirectUrl'] = add_query_arg(
					array(
						'camptix_mayar_return' => $attempt['reference'],
						'key'                => $attempt['return_key'],
					),
					$camptix->get_tickets_url()
				);
			}
			$client = new Mayar_Client( $environment, $config['key'] );
			$result = $client->request( 'POST', '/payments/create', $body );
			if ( is_wp_error( $result ) || ! is_array( $result ) || ! wp_is_uuid( $result['id'] ?? '' ) || ! wp_is_uuid( $result['transactionId'] ?? '' ) || ! Mayar_Client::checkout_url_allowed( $result['link'] ?? null ) ) {
				$attempt['state'] = 'uncertain';
				$store->save( $attempt );
				$this->review( $attempt, 'creation_uncertain' );
				return $this->error( 'creation_uncertain' );
			}
			$attempt['payment_id']     = $result['id'];
			$attempt['transaction_id'] = $result['transactionId'];
			$attempt['checkout_url']   = $result['link'];
			$attempt['state']          = 'open';
			if ( ! $store->save( $attempt ) ) {
				$this->review( $attempt, 'creation_uncertain' );
				return $this->error( 'creation_uncertain' );
			}
			return $attempt;
		} finally {
			$store->unlock( $lock );
		}
	}

	/** Authoritative verifier shared by webhook, browser, cron, and administrator. */
	public function verify( $token, $candidate_transaction = '' ) {
		global $camptix;
		$store = new Payment_Store();
		$lock  = $store->lock( $token );
		if ( ! $lock ) {
			return $this->error( 'busy' );
		}
		try {
			$attempt = $store->get( $token );
			if ( ! $attempt || get_current_blog_id() !== $attempt['blog_id'] ) {
				return $this->error( 'order' );
			}
			if ( in_array( $attempt['state'], array( 'complete', 'review' ), true ) ) {
				return $attempt['state'];
			}
			$config = $this->configuration( $attempt['environment'] );
			if ( $attempt['fingerprint'] !== $config['fingerprint'] || $attempt['merchant_id'] !== $config['merchant'] ) {
				$this->review( $attempt, 'credentials_changed' );
				return $this->error( 'credentials_changed' );
			}
			$id = $attempt['transaction_id'] ?? $candidate_transaction;
			if ( ! is_string( $id ) || ! wp_is_uuid( $id ) ) {
				return $this->error( 'unknown_transaction' );
			}
			$client      = new Mayar_Client( $attempt['environment'], $config['key'] );
			$transaction = $client->request( 'GET', '/transactions/' . rawurlencode( $id ) );
			if ( is_wp_error( $transaction ) ) {
				return $transaction;
			}
			if ( ! is_array( $transaction ) ) {
				return $this->error( 'mismatch' );
			}
			$payment_id = $attempt['payment_id'] ?? ( $transaction['paymentLink']['id'] ?? '' );
			if ( ! is_string( $payment_id ) || ! wp_is_uuid( $payment_id ) ) {
				return $this->error( 'mismatch' );
			}
			$payment = $client->request( 'GET', '/payments/' . rawurlencode( $payment_id ) );
			if ( is_wp_error( $payment ) ) {
				return $payment;
			}
			if ( ! $this->matches( $attempt, $id, $payment_id, $transaction, $payment ) ) {
				$this->review( $attempt, 'verification_mismatch' );
				return $this->error( 'mismatch' );
			}
			$attempt['transaction_id'] = $id;
			$attempt['payment_id']     = $payment_id;
			if ( ! $store->save( $attempt ) ) {
				$this->review( $attempt, 'transaction_conflict' );
				return $this->error( 'mismatch' );
			}
			$status = $transaction['status'] ?? '';
			if ( 'paid' !== $status ) {
				if ( ! in_array( $status, array( 'unpaid', 'created', 'expired' ), true ) ) {
					return $this->error( 'unknown_status' );
				}
				if ( time() >= $attempt['expires_at'] && in_array( $status, array( 'expired', 'unpaid', 'created' ), true ) ) {
					if ( 'expired' !== $attempt['state'] ) {
						$closed = $client->request( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/close' );
						if ( is_wp_error( $closed ) ) {
							return $closed;
						}
					}
					$attempt['state'] = 'expired';
					$store->save( $attempt );
					return 'expired';
				}
				return 'unpaid';
			}

			$order = $this->get_order( $token );
			if ( ! $camptix->lock_checkout_rows( wp_list_pluck( $order['items'] ?? array(), 'id' ) ) ) {
				return $this->error( 'busy' );
			}
			try {
				$attendees = $this->attendees( $token );
				$ids       = array_map( 'intval', wp_list_pluck( $attendees, 'ID' ) );
				sort( $ids );
				$expected = $attempt['attendee_ids'];
				sort( $expected );
				if ( $ids !== $expected || Mayar_Client::amount( $order['total'] ?? 0 ) !== $attempt['amount'] ) {
					$this->review( $attempt, 'order_changed' );
					return $this->error( 'order' );
				}
				foreach ( $attendees as $attendee ) {
					if ( 'mayar' !== get_post_meta( $attendee->ID, 'tix_payment_method', true ) ) {
						return $this->error( 'order' );
					}
					if ( ! in_array( $attendee->post_status, array( 'draft', 'publish' ), true ) || ( 'draft' === $attendee->post_status && $camptix->get_draft_release_at( $attendee->ID ) <= time() ) ) {
						$attempt['state'] = 'review';
						$store->save( $attempt );
						$this->review( $attempt, 'paid_after_release' );
						return 'review';
					}
				}
				$camptix->payment_result(
					$token,
					CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
					array(
						'transaction_id'      => $id,
						'transaction_details' => array(
							'provider'    => 'mayar',
							'payment_id'  => $payment_id,
							'amount'      => $attempt['amount'],
							'currency'    => 'IDR',
							'environment' => $attempt['environment'],
						),
					),
					false
				);
				$attempt['state'] = 'complete';
				$store->save( $attempt );
				foreach ( $ids as $attendee_id ) {
					delete_post_meta( $attendee_id, '_camptix_mayar_review' );
				}
				wp_clear_scheduled_hook( 'camptix_mayar_reconcile', array( $token ) );
				return 'complete';
			} finally {
				$camptix->release_checkout_rows();
			}
		} finally {
			$store->unlock( $lock );
		}
	}

	/** Require stored identity, merchant ownership, amount, and provider-side association. */
	private function matches( $attempt, $transaction_id, $payment_id, $transaction, $payment ) {
		if ( ! is_array( $payment ) || ! is_array( $transaction ) ) {
			return false;
		}
		foreach ( array( $transaction, $payment ) as $record ) {
			if ( Mayar_Client::amount( $record['amount'] ?? 0 ) !== $attempt['amount'] || ( isset( $record['currency'] ) && 'IDR' !== $record['currency'] ) ) {
				return false;
			}
		}
		$reference = $transaction['extraData']['camptix_reference'] ?? null;
		if ( null !== $reference && $reference !== $attempt['reference'] ) {
			return false;
		}
		// Recovery of an uncertain creation requires provider-side correlation, not webhook assertions.
		if ( empty( $attempt['transaction_id'] ) && $reference !== $attempt['reference'] ) {
			return false;
		}
		return ( $transaction['id'] ?? '' ) === $transaction_id
			&& ( $transaction['paymentLink']['id'] ?? '' ) === $payment_id
			&& ( $transaction['user']['id'] ?? '' ) === $attempt['merchant_id']
			&& ( $payment['id'] ?? '' ) === $payment_id
			&& ( $payment['userId'] ?? '' ) === $attempt['merchant_id']
			&& ( $payment['type'] ?? '' ) === 'payment_request';
	}

	/** A list with explicit statuses also finds refunded and timed-out orders. */
	public function attendees( $token ) {
		return get_posts( array(
			'post_type' => 'tix_attendee', 'post_status' => array( 'draft', 'pending', 'publish', 'cancel', 'failed', 'refund', 'timeout' ), 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => 'tix_payment_token', 'meta_value' => $token,
		) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/** Retain the original environment even if new checkouts switch modes. */
	private function configuration( $environment ) {
		$key      = $this->options[ $environment . '_api_key' ] ?? '';
		$merchant = $this->options[ $environment . '_merchant_id' ] ?? '';
		return array(
			'key' => $key, 'merchant' => $merchant, 'fingerprint' => hash( 'sha256', $key . ':' . $merchant ),
		);
	}

	/** Record an actionable admin marker without logging tokens, secrets, or attendee data. */
	public function review( $attempt, $reason ) {
		foreach ( $attempt['attendee_ids'] as $id ) {
			update_post_meta( $id, '_camptix_mayar_review', $reason );
		}
	}

	/** Check a multi-ticket order only once during a timeout sweep. */
	public function pre_attendee_timeout( $attendee_id ) {
		if ( 'mayar' !== get_post_meta( $attendee_id, 'tix_payment_method', true ) ) {
			return;
		}
		$token = get_post_meta( $attendee_id, 'tix_payment_token', true );
		if ( ! $token || isset( $this->timeout_checked[ $token ] ) ) {
			return;
		}
		$this->timeout_checked[ $token ] = true;
		$this->verify( $token );
	}

	/** Reset per-sweep deduplication in long-lived cron processes. */
	public function reset_timeout_checks() {
		$this->timeout_checked = array();
	}

	/** User messages intentionally do not contain the raw provider response. */
	private function error( $code ) {
		$messages = array(
			'amount'             => __( 'Mayar requires a positive order total in whole Indonesian rupiah.', 'camptix-indonesian-payments' ),
			'configuration'      => __( 'Mayar is not configured for this IDR checkout. Please contact the organizers.', 'camptix-indonesian-payments' ),
			'existing_payment'   => __( 'This order already has a Mayar payment attempt. Please contact the organizers before paying again.', 'camptix-indonesian-payments' ),
			'creation_uncertain' => __( 'The Mayar payment could not be confirmed. Please contact the organizers before starting another payment.', 'camptix-indonesian-payments' ),
		);
		return new WP_Error( 'mayar_' . $code, $messages[ $code ] ?? __( 'The Mayar payment needs another verification. Please try again shortly or contact the organizers.', 'camptix-indonesian-payments' ) );
	}
}
