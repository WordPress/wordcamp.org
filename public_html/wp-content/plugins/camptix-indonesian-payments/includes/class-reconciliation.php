<?php
namespace WordCamp\CampTix\IndonesianPayments;

defined( 'ABSPATH' ) || exit;

/** Bounded recovery jobs and organizer diagnostics. */
class Reconciliation {
	private $gateway;

	/** Use the shared gateway verifier. */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/** One scheduled verification per order; no API polling from frontend page loads. */
	public static function schedule( $token, $delay = 300 ) {
		if ( ! wp_next_scheduled( 'camptix_mayar_reconcile', array( $token ) ) ) {
			wp_schedule_single_event( time() + $delay, 'camptix_mayar_reconcile', array( $token ) );
		}
	}

	/** Retry with a bounded backoff and leave a review marker when retries are exhausted. */
	public function run( $token ) {
		$result = $this->gateway->verify( $token );
		if ( in_array( $result, array( 'complete', 'review' ), true ) ) {
			return;
		}
		$store = new Payment_Store();
		$lock  = $store->lock( $token );
		if ( ! $lock ) {
			self::schedule( $token );
			return;
		}
		try {
			$attempt = $store->get( $token );
			if ( ! $attempt || in_array( $attempt['state'], array( 'complete', 'review' ), true ) ) {
				return;
			}
			++$attempt['retries'];
			$store->save( $attempt );
			if ( time() > $attempt['expires_at'] + 2 * DAY_IN_SECONDS || $attempt['retries'] > 24 ) {
				if ( 'expired' !== $result ) {
					$this->gateway->review( $attempt, 'verification_overdue' );
				}
				return;
			}
			self::schedule( $token, min( HOUR_IN_SECONDS, 300 * ( 2 ** min( 4, $attempt['retries'] ) ) ) );
		} finally {
			$store->unlock( $lock );
		}
	}

	/** Match CampTix's attendee-management capability and the individual post's permissions. */
	private function can_manage( $id = 0 ) {
		global $camptix;
		return current_user_can( $camptix->caps['manage_attendees'] ?? 'manage_options' ) && ( ! $id || current_user_can( 'edit_post', $id ) );
	}

	/** Rechecking is a nonce-protected organizer action, not a public payment-status endpoint. */
	public function admin_recheck() {
		$id = isset( $_GET['attendee_id'] ) && is_scalar( $_GET['attendee_id'] ) ? absint( $_GET['attendee_id'] ) : 0;
		if ( ! $id || ! $this->can_manage( $id ) || 'tix_attendee' !== get_post_type( $id ) || 'mayar' !== get_post_meta( $id, 'tix_payment_method', true ) ) {
			wp_die( esc_html__( 'You cannot recheck this payment.', 'camptix-indonesian-payments' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'camptix_mayar_recheck_' . $id, 'mayar_nonce' );
		$token     = get_post_meta( $id, 'tix_payment_token', true );
		$candidate = isset( $_POST['mayar_transaction_id'] ) && is_string( $_POST['mayar_transaction_id'] ) ? trim( wp_unslash( $_POST['mayar_transaction_id'] ) ) : '';
		$result    = $this->gateway->verify( $token, $candidate );
		if ( is_wp_error( $result ) ) {
			$store   = new Payment_Store();
			$attempt = $store->get( $token );
			if ( $attempt ) {
				$this->gateway->review( $attempt, $result->get_error_code() );
			}
		}
		wp_safe_redirect( get_edit_post_link( $id, 'raw' ) );
		exit;
	}

	/** Add diagnostics only to Mayar attendee records. */
	public function add_meta_box( $post ) {
		if ( $this->can_manage( $post->ID ) && 'mayar' === get_post_meta( $post->ID, 'tix_payment_method', true ) ) {
			add_meta_box( 'camptix-mayar', __( 'Mayar payment', 'camptix-indonesian-payments' ), array( $this, 'render_meta_box' ), 'tix_attendee', 'side' );
		}
	}

	/** Display provider IDs and a recheck button, without private payment tokens. */
	public function render_meta_box( $post ) {
		if ( ! $this->can_manage( $post->ID ) ) {
			return;
		}
		$store   = new Payment_Store();
		$attempt = $store->get( get_post_meta( $post->ID, 'tix_payment_token', true ) );
		if ( ! $attempt ) {
			return;
		}
		printf( '<p>%s: <strong>%s</strong></p>', esc_html__( 'Verification state', 'camptix-indonesian-payments' ), esc_html( $attempt['state'] ) );
		printf( '<p>%s: <code>%s</code></p>', esc_html__( 'Payment ID', 'camptix-indonesian-payments' ), esc_html( $attempt['payment_id'] ?? '—' ) );
		printf( '<p>%s: <code>%s</code></p>', esc_html__( 'Transaction ID', 'camptix-indonesian-payments' ), esc_html( $attempt['transaction_id'] ?? '—' ) );
		$review = get_post_meta( $post->ID, '_camptix_mayar_review', true );
		if ( $review ) {
			printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Needs review:', 'camptix-indonesian-payments' ), esc_html( $review ) );
		}
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'camptix_mayar_recheck',
					'attendee_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'camptix_mayar_recheck_' . $post->ID,
			'mayar_nonce'
		);
		if ( empty( $attempt['transaction_id'] ) ) {
			echo '<p><label>' . esc_html__( 'Transaction UUID from Mayar (uncertain creation only)', 'camptix-indonesian-payments' ) . '<input type="text" name="mayar_transaction_id" class="widefat" /></label></p>';
		}
		// This button uses the existing post-edit form, so there is no nested HTML form.
		printf( '<button type="submit" class="button" formnovalidate formaction="%s">%s</button>', esc_url( $url ), esc_html__( 'Recheck payment', 'camptix-indonesian-payments' ) );
	}

	/** Surface late/uncertain payments without emailing financial data. */
	public function admin_notice() {
		if ( ! $this->can_manage() ) {
			return;
		}
		$ids = get_posts( array(
			'post_type' => 'tix_attendee', 'post_status' => array( 'draft', 'pending', 'publish', 'cancel', 'failed', 'refund', 'timeout' ), 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_camptix_mayar_review',
		) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $ids ) {
			printf( '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>', esc_html__( 'A Mayar payment needs organizer review. Check the provider transaction before requesting another payment or issuing tickets.', 'camptix-indonesian-payments' ), esc_url( get_edit_post_link( $ids[0], 'raw' ) ), esc_html__( 'Review payment', 'camptix-indonesian-payments' ) );
		}
	}
}
