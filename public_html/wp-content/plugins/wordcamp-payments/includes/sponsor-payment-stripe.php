<?php
/**
 * Sponsorship Payments via Stripe
 */

namespace WordCamp\Budgets\Sponsor_Payment_Stripe;
use WordCamp\Utilities\Stripe_Client;
use WordCamp\Utilities\Form_Spam_Prevention;
use WordCamp_Loader;
use WordCamp_Budgets;
use Exception;

defined( 'WPINC' ) || die();

const STEP_SELECT_INVOICE  = 1;
const STEP_PAYMENT_DETAILS = 2;
const STEP_PAYMENT_SUCCESS = 3;
const JS_VERSION           = 1;
const CSS_VERSION          = 2;

/**
 * Render the payment UI
 *
 * This function is called after template_redirect when the content is about to get loaded.
 * It is invoked from the template-sponsorship-payment.php page template in the central theme.
 */
function render() {
	$keys = _get_keys();

	if ( empty( $keys['publishable'] ) || empty( $keys['secret'] ) || empty( $keys['hmac_key'] ) ) {
		wp_die( 'Invalid keys' );
	}

	require_once __DIR__ . '/wordcamp-budgets.php';

	$data = array(
		'keys'                   => $keys,
		'step'                   => STEP_SELECT_INVOICE,
		'wordcamp_query_options' => get_wordcamp_query_options(),
		'currencies'             => WordCamp_Budgets::get_currencies(),
		'errors'                 => array(),
	);

	$submitted = $_REQUEST['sponsor_payment_submit'] ?? false;

	if ( $submitted ) {
		_handle_post_data( $data ); // $data passed by ref.
	}

	wp_enqueue_style( 'wcb-sponsor-payments', plugins_url( 'css/sponsor-payments.css', __DIR__ ), array(), CSS_VERSION );
	wp_enqueue_script( 'wcb-sponsor-payments', plugins_url( 'javascript/sponsor-payments.js', __DIR__ ), array( 'jquery' ), JS_VERSION, true );

	wp_localize_script(
		'wcb-sponsor-payments',
		'WordCampSponsorPayments',
		array(
			'steps' => array(
				'select-invoice'  => STEP_SELECT_INVOICE,
				'payment-details' => STEP_PAYMENT_DETAILS,
				'payment-success' => STEP_PAYMENT_SUCCESS,
			),
		)
	);

	$fsp = new Form_Spam_Prevention( get_fsp_config() );
	add_action( 'wp_print_styles', array( $fsp, 'render_form_field_styles' ) );

	require_once dirname( __DIR__ ) . '/views/sponsor-payment/main.php';
}

/**
 * Define the configuration for the `Form_Spam_Prevention` instances.
 *
 * This type of form should be less strict, since it's less likely to be spammed, there are automated
 * fraud controls on Stripe's side to catch card testing, and there aren't emails or posts automatically
 * generated that have to be cleaned up by contributors. Before making it less strict, sponsors were
 * encountering false positives too often.
 */
function get_fsp_config() : array {
	return array(
		'score_threshold'     => 8,
		'throttle_duration'   => 5 * MINUTE_IN_SECONDS,
		'timestamp_max_range' => '0 seconds',
	);
}

/**
 * Get Stripe and HMAC keys.
 *
 * @return array Stripe and HMAC keys.
 */
function _get_keys() {
	return apply_filters(
		'wcorg_sponsor_payment_stripe',
		array(
			// Stripe API credentials.
			'publishable' => '',
			'secret'      => '',

			// An HMAC key used to sign some data in between requests.
			'hmac_key'    => '',
		)
	);
}

/**
 * Returns query options to be used with `get_wordcamps()`.
 *
 * This provides a consistent and canonical source for the query options, so that all callers are in sync.
 *
 * @return array
 */
function get_wordcamp_query_options() {
	return array(
		'post_status' => WordCamp_Loader::get_public_post_statuses(),
		'orderby'     => 'title',
		'order'       => 'asc',

		'meta_query' => array(
			array(
				'key'     => 'Start Date (YYYY-mm-dd)',
				'value'   => strtotime( '-2 years' ),
				'compare' => '>',
			),
		),
	);
}

/**
 * Handle POST-ed data
 *
 * This is where all the magic happens, forms validation, Stripe requests,
 * keys verification etc. Note that $data here is passed by reference and
 * can (and should, in some cases) be changed.
 *
 * @param array $data By-ref $data array that is passed to the view.
 */
function _handle_post_data( &$data ) {
	$step = $_REQUEST['step'] ?? 0;
	$fsp  = new Form_Spam_Prevention( get_fsp_config() );

	switch ( $step ) {
		// An invoice, event, currency and amount have been selected.
		default:
		case STEP_SELECT_INVOICE:
			$payment_type = filter_input( INPUT_POST, 'payment_type' );
			$wordcamp_id  = filter_input( INPUT_POST, 'wordcamp_id', FILTER_VALIDATE_INT );
			$invoice_id   = filter_input( INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT );
			$description  = filter_input( INPUT_POST, 'description' );
			$currency     = filter_input( INPUT_POST, 'currency' );
			$amount       = filter_input( INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT );

			if ( ! $fsp->validate_form_submission() ) {
				$data['errors'][] = 'Your form submission could not be processed. Please try again.';
				return;
			}

			switch ( $payment_type ) {
				default:
				case 'invoice':
					if ( ! $wordcamp_id ) {
						$data['errors'][] = 'Please select an event.';
						return;
					}

					// Make sure the selected WordCamp is valid.
					$valid_ids = wp_list_pluck( get_wordcamps( get_wordcamp_query_options() ), 'ID' );

					if ( ! in_array( $wordcamp_id, $valid_ids ) ) {
						$data['errors'][] = 'Please select a valid event.';
						$fsp->add_score_to_ip_address( array( 1 ) );
						return;
					}

					$wordcamp_site_id = get_wordcamp_site_id( get_post( $wordcamp_id ) );

					if ( empty( $wordcamp_site_id ) ) {
						$data['errors'][] = 'Could not find a site for this WordCamp.';
						return;
					}

					if ( ! $invoice_id ) {
						$data['errors'][] = 'Please provide a valid invoice ID.';
						return;
					}

					$description = sprintf( 'WordCamp Sponsorship: %s', get_wordcamp_name( $wordcamp_site_id ) );

					break;

				case 'other':
					$description = substr( sanitize_text_field( $description ), 0, 100 );

					if ( ! $description ) {
						$data['errors'][] = 'Please describe the purpose of the payment.';
						return;
					}
					break;
			}

			if ( ! $currency ) {
				$data['errors'][] = 'Please select a currency.';
				return;
			}

			if ( ! array_key_exists( $currency, $data['currencies'] ) || false !== strpos( $currency, 'null' ) ) {
				$data['errors'][] = 'Invalid currency.';
				$fsp->add_score_to_ip_address( array( 1 ) );
				return;
			}

			$amount = round( $amount, 2 );

			if ( ! $amount ) {
				$data['errors'][] = 'Please enter a payment amount.';
				return;
			}

			if ( $amount < 1.00 ) {
				$data['errors'][] = 'Amount can not be less than 1.00.';
				return;
			}

			try {
				$decimal_amount = Stripe_Client::get_fractional_unit_amount( $currency, $amount );
			} catch ( Exception $e ) {
				$data['errors'][] = $e->getMessage();
				return;
			}

			$data['step']    = STEP_PAYMENT_DETAILS;
			$data['payment'] = array(
				'payment_type'   => $payment_type,
				'wordcamp_id'    => $wordcamp_id,
				'invoice_id'     => $invoice_id,
				'description'    => $description,
				'currency'       => $currency,
				'amount'         => $amount,
				'decimal_amount' => $decimal_amount,
			);

			// Passed through to the charge step.
			$data['payment_data_json']      = wp_json_encode( $data['payment'] );
			$data['payment_data_signature'] = hash_hmac( 'sha256', $data['payment_data_json'], $data['keys']['hmac_key'] );

			// Add a WordCamp object for convenience.
			$data['payment']['wordcamp_obj'] = get_post( $wordcamp_id );

			$stripe = new Stripe_Client( $data['keys']['secret'] );
			try {
				$session = $stripe->create_session( array(
					'ui_mode'    => 'embedded',
					'mode'       => 'payment',
					'return_url' => add_query_arg(
						array(
							'step'                   => STEP_PAYMENT_DETAILS,
							'sponsor_payment_submit' => 1,
							'session_id'             => '{CHECKOUT_SESSION_ID}',
							'payment_data_json'      => urlencode( $data['payment_data_json'] ),
							'payment_data_signature' => urlencode( $data['payment_data_signature'] ),
						),
						get_permalink()
					),
					'line_items' => array(
						array(
							'quantity'   => 1,
							'price_data' => array(
								'product_data' => array(
									'name'        => 'WordPress Community Support, PBC',
									// TODO: Old checkout uses "Event Sponsorship Payment" here instead of the description.
									'description' => $data['payment']['description'],
								),
								'unit_amount'  => $data['payment']['decimal_amount'],
								'currency'     => $data['payment']['currency'],
							),
						),
					),
					'metadata' => array(
						'invoice_id'       => $data['payment']['invoice_id'],
						'wordcamp_id'      => $data['payment']['wordcamp_id'],
						'wordcamp_site_id' => $data['payment']['wordcamp_id'],
						'wordcamp_url'     => set_url_scheme( esc_url_raw( get_blog_option( $data['payment']['wordcamp_id'], 'home', '' ) ), 'https' ),
					)
				) );

				if ( ! empty( $session->error ) ) {
					$data['step']     = STEP_SELECT_INVOICE;
					$data['errors'][] = $session->error->message;
					return;
				}

				$data['session_secret'] = $session->client_secret;
			} catch ( Exception $e ) {
				// Reset back to the start.
				$data['step']     = STEP_SELECT_INVOICE;
				$data['errors'][] = 'Payment initialization failed.';
				return;
			}

			break;

		// The card details have been entered and Stripe has submitted our form.
		case STEP_PAYMENT_DETAILS:
			$payment_data_json      = wp_unslash( $_REQUEST['payment_data_json'] ?? '' );
			$payment_data_signature = wp_unslash( $_REQUEST['payment_data_signature'] ?? '' );
			$session_id             = wp_unslash( $_REQUEST['session_id'] ?? '' );

			if ( ! $payment_data_json || ! $payment_data_signature ) {
				$data['errors'][] = 'Payment data is missing.';
				return;
			}

			// Make sure our data hasn't been altered.
			if ( ! hash_equals( hash_hmac( 'sha256', $payment_data_json, $data['keys']['hmac_key'] ), $payment_data_signature ) ) {
				$data['errors'][] = 'Could not verify payload signature.';
				return;
			}

			// Fetch the Session.
			try {
				$stripe  = new Stripe_Client( $data['keys']['secret'] );
				$session = $stripe->retrieve_session( $session_id );
			} catch ( Exception $e ) {
				// This is handled by the empty() check below.
			}

			if ( empty( $session ) ) {
				$data['errors'][] = 'Session data is missing.';
				return;
			}

			// Payment failure, head back a step.
			if ( 'open' === $session->status ) {
				$data['step']                    = STEP_PAYMENT_DETAILS;
				$data['payment']                 = json_decode( $payment_data_json, true );
				$data['payment']['wordcamp_obj'] = get_post( $data['payment']['wordcamp_id'] );
				$data['session_secret']          = $session->client_secret;

				$fsp->add_score_to_ip_address( array( 1 ) );
				return;
			}

			// All good!
			$data['step'] = STEP_PAYMENT_SUCCESS;
			$fsp->add_score_to_ip_address( array( -1 ) );
			break;
	}
}
