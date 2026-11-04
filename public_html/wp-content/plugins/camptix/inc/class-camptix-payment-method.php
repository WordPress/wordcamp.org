<?php

/**
 * Payment Method Class for CampTix
 *
 * @since 1.2
 */
abstract class CampTix_Payment_Method extends CampTix_Addon {
	public $id                   = false;
	public $name                 = false;
	public $description          = false;
	public $supported_currencies = array();
	public $supported_features   = array(
		'refund-single'  => false,
		'refund-all'     => false,
		'refund-partial' => false,
		'payment-lookup' => false,
	);
	public $camptix_options;

	/**
	 * Results of reconcile_payment(), telling the timeout sweep what to do with a draft order.
	 */
	// The gateway found a payment and settled the order through payment_result().
	const LOOKUP_SETTLED = 'settled';
	// The gateway session can still be paid until `expires_at`: keep the order's seats until then.
	const LOOKUP_OPEN = 'open';
	// No payment can still complete: let the sweep time the order out.
	const LOOKUP_EXPIRED = 'expired';
	// The gateway answered, but not in a way that says what became of the payment: retry next sweep.
	const LOOKUP_UNKNOWN = 'unknown';
	// The gateway could not be reached: retry next sweep, and don't ask it again this pass.
	const LOOKUP_UNREACHABLE = 'unreachable';

	/**
	 * Constructor
	 */
	function __construct() {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		parent::__construct();

		add_filter( 'camptix_available_payment_methods', array( $this, '_camptix_available_payment_methods' ) );
		add_filter( 'camptix_validate_options', array( $this, '_camptix_validate_options' ) );
		add_filter( 'camptix_get_payment_method_by_id', array( $this, '_camptix_get_payment_method_by_id' ), 10, 2 );

		$payment_method = get_class( $this );

		if ( ! $this->id ) {
			wp_die( "ID not specified in $payment_method." );
		}

		if ( ! $this->name ) {
			wp_die( "Name not specified in $payment_method." );
		}

		if ( ! $this->description ) {
			wp_die( "Description not specified in $payment_method." );
		}

		if ( ! is_array( $this->supported_currencies ) || count( $this->supported_currencies ) < 1 ) {
			wp_die( "Supported currencies not specified in $payment_method." );
		}

		$this->camptix_options = $camptix->get_options();
		if (
				array_key_exists( 'payment_methods', $this->camptix_options )
				&& array_key_exists( $this->id, $this->camptix_options['payment_methods'] )
				&& $this->camptix_options['payment_methods'][ $this->id ]
		) {
			add_filter( 'camptix_supported_currencies', array( $this, 'add_supported_currency' ) );
		}
	}

	/**
	 * Add currencies supported by this plugin to the global list of currencies. Payment addons should not override
	 * this method, but instead define an array `$supported_currencies`
	 *
	 * @param $currencies
	 *
	 * @return array
	 */
	function add_supported_currency( $currencies ) {
		return array_merge( $this->supported_currencies, $currencies );
	}

	/**
	 * Handle calls to inaccessible methods
	 *
	 * In the past, this class duplicated some methods from the CampTix_Plugin class, and had wrappers that would
	 * do nothing except call public methods in the CampTix_Plugin class. That is unnecessary and undesirable,
	 * though, as those methods should just be called directly. Those methods were removed, and this method was added
	 * to maintain backwards-compatibility with any addons that are still calling the methods on this class.
	 *
	 * @param string $name
	 * @param array  $arguments
	 *
	 * @return mixed the function result, or false on error.
	 */
	public function __call( $name, $arguments ) {
		/** @var $camptix Camptix_Plugin */
		global $camptix;

		// Whitelist the methods we want to use to avoid unintentionally calling CampTix_Plugin methods in case of typos, etc
		$camptix_methods = array( 'payment_result', 'redirect_with_error_flags', 'error_flag', 'get_tickets_url', 'log', 'field_text', 'field_checkbox', 'field_yesno' );

		if ( in_array( $name, $camptix_methods ) ) {
			// Set a default value for the log $module parameter
			if ( 'log' == $name && empty( $arguments[4] ) ) {
				$arguments[4] = 'payment';
			}

			return call_user_func_array( array( $camptix, $name ), $arguments );
		} else {
			trigger_error( sprintf( 'Call to undefined method %s::%s()', get_class( $this ), $name ), E_USER_ERROR );
		}
	}

	/**
	 * Check if the payment method supports the given currency
	 *
	 * @param string $currency
	 *
	 * @return bool
	 */
	function supports_currency( $currency ) {
		return in_array( $currency, $this->supported_currencies );
	}

	/**
	 * Check if the payment method supports the given feature
	 *
	 * @param string $feature
	 *
	 * @return bool
	 */
	function supports_feature( $feature ) {
		return array_key_exists( $feature, $this->supported_features ) ? $this->supported_features[ $feature ] : false;
	}

	/**
	 * Get the payment gateway object for the given ID
	 *
	 * @param CampTix_Payment_Method $payment_method
	 * @param string                 $id
	 *
	 * @return CampTix_Payment_Method
	 */
	function _camptix_get_payment_method_by_id( $payment_method, $id ) {
		if ( $this->id == $id ) {
			$payment_method = $this;
		}

		return $payment_method;
	}

	/**
	 * Render the section header markup on the Payment screen
	 */
	function _camptix_settings_section_callback() {
		echo '<p>' . $this->description . '</p>';
		printf( '<p>' . __( 'Supported currencies: <code>%s</code>.', 'wordcamporg' ) . '</p>', implode( '</code>, <code>', $this->supported_currencies ) );
	}

	/**
	 * What a payment method must support before it can be enabled for an event, as feature
	 * names and what to call them. See docs/camptix-payment-gateways.md.
	 *
	 * @return string[]
	 */
	public static function get_required_features() {
		/**
		 * Filters what a payment method must support before it can be newly enabled.
		 *
		 * @param string[] $features Feature names and what to call them.
		 */
		return (array) apply_filters(
			'camptix_payment_method_required_features',
			array(
				'refund-single'  => __( 'refunds', 'wordcamporg' ),
				'payment-lookup' => __( 'payment lookup', 'wordcamporg' ),
			)
		);
	}

	/**
	 * The required features this payment method doesn't support.
	 *
	 * @return string[] What to call each one.
	 */
	public function get_missing_requirements() {
		$missing = array();

		foreach ( self::get_required_features() as $feature => $label ) {
			if ( ! $this->supports_feature( $feature ) ) {
				$missing[ $feature ] = $label;
			}
		}

		return $missing;
	}

	/**
	 * Render the markup for the Enabled button
	 *
	 * A payment method missing a required feature can't be newly enabled. One already enabled
	 * stays switchable, with a warning, so an event mid-sale isn't cut off.
	 *
	 * @param array $args
	 */
	function _camptix_settings_enabled_callback( $args = array() ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$missing = $this->get_missing_requirements();

		if ( in_array( $this->camptix_options['currency'], $this->supported_currencies ) && ( ! $missing || ! empty( $args['value'] ) ) ) {
			$camptix->field_yesno( $args );

			if ( $missing ) {
				?>
				<p class="description">
					<?php printf(
						/* translators: %s: Missing features, e.g. "refunds, payment lookup". */
						esc_html__( 'This payment method does not support %s, which every payment method now must. It stays available while enabled, but once disabled it cannot be enabled again.', 'wordcamporg' ),
						esc_html( implode( ', ', $missing ) )
					); ?>
				</p>
				<?php
			}
		} elseif ( $missing && in_array( $this->camptix_options['currency'], $this->supported_currencies ) ) {
			esc_html_e( 'Disabled', 'wordcamporg' );

			?>
			<p class="description">
				<?php printf(
					/* translators: %s: Missing features, e.g. "refunds, payment lookup". */
					esc_html__( 'This payment method cannot be enabled: it does not support %s.', 'wordcamporg' ),
					esc_html( implode( ', ', $missing ) )
				); ?>
			</p>
			<?php
		} else {
			_e( 'Disabled', 'wordcamporg' );

			?>

			<p class="description">
				<?php printf(
					__( '%s is not supported by this payment method.', 'wordcamporg' ),
					'<code>' . esc_html( $this->camptix_options['currency'] ) . '</code>'
				); ?>
			</p>

			<?php
		}
	}

	/**
	 * Validate options if they were submitted for this payment method
	 *
	 * @param array $camptix_options
	 *
	 * @return array
	 */
	function _camptix_validate_options( $camptix_options ) {
		$post_key   = "camptix_payment_options_{$this->id}";
		$option_key = "payment_options_{$this->id}";

		if ( ! isset( $_POST[ $post_key ] ) ) {
			return $camptix_options;
		}

		$input = $_POST[ $post_key ];
		$output                         = $this->validate_options( $input );
		$camptix_options[ $option_key ] = $output;

		return $camptix_options;
	}

	/**
	 * Validate new option values before saving
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	function validate_options( $input ) {
		return array();
	}

	/**
	 * Handle the checkout process
	 *
	 * @param string $payment_token
	 *
	 * @return int A payment status, e.g., PAYMENT_STATUS_CANCELLED, PAYMENT_STATUS_COMPLETED, etc
	 */
	abstract function payment_checkout( $payment_token );

	/**
	 * Handle the refund process
	 *
	 * @param string $payment_token
	 *
	 * @return int A payment status, e.g., PAYMENT_STATUS_CANCELLED, PAYMENT_STATUS_COMPLETED, etc
	 */
	function payment_refund( $payment_token ) {
		/** @var $camptix Camptix_Plugin  */
		global $camptix;

		$refund_data = array();
		$camptix->log( __FUNCTION__ . ' not implemented in payment module.', 0, null, 'refund' );

		return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED, $refund_data );
	}

	/**
	 * Refund part of a payment, leaving the order's other tickets paid.
	 *
	 * Gateways that implement this must also set the `refund-partial` feature. Unlike
	 * payment_refund(), this does not call payment_result(): CampTix records the result
	 * only on the attendees whose tickets were refunded.
	 *
	 * @param string $payment_token
	 * @param float  $amount            The amount to refund, in the currency's base unit (e.g. dollars).
	 * @param bool   $refunds_remainder Whether this refunds everything still paid on the order.
	 *
	 * @return array {
	 *     @type int    $status                     PAYMENT_STATUS_REFUNDED on success.
	 *     @type string $refund_transaction_id      The gateway's ID for the refund.
	 *     @type mixed  $refund_transaction_details The gateway's response.
	 * }
	 */
	public function payment_refund_partial( $payment_token, $amount, $refunds_remainder ) {
		/** @var $camptix Camptix_Plugin  */
		global $camptix;

		$camptix->log( __FUNCTION__ . ' not implemented in payment module.', 0, null, 'refund' );

		return array(
			'status'                     => CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,
			'refund_transaction_id'      => null,
			'refund_transaction_details' => array(),
		);
	}

	/**
	 * Send a request for a refund to the payment gateway API
	 *
	 * @param string $payment_token
	 *
	 * @return array
	 */
	function send_refund_request( $payment_token ) {
		/** @var $camptix Camptix_Plugin  */
		global $camptix;

		$result = array(
			'token' => $payment_token,
			'status' => CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,
			'refund_transaction_id' => null,
			'refund_transaction_details' => array(),
		);

		$camptix->log( __FUNCTION__ . ' not implemented in payment module.', 0, null, 'refund' );
		return $result;
	}

	/**
	 * Ask the gateway what became of an order's payment, before the timeout sweep releases it.
	 *
	 * Gateways that implement this must also set the `payment-lookup` feature. The sweep calls
	 * it at most once per order per pass, for orders that are still in draft and due to time
	 * out. A payment found complete, pending or failed must be settled here, through the same
	 * validated path as the gateway's own return or notification handler (so amount and order
	 * checks apply), and reported as LOOKUP_SETTLED. Everything else is reported, and the sweep
	 * does the bookkeeping: see CampTix_Plugin::reconcile_draft_before_timeout().
	 *
	 * @param string $payment_token CampTix payment token of a draft order.
	 *
	 * @return array {
	 *     @type string $status     One of the LOOKUP_* constants.
	 *     @type int    $expires_at For LOOKUP_OPEN, when the gateway session stops being payable.
	 *     @type string $reason     For the log: why the order is kept, or what was found.
	 *     @type mixed  $data       For the log: the gateway's answer, without secrets.
	 * }
	 */
	public function reconcile_payment( $payment_token ) {
		return $this->lookup_result( self::LOOKUP_EXPIRED, 'payment lookup not implemented in payment module' );
	}

	/**
	 * Build a reconcile_payment() result.
	 *
	 * @param string $status     One of the LOOKUP_* constants.
	 * @param string $reason     For the log.
	 * @param mixed  $data       For the log.
	 * @param int    $expires_at For LOOKUP_OPEN.
	 *
	 * @return array
	 */
	protected function lookup_result( $status, $reason = '', $data = null, $expires_at = 0 ) {
		return array(
			'status'     => $status,
			'expires_at' => (int) $expires_at,
			'reason'     => $reason,
			'data'       => $data,
		);
	}

	/**
	 * Register settings for the Payment screen
	 */
	function payment_settings_fields() {
	}

	/**
	 * Add the current payment method to the list of available methods
	 *
	 * @param array $payment_methods
	 *
	 * @return array
	 */
	function _camptix_available_payment_methods( $payment_methods ) {
		if ( $this->id && $this->name && $this->description ) {
			$payment_methods[ $this->id ] = array(
				'name' => $this->name,
				'description' => $this->description,
			);
		}

		return $payment_methods;
	}

	/**
	 * Get the order for the given payment token
	 *
	 * @param string $payment_token
	 *
	 * @return array
	 */
	function get_order( $payment_token = false ) {
		if ( ! $payment_token ) {
			return array();
		}

		$attendees = get_posts( array(
			'posts_per_page' => 1,
			'post_type'      => 'tix_attendee',
			'post_status'    => 'any',
			'meta_query'     => array(
				array(
					'key'     => 'tix_payment_token',
					'compare' => '=',
					'value'   => $payment_token,
					'type'    => 'CHAR',
				),
			),
		) );

		if ( ! $attendees ) {
			return array();
		}

		return $this->get_order_by_attendee_id( $attendees[0]->ID );
	}

	/**
	 * Get the order for the given attendee
	 *
	 * @param int $attendee_id
	 *
	 * @return array
	 */
	function get_order_by_attendee_id( $attendee_id ) {
		$order = (array) get_post_meta( $attendee_id, 'tix_order', true );

		if ( $order ) {
			$order['attendee_id'] = $attendee_id;
		}

		return $order;
	}

	/**
	 * Get an escaped field name for a setting
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	function settings_field_name_attr( $name ) {
		return esc_attr( "camptix_payment_options_{$this->id}[{$name}]" );
	}

	/**
	 * Add a setting field
	 *
	 * @param string   $option_name
	 * @param string   $title
	 * @param callable $callback
	 * @param string   $description
	 */
	function add_settings_field_helper( $option_name, $title, $callback, $description = '' ) {
		add_settings_field(
			'camptix_payment_' . $this->id . '_' . $option_name,
			$title,
			$callback,
			'camptix_options',
			'payment_' . $this->id,
			array(
				'name' => $this->settings_field_name_attr( $option_name ),
				'value' => $this->options[ $option_name ] ?? '',
				'description' => $description,
			)
		);
	}

	/**
	 * Get this payment method's options
	 *
	 * @return array
	 */
	function get_payment_options() {
		$payment_options = array();
		$option_key      = "payment_options_{$this->id}";

		if ( isset( $this->camptix_options[ $option_key ] ) ) {
			$payment_options = (array) $this->camptix_options[ $option_key ];
		}

		return $payment_options;
	}

	/**
	 * Refresh `$this->camptix_options` from the current site's CampTix
	 * options.
	 *
	 * Multisite callers can call this after `switch_to_blog()` to make sure
	 * subsequent calls to `get_payment_options()` reflect the switched-to
	 * site. Subclasses that maintain their own option snapshots should
	 * override this and call `parent::load_options()` first.
	 */
	public function load_options() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$this->camptix_options = $camptix->get_options();
	}
}
