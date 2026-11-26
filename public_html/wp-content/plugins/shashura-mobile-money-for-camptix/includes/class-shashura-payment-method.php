<?php
/**
 * CampTix payment method: Shashura mobile money (MTN / Airtel Uganda).
 *
 * Buyer flow
 *  1. CampTix checkout calls payment_checkout(); we send the buyer to our phone page.
 *  2. Buyer enters a number; it is format-checked, rate-limited and verified with KYC.
 *  3. Buyer confirms the account name / network / amount; we push a PIN prompt (requestToPay).
 *  4. The waiting page polls; when Shashura reports TS with the exact order amount the
 *     order is completed and the buyer lands on CampTix's ticket page.
 *
 * Safety rules
 *  - Only Shashura's /enquiry decides that an order is paid. Browser requests and webhook
 *    bodies are only hints that tell us *when* to ask.
 *  - Paid means: a TS status enquiry for our attempt reference, with an amount matching both
 *    the requested attempt and the order total. If the attempt already has a Shashura
 *    transaction ID (tid), an enquiry returning a different tid is ignored.
 *    The external_ref field can change after settlement, so it is not compared.
 *  - Every attempt of an order is reconciled, so a buyer who approves an older prompt is
 *    still recognised (and a second paid attempt is flagged for refund).
 *  - Orders are never set to "pending" (CampTix would hold the seats forever). They stay
 *    drafts until paid, cancelled, failed or timed out by CampTix's sweep, which asks
 *    Shashura first.
 *  - Settlement runs under a per-order database lock so the webhook and the browser cannot
 *    complete the same order twice.
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;

class Shasmomo_Payment_Method extends CampTix_Payment_Method {

	public $id                   = 'shashura';
	public $name                 = 'Mobile Money (MTN / Airtel)';
	public $description          = 'Pay with MTN or Airtel Mobile Money via Shashura.';
	public $supported_currencies = array( 'UGX' );
	public $supported_features   = array(
		'refund-single'  => true,
		'refund-all'     => true,
		'refund-partial' => true,
	);

	const MAX_ATTEMPTS       = 5;           // PIN prompts per order.
	const MAX_LOOKUPS        = 8;           // KYC lookups per order.
	const LOOKUPS_PER_IP     = 20;          // KYC lookups per IP per hour.
	const LOOKUPS_PER_NUMBER = 3;           // KYC lookups per phone number per hour, across all orders.
	const LOOKUPS_ALERT      = 300;         // KYC lookups per site per hour that trigger an admin alert (never blocks).
	const RETRY_AFTER        = 150;         // Seconds before a new prompt may replace an unanswered one.
	const CONFIRM_TTL        = 900;         // Seconds a verified number stays valid for confirmation.
	const CHECKOUT_HOLD      = 900;         // Seconds CampTix holds the seats once checkout reaches us.
	const SESSION_HOLD       = 1800;        // Seconds CampTix holds the seats after a prompt is sent.
	const POLL_MIN_INTERVAL  = 3;           // Seconds between enquiries for the same attempt.
	const UNKNOWN_FAIL_AFTER = 600;         // An attempt Shashura never heard of counts as lost after this.
	const LOCK_STALE_AFTER   = 300;         // Seconds after which an abandoned lock is cleared.
	const WEBHOOK_MAX_BYTES  = 65536;
	const FINAL_STATES       = array( 'paid', 'failed', 'mismatch', 'rejected' );
	const STATUSES           = array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed', 'timeout' );

	protected $options = array();

	/** Declared here too: CampTix 1.x's base class doesn't, which is deprecated on PHP 8.2+. */
	public $camptix_options;

	/** @var array Option key => wp-config.php constant, for settings defined there. */
	private $locked = array();

	/** @var array Orders already looked at during the current timeout sweep. */
	private $swept = array();

	// ==================================================================
	// Setup
	// ==================================================================

	/**
	 * Initialize the payment method and register WordPress hooks.
	 */
	public function camptix_init() {
		$this->name        = __( 'Mobile Money (MTN / Airtel)', 'shashura-mobile-money-for-camptix' );
		$this->description = __( 'Pay with MTN or Airtel Mobile Money via Shashura.', 'shashura-mobile-money-for-camptix' );

		$this->options = array_merge(
			array(
				'client_id'      => '',
				'client_secret'  => '',
				'environment'    => 'sandbox',
				'webhook_secret' => '',
				'base_url'       => '',
				'name_display'   => 'off',
				'ip_source'      => 'remote_addr',
				'proxy_hops'     => 1,
				// Off by default: a "Powered by Shashura" credit link must never
				// appear on a buyer-facing page without the site administrator
				// explicitly opting in (WordPress.org Plugin Directory guidelines).
				'show_branding'  => false,
				'last_check'     => '',
			),
			$this->get_payment_options()
		);

		// Credentials can be kept out of the database by defining them in wp-config.php.
		foreach ( array(
			'client_id'      => 'SHASMOMO_CLIENT_ID',
			'client_secret'  => 'SHASMOMO_CLIENT_SECRET',
			'webhook_secret' => 'SHASMOMO_WEBHOOK_SECRET',
		) as $key => $constant ) {
			if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
				$this->options[ $key ] = (string) constant( $constant );
				$this->locked[ $key ]  = $constant;
			}
		}

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'camptix_timeout_sweep_start', array( $this, 'reset_sweep' ) );
		add_action( 'camptix_pre_attendee_timeout', array( $this, 'pre_attendee_timeout' ) );
		if ( method_exists( $this, 'add_supported_currency' ) ) {
			add_filter( 'camptix_supported_currencies', array( $this, 'add_supported_currency' ) );
		}
		add_filter( 'camptix_currencies', array( $this, 'ensure_ugx' ) ); // CampTix 1.x has no UGX.
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Ensure UGX is available in CampTix currencies.
	 *
	 * @param array $currencies Existing CampTix currencies.
	 * @return array
	 */
	public function ensure_ugx( $currencies ) {
		if ( ! isset( $currencies['UGX'] ) ) {
			$currencies['UGX'] = array(
				'label' => __( 'Uganda Shilling', 'shashura-mobile-money-for-camptix' ), 'format' => 'UGX %s', 'decimal_point' => 0,
			);
		}
		return $currencies;
	}

	/**
	 * Build an API client using current gateway options.
	 *
	 * @return Shasmomo_API
	 */
	private function api() {
		return new Shasmomo_API(
			$this->options['client_id'],
			$this->options['client_secret'],
			$this->options['environment'],
			$this->options['webhook_secret'],
			$this->options['base_url']
		);
	}

	/** The webhook secret is required: without it a payment made after the buyer leaves can be lost. */
	private function is_configured() {
		return '' !== $this->options['client_id'] && '' !== $this->options['client_secret'] && '' !== $this->options['webhook_secret'];
	}

	// ==================================================================
	// Settings
	// ==================================================================

	/**
	 * Register payment gateway settings fields.
	 */
	public function payment_settings_fields() {
		$this->add_settings_field_helper(
			'client_id',
			__( 'Client ID', 'shashura-mobile-money-for-camptix' ),
			array( $this, 'field_plain' ),
			__( 'Starts with PID_. Found on your app in the Shashura dashboard.', 'shashura-mobile-money-for-camptix' )
		);

		$this->add_settings_field_helper(
			'client_secret',
			__( 'Client Secret', 'shashura-mobile-money-for-camptix' ),
			array( $this, 'field_secret' ),
			__( 'Starts with PSK_. Each environment has its own secret.', 'shashura-mobile-money-for-camptix' )
		);

		$this->add_settings_field_helper(
			'environment',
			__( 'Environment', 'shashura-mobile-money-for-camptix' ),
			array( $this, 'field_environment' ),
			__( 'Use Sandbox to test. Switch to Production to take real payments.', 'shashura-mobile-money-for-camptix' )
		);

		$this->add_settings_field_helper(
			'webhook_secret',
			__( 'Webhook Secret', 'shashura-mobile-money-for-camptix' ),
			array( $this, 'field_secret' ),
			sprintf(
				/* translators: %s: callback URL */
				__( 'The app\'s webhook signing secret. On the Shashura Apps page set the Callback URL to %s', 'shashura-mobile-money-for-camptix' ),
				'<code>' . esc_html( $this->webhook_url() ) . '</code>'
			)
		);

		$this->add_settings_field_helper(
			'name_display',
			__( 'Account name shown to buyer', 'shashura-mobile-money-for-camptix' ),
			array( $this, 'field_name_display' ),
			__( 'A visitor can enter any supported number at checkout. Hide the subscriber name by default to protect third-party privacy.', 'shashura-mobile-money-for-camptix' )
		);

		$this->add_settings_field_helper(
			'ip_source',
			__( 'Visitor IP from', 'shashura-mobile-money-for-camptix' ),
			array( $this, 'field_ip_source' ),
			__( 'Used to rate-limit number checks. Choose a proxy header only if your site really sits behind that proxy: visitors can fake these headers otherwise.', 'shashura-mobile-money-for-camptix' )
		);

		$this->add_settings_field_helper(
			'proxy_hops',
			__( 'Trusted proxies', 'shashura-mobile-money-for-camptix' ),
			array( $this, 'field_proxy_hops' ),
			__( 'Only used with X-Forwarded-For: the number of proxies you control in front of the site (usually 1).', 'shashura-mobile-money-for-camptix' )
		);

		$this->add_settings_field_helper(
			'show_branding',
			__( 'Show "Powered by Shashura"', 'shashura-mobile-money-for-camptix' ),
			array( $this, 'field_show_branding' ),
			__( 'Off by default. Turning this on adds a small "Powered by Shashura" credit link to the buyer-facing payment pages.', 'shashura-mobile-money-for-camptix' )
		);

		$this->add_settings_field_helper(
			'last_check',
			__( 'Connection', 'shashura-mobile-money-for-camptix' ),
			array( $this, 'field_status' )
		);
	}

	/** Shows a note instead of the input when the setting comes from wp-config.php. */
	private function field_locked( $args ) {
		$key = preg_match( '/\[([a-z_]+)\]$/', $args['name'], $m ) ? $m[1] : '';
		if ( ! isset( $this->locked[ $key ] ) ) {
			return false;
		}
		printf(
			'<p><em>%s</em></p>',
			/* translators: %s: constant name */
			esc_html( sprintf( __( 'Set in wp-config.php (%s).', 'shashura-mobile-money-for-camptix' ), $this->locked[ $key ] ) )
		);
		return true;
	}

	/**
	 * Render a plain text settings field.
	 *
	 * @param array $args Field arguments.
	 */
	public function field_plain( $args ) {
		if ( $this->field_locked( $args ) ) {
			return;
		}
		printf( '<input type="text" class="regular-text" autocomplete="off" name="%s" value="%s" />', esc_attr( $args['name'] ), esc_attr( $args['value'] ) );
		$this->field_description( $args );
	}

	/** Secrets are never printed back into the page. Leaving the box empty keeps the saved value. */
	public function field_secret( $args ) {
		if ( $this->field_locked( $args ) ) {
			return;
		}
		$has = '' !== (string) $args['value'];
		printf(
			'<input type="password" class="regular-text" autocomplete="new-password" name="%s" value="" placeholder="%s" />',
			esc_attr( $args['name'] ),
			esc_attr( $has ? __( 'Saved. Leave blank to keep it.', 'shashura-mobile-money-for-camptix' ) : '' )
		);
		$this->field_description( $args );
	}

	/**
	 * Render the environment selector field.
	 *
	 * @param array $args Field arguments.
	 */
	public function field_environment( $args ) {
		printf( '<select name="%s">', esc_attr( $args['name'] ) );
		foreach ( array(
			'sandbox' => __( 'Sandbox (testing)', 'shashura-mobile-money-for-camptix' ), 'production' => __( 'Production (real payments)', 'shashura-mobile-money-for-camptix' ),
		) as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $args['value'], $k, false ), esc_html( $label ) );
		}
		echo '</select>';
		$this->field_description( $args );
	}

	/**
	 * Render the account name display selector field.
	 *
	 * @param array $args Field arguments.
	 */
	public function field_name_display( $args ) {
		printf( '<select name="%s">', esc_attr( $args['name'] ) );
		foreach ( array(
			'off' => __( 'Hide account name (recommended)', 'shashura-mobile-money-for-camptix' ), 'partial' => __( 'First name and initials (e.g. Mugisha S.)', 'shashura-mobile-money-for-camptix' ), 'full' => __( 'Full name (e.g. Mugisha Samuel)', 'shashura-mobile-money-for-camptix' ),
		) as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $args['value'], $k, false ), esc_html( $label ) );
		}
		echo '</select>';
		$this->field_description( $args );
	}

	/**
	 * Render the visitor IP source selector field.
	 *
	 * @param array $args Field arguments.
	 */
	public function field_ip_source( $args ) {
		$choices = array(
			'remote_addr'     => __( 'Direct connection (REMOTE_ADDR)', 'shashura-mobile-money-for-camptix' ),
			'cloudflare'      => __( 'Cloudflare (CF-Connecting-IP)', 'shashura-mobile-money-for-camptix' ),
			'x_real_ip'       => __( 'Reverse proxy (X-Real-IP)', 'shashura-mobile-money-for-camptix' ),
			'x_forwarded_for' => __( 'Reverse proxy (X-Forwarded-For)', 'shashura-mobile-money-for-camptix' ),
		);
		printf( '<select name="%s">', esc_attr( $args['name'] ) );
		foreach ( $choices as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $args['value'], $k, false ), esc_html( $label ) );
		}
		echo '</select>';
		$this->field_description( $args );
	}

	/**
	 * Render the trusted proxy hops number field.
	 *
	 * @param array $args Field arguments.
	 */
	public function field_proxy_hops( $args ) {
		printf(
			'<input type="number" class="small-text" min="1" max="5" step="1" name="%s" value="%s" />',
			esc_attr( $args['name'] ),
			esc_attr( (string) max( 1, (int) $args['value'] ) )
		);
		$this->field_description( $args );
	}

	/**
	 * Render the "Powered by Shashura" branding checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function field_show_branding( $args ) {
		printf(
			'<label><input type="checkbox" name="%s" value="1"%s /> %s</label>',
			esc_attr( $args['name'] ),
			checked( ! empty( $args['value'] ), true, false ),
			esc_html__( 'Show the credit link', 'shashura-mobile-money-for-camptix' )
		);
		$this->field_description( $args );
	}

	/**
	 * Render the connection status field.
	 *
	 * @param array $args Field arguments.
	 */
	public function field_status( $args ) {
		if ( '' === $this->options['webhook_secret'] ) {
			echo '<p style="color:#d63638;margin:0 0 6px">&#9888; ' . esc_html__( 'Add the Webhook Secret. Mobile Money is not offered at checkout until it is set.', 'shashura-mobile-money-for-camptix' ) . '</p>';
		}
		$v = is_array( $args['value'] ) ? $args['value'] : array();
		if ( empty( $v['at'] ) ) {
			echo '<em>' . esc_html__( 'Not checked yet. Save your credentials to test them.', 'shashura-mobile-money-for-camptix' ) . '</em>';
			return;
		}
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $v['at'] );
		if ( ! empty( $v['ok'] ) ) {
			echo '<span style="color:#008a20">&#10003; ' . esc_html__( 'Connected', 'shashura-mobile-money-for-camptix' ) . '</span> <span class="description">(' . esc_html( $when ) . ')</span>';
		} else {
			echo '<span style="color:#d63638">&#10007; ' . esc_html( $v['msg'] ) . '</span> <span class="description">(' . esc_html( $when ) . ')</span>';
		}
	}

	/**
	 * Render a field's description text.
	 *
	 * @param array $args Field arguments.
	 */
	private function field_description( $args ) {
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses( $args['description'], array( 'code' => array() ) ) . '</p>';
		}
	}

	/**
	 * Validate and sanitize saved gateway options.
	 *
	 * @param array $input Raw submitted options.
	 * @return array
	 */
	public function validate_options( $input ) {
		$input  = is_array( $input ) ? wp_unslash( $input ) : array();
		$output = $this->options;

		if ( isset( $input['client_id'] ) ) {
			$output['client_id'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['client_id'] );
		}
		foreach ( array( 'client_secret', 'webhook_secret' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) {
				$output[ $key ] = trim( (string) $input[ $key ] );
			}
		}
		if ( isset( $input['environment'] ) ) {
			$output['environment'] = 'production' === $input['environment'] ? 'production' : 'sandbox';
		}
		// Saved per-site endpoints are no longer trusted, including legacy values.
		$output['base_url'] = '';
		if ( isset( $input['name_display'] ) ) {
			$output['name_display'] = in_array( $input['name_display'], array( 'off', 'partial', 'full' ), true ) ? $input['name_display'] : 'off';
		}
		if ( isset( $input['ip_source'] ) ) {
			$output['ip_source'] = in_array( $input['ip_source'], array( 'remote_addr', 'cloudflare', 'x_real_ip', 'x_forwarded_for' ), true ) ? $input['ip_source'] : 'remote_addr';
		}
		if ( isset( $input['proxy_hops'] ) ) {
			$output['proxy_hops'] = min( 5, max( 1, absint( $input['proxy_hops'] ) ) );
		}
		// Checkbox: absent from $input entirely when unchecked, so this only
		// turns on if the admin explicitly ticked it on this save.
		$output['show_branding'] = ! empty( $input['show_branding'] );

		// Test the credentials when they change (or the last test failed), so the admin sees a
		// problem now, not at the first sale, without a slow API call on every unrelated save.
		$changed = false;
		foreach ( array( 'client_id', 'client_secret', 'environment', 'base_url' ) as $key ) {
			if ( (string) $this->options[ $key ] !== (string) $output[ $key ] ) {
				$changed = true;
			}
		}
		if ( '' === $output['client_id'] || '' === $output['client_secret'] ) {
			$output['last_check'] = '';
		} elseif ( $changed || empty( $output['last_check']['ok'] ) ) {
			$api                  = new Shasmomo_API( $output['client_id'], $output['client_secret'], $output['environment'], '', $output['base_url'] );
			$result               = $api->test_credentials();
			$output['last_check'] = array(
				'at'  => time(),
				'ok'  => ! is_wp_error( $result ),
				'msg' => is_wp_error( $result ) ? $this->admin_error_text( $result ) : '',
			);
		}

		// Values from wp-config.php are used above but never written to the database.
		$stored = $this->get_payment_options();
		foreach ( array_keys( $this->locked ) as $key ) {
			$output[ $key ] = isset( $stored[ $key ] ) ? $stored[ $key ] : '';
		}
		return $output;
	}

	/**
	 * Format an API credential test error for the admin screen.
	 *
	 * @param WP_Error $e Error returned by Shasmomo_API.
	 * @return string
	 */
	private function admin_error_text( WP_Error $e ) {
		$code = Shasmomo_API::error_code( $e );
		$map  = array(
			'INVALID_SECRET'     => __( 'Client Secret is wrong for this environment.', 'shashura-mobile-money-for-camptix' ),
			'INVALID_APP'        => __( 'No Shashura app matches this Client ID and environment.', 'shashura-mobile-money-for-camptix' ),
			'IP_NOT_WHITELISTED' => __( 'This server\'s IP is not on the app\'s allow-list in Shashura.', 'shashura-mobile-money-for-camptix' ),
		);
		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		/* translators: %s: error message returned by the Shashura API */
		return sprintf( __( 'Could not connect: %s', 'shashura-mobile-money-for-camptix' ), sanitize_text_field( $e->get_error_message() ) );
	}

	// ==================================================================
	// URLs
	// ==================================================================

	/**
	 * Build a CampTix tickets-page action URL for this gateway.
	 *
	 * @param string $action CampTix action slug.
	 * @param string $token  Order payment token.
	 * @param array  $extra  Additional query args.
	 * @return string
	 */
	private function url( $action, $token, $extra = array() ) {
		global $camptix;
		return add_query_arg(
			array_merge(
				array(
					'tix_action'         => $action,
					'tix_payment_method' => $this->id,
					'tix_payment_token'  => $token,
				),
				$extra
			),
			$camptix->get_tickets_url()
		);
	}

	/**
	 * Return the REST API webhook callback URL.
	 *
	 * @return string
	 */
	private function webhook_url() {
		return rest_url( 'shasmomo/v1/webhook' );
	}

	// ==================================================================
	// Order context (state lives on the CampTix attendee posts)
	// ==================================================================

	/**
	 * Load order state from attendee post meta.
	 *
	 * @param string $token Order payment token.
	 * @return array|null { token, ids, first, status, total, attempts, pending, lookups }
	 */
	private function load( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		// The waiting page polls every few seconds, and the attendees of an order never change,
		// so the token lookup (a meta query) is cached.
		$cache_key = 'shasmomo_ids_' . $token;
		$ids       = get_transient( $cache_key );
		if ( ! is_array( $ids ) || ! $ids || false === get_post_status( (int) $ids[0] ) ) {
			$ids = $this->find_attendee_ids( $token );
			if ( $ids ) {
				set_transient( $cache_key, $ids, HOUR_IN_SECONDS );
			}
		}
		if ( ! $ids ) {
			return null;
		}
		$first = (int) $ids[0];

		// Only orders the buyer placed with this payment method.
		if ( get_post_meta( $first, 'tix_payment_method', true ) !== $this->id ) {
			return null;
		}

		$order = $this->get_order_by_attendee_id( $first );
		$total = isset( $order['total'] ) ? (int) round( (float) $order['total'] ) : 0;
		$att   = get_post_meta( $first, '_shashura_attempts', true );
		$pend  = get_post_meta( $first, '_shashura_pending', true );

		return array(
			'token'    => $token,
			'ids'      => array_map( 'intval', $ids ),
			'first'    => $first,
			'status'   => get_post_status( $first ),
			'total'    => $total,
			'attempts' => is_array( $att ) ? $att : array(),
			'pending'  => is_array( $pend ) ? $pend : array(),
			'lookups'  => (int) get_post_meta( $first, '_shashura_lookups', true ),
		);
	}

	/** Attendee IDs of an order, oldest first. */
	private function find_attendee_ids( $token ) {
		$ids = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => self::STATUSES,
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- a payment callback must resolve its own order regardless of any front-end query filter (e.g. language/exclude filters) another plugin adds.
			'suppress_filters' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- CampTix stores the payment token only as post meta; there is no other lookup path.
			'meta_query'       => array(
				array(
					'key' => 'tix_payment_token', 'value' => $token, 'compare' => '=', 'type' => 'CHAR',
				),
			),
		) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Persist a meta value across every attendee post in an order.
	 *
	 * @param array  $ctx   Order context.
	 * @param string $key   Post meta key.
	 * @param mixed  $value Meta value.
	 */
	private function save( $ctx, $key, $value ) {
		foreach ( $ctx['ids'] as $id ) {
			update_post_meta( $id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * Look up an order's payment token by Shashura attempt reference.
	 *
	 * @param string $reference Attempt reference.
	 * @return string
	 */
	private function find_token_by_reference( $reference ) {
		if ( ! is_string( $reference ) || ! preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $reference ) ) {
			return '';
		}
		$ids = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => self::STATUSES,
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- a webhook must resolve the order its own prompt reference belongs to regardless of any front-end query filter.
			'suppress_filters' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the prompt reference is only stored as post meta; there is no other lookup path.
			'meta_query'       => array(
				array(
					'key' => '_shashura_ref', 'value' => $reference, 'compare' => '=', 'type' => 'CHAR',
				),
			),
		) );
		return $ids ? (string) get_post_meta( $ids[0], 'tix_payment_token', true ) : '';
	}

	/** Masked number for an attempt, whether or not the full number is still stored. */
	private function masked( $a ) {
		if ( ! empty( $a['msisdn'] ) ) {
			return Shasmomo_Phone::mask( $a['msisdn'] );
		}
		return isset( $a['msisdn_masked'] ) ? (string) $a['msisdn_masked'] : '';
	}

	/** Once an attempt is final the full number is no longer needed: keep only the masked one. */
	private function redact( $a ) {
		if ( ! empty( $a['msisdn'] ) ) {
			$a['msisdn_masked'] = Shasmomo_Phone::mask( $a['msisdn'] );
			$a['msisdn']        = '';
		}
		return $a;
	}

	// ==================================================================
	// Lock: one settlement per order at a time (webhook vs. browser vs. sweep)
	// ==================================================================

	/**
	 * Take the order's lock. The row value carries a random owner id, so a request can only
	 * ever release its own lock (never one a later request took after a stale cleanup).
	 *
	 * @return array|false { name, value }
	 */
	private function lock( $token ) {
		global $wpdb;
		$name = 'shasmomo_lock_' . md5( $token );

		// Direct queries are required for the whole function: the lock is a race-free
		// "insert if absent" primitive (INSERT IGNORE / affected-rows check), which
		// add_option()/update_option() cannot provide, and an object cache would make
		// the single source of truth (the row) stale across requests racing for the lock.
		for ( $try = 0; $try < 2; $try++ ) {
			$value = time() . '|' . wp_generate_password( 20, false, false );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$value
			) );
			if ( 1 === (int) $ok ) {
				$lock = array(
					'name' => $name, 'value' => $value,
				);
				register_shutdown_function( array( $this, 'unlock' ), $lock ); // Released even if CampTix exits mid-way.
				return $lock;
			}
			// Clear a lock left by a crashed request. A full settlement (5 enquiries x timeout) fits well inside this.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( '' !== $held && (int) $held < time() - self::LOCK_STALE_AFTER ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $held ) );
				continue;
			}
			return false;
		}
		return false;
	}

	/**
	 * Release an order lock held by this request.
	 *
	 * @param array|false $lock Lock descriptor returned by lock().
	 */
	public function unlock( $lock ) {
		global $wpdb;
		if ( is_array( $lock ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the same race-free lock row taken in lock() above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock['name'], $lock['value'] ) );
		}
	}

	// ==================================================================
	// Reconciliation: the only place that decides an order is paid
	// ==================================================================

	/**
	 * Ask Shashura about every unsettled attempt and record the answers.
	 *
	 * @return array { paid: attempt[], latest: attempt|null, attempts }
	 */
	private function reconcile( $ctx, $force = false ) {
		global $camptix;
		$attempts = $ctx['attempts'];
		$changed  = false;
		$now      = time();
		$api      = $this->api();

		foreach ( $attempts as $i => $a ) {
			if ( in_array( $a['status'], self::FINAL_STATES, true ) ) {
				continue;
			}
			// Sent under the other environment's credentials, which this API can't query. Leave it
			// open (never "failed": the buyer may have paid) until the environment is switched back.
			if ( isset( $a['env'] ) && $a['env'] !== $this->options['environment'] ) {
				if ( empty( $a['env_warned'] ) ) {
					$a['env_warned'] = 1;
					$attempts[ $i ]  = $a;
					$changed         = true;
					$camptix->log( sprintf( 'Attempt %s was sent in %s but the environment is now %s; it cannot be checked until you switch back. Check it in the Shashura dashboard.', $a['ref'], $a['env'], $this->options['environment'] ), $ctx['first'], null, 'payment' );
				}
				continue;
			}
			$interval = 'lost' === $a['status'] ? 60 : self::POLL_MIN_INTERVAL;
			if ( ! $force && ! empty( $a['checked'] ) && $a['checked'] > $now - $interval ) {
				continue;
			}

			$r            = $api->enquiry( $a['ref'] );
			$a['checked'] = $now;

			if ( is_wp_error( $r ) ) {
				// Shashura doesn't know this reference (yet). Show it as failed so the buyer can
				// retry, but keep asking: an outage or delay must not lose a real payment.
				if ( 404 === Shasmomo_API::http_status( $r ) && $now - $a['created'] > self::UNKNOWN_FAIL_AFTER ) {
					$a['status'] = 'lost';
				}
				// Repeated polling during an outage must not flood the attendee log.
				// Persist the error class on the attempt and log only when it changes.
				$error_class = $r->get_error_code() . ':' . Shasmomo_API::http_status( $r );
				if ( ! isset( $a['enquiry_error_class'] ) || $a['enquiry_error_class'] !== $error_class ) {
					$camptix->log( 'Shashura enquiry error for ' . $a['ref'] . ': ' . $r->get_error_message(), $ctx['first'], null, 'payment' );
					$a['enquiry_error_class'] = $error_class;
				}
			} elseif ( ! empty( $a['tid'] ) && '' !== $r['tid'] && $r['tid'] !== $a['tid'] ) {
				// external_ref is overwritten with the telco's own confirmation id once a
				// payment settles, so it no longer reliably matches what was asked for — tid
				// is Shashura's own permanent identifier and is what's checked instead.
				$camptix->log(
					'Shashura enquiry returned a different transaction; ignoring.',
					$ctx['first'],
					array(
						'asked_tid' => $a['tid'],
						'got_tid'   => $r['tid'],
					),
					'payment'
				);
			} else {
				// A recovered enquiry can report a future failure again if needed.
				if ( isset( $a['enquiry_error_class'] ) ) {
					unset( $a['enquiry_error_class'] );
					$camptix->log( 'Shashura enquiry recovered for ' . $a['ref'] . '.', $ctx['first'], null, 'payment' );
				}
				$a['raw_state']   = $r['raw_state'];
				$a['tid']         = $r['tid'] ? $r['tid'] : ( isset( $a['tid'] ) ? $a['tid'] : '' );
				$a['network_ref'] = $r['network_ref'];

				if ( 'paid' === $r['state'] ) {
					if ( null === $r['amount'] ) {
						// Never complete without seeing the amount.
						$camptix->log( 'Shashura reported TS without an amount; not completing.', $ctx['first'], $r, 'payment' );
					} elseif ( $r['amount'] !== (int) $a['amount'] || $r['amount'] !== $ctx['total'] ) {
						$a['status']      = 'mismatch';
						$a['paid_amount'] = $r['amount'];
						$camptix->log( sprintf( 'Shashura amount mismatch on %s: paid %d, order total %d. Not completing.', $a['ref'], $r['amount'], $ctx['total'] ), $ctx['first'], $r, 'payment' );
					} else {
						$a['status']      = 'paid';
						$a['paid_amount'] = $r['amount'];
					}
				} elseif ( 'failed' === $r['state'] ) {
					$a['status']  = 'failed';
					$a['failure'] = $r['failure_code'];
					$camptix->log(
						sprintf( 'Attempt %s failed: %s', $a['ref'], '' !== $r['failure_code'] ? $r['failure_code'] . ( '' !== $r['failure_text'] ? ' (' . $r['failure_text'] . ')' : '' ) : 'no reason given' ),
						$ctx['first'],
						null,
						'payment'
					);
				} else {
					$a['status'] = 'pending';
				}
			}

			if ( in_array( $a['status'], self::FINAL_STATES, true ) ) {
				$a = $this->redact( $a );
			}
			$attempts[ $i ] = $a;
			$changed        = true;
		}

		if ( $changed ) {
			$this->save( $ctx, '_shashura_attempts', $attempts );
		}
		// Persist evidence before notification; final attempts are not re-enquired on replay.
		foreach ( $attempts as $i => $a ) {
			if ( 'mismatch' === $a['status'] && empty( $a['mismatch_alerted'] ) ) {
				$attempts[ $i ]['mismatch_alerted'] = time();
				$this->save( $ctx, '_shashura_attempts', $attempts );
				wp_mail(
					get_option( 'admin_email' ),
					'CampTix Mobile Money payment needs reconciliation',
					sprintf( 'Payment %s (transaction %s) collected UGX %d; expected UGX %d. Do not ask the buyer to pay again. Reconcile or refund in the gateway dashboard. Order: %s', $a['ref'], $a['tid'], $a['paid_amount'], $ctx['total'], admin_url( 'post.php?post=' . $ctx['first'] . '&action=edit' ) )
				);
			}
		}

		// Enquiries can outlast the reservation. Re-read after the network calls;
		// a plugin order lock is not CampTix's inventory/timeout lock.
		$can_fulfil = $this->reservation_retained( $ctx );
		foreach ( $attempts as $i => $a ) {
			if ( 'paid' !== $a['status'] ) {
				continue;
			}
			if ( ! $can_fulfil && empty( $a['fulfilment_hold'] ) ) {
				$attempts[ $i ]['fulfilment_hold'] = time();
				$this->save( $ctx, '_shashura_attempts', $attempts );
			}
			if ( ! empty( $attempts[ $i ]['fulfilment_hold'] ) && empty( $a['fulfilment_alerted'] ) ) {
				$attempts[ $i ]['fulfilment_alerted'] = time();
				$this->save( $ctx, '_shashura_attempts', $attempts );
				$camptix->log(
					'Mobile Money collected after the reservation was released or could not be verified. No ticket issued; manual reconciliation required.',
					$ctx['first'],
					array(
						'ref'    => $a['ref'],
						'tid'    => $a['tid'],
						'amount' => $a['paid_amount'],
					),
					'payment'
				);
				wp_mail(
					get_option( 'admin_email' ),
					'CampTix late Mobile Money payment: no ticket issued',
					sprintf( 'Payment %s (transaction %s) collected UGX %d after its reservation was released or could not be verified. No tickets were issued. Do not charge the buyer again. Check capacity and reconcile manually, or refund this collection in the Shashura dashboard. Order: %s', $a['ref'], $a['tid'], $a['paid_amount'], admin_url( 'post.php?post=' . $ctx['first'] . '&action=edit' ) )
				);
			}
		}
		$has_hold = (bool) array_filter(
			$attempts,
			function ( $a ) {
				return ! empty( $a['fulfilment_hold'] );
			}
		);
		$paid     = array_values(
			array_filter(
				$attempts,
				function ( $a ) use ( $has_hold ) {
					return ! $has_hold && 'paid' === $a['status'];
				}
			)
		);

		return array(
			'paid'     => $paid,
			'latest'   => $attempts ? end( $attempts ) : null,
			'attempts' => $attempts,
		);
	}

	/**
	 * Fail closed once a seat may have been released. Do not reallocate inventory
	 * with a count-then-publish check: that would race another checkout.
	 */
	private function reservation_retained( $ctx ) {
		global $camptix;
		foreach ( $ctx['ids'] as $id ) {
			clean_post_cache( $id );
			$status = get_post_status( $id );
			if ( in_array( $status, array( 'publish', 'pending' ), true ) ) {
				continue;
			}
			if ( 'draft' !== $status ) {
				return false;
			}
			if ( method_exists( $camptix, 'get_draft_release_at' ) ) {
				$release = (int) $camptix->get_draft_release_at( $id );
			} else {
				$created = (int) get_post_meta( $id, 'tix_timestamp', true );
				$release = $created > 0 ? $created + DAY_IN_SECONDS : 0;
			}
			if ( $release <= time() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Reconcile under the order lock and, if paid, tell CampTix. Any other CampTix result
	 * (cancel, fail) is decided by $otherwise, which also runs while the lock is held, so it
	 * can never overwrite a payment another request is recording at the same moment.
	 *
	 * @param array         $ctx         Order context.
	 * @param bool          $interactive Whether CampTix may redirect and exit.
	 * @param bool          $force       Whether to bypass the polling interval cache.
	 * @param callable|null $otherwise   fn( $ctx, $rec, $state ) called under the lock when not paid.
	 * @return string paid | reconciliation | pending | failed | none | busy
	 */
	private function settle( $ctx, $interactive, $force = false, $otherwise = null ) {
		$lock = $this->lock( $ctx['token'] );
		if ( ! $lock ) {
			return 'busy';
		}

		$ctx = $this->load( $ctx['token'] ); // Fresh state now that we hold the lock.
		if ( ! $ctx ) {
			$this->unlock( $lock );
			return 'none';
		}
		$rec = $this->reconcile( $ctx, $force );

		if ( $rec['paid'] ) {
			$this->report( $ctx['token'], CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $this->payment_data( $ctx, $rec ), $interactive );
			$this->unlock( $lock );
			return 'paid';
		}

		if ( $this->needs_reconciliation( $rec['attempts'] ) ) {
			$this->unlock( $lock );
			return 'reconciliation'; // Never invoke unpaid cancellation/failure callbacks.
		}

		if ( ! $rec['latest'] ) {
			$state = 'none';
		} else {
			$state = in_array( $rec['latest']['status'], array( 'failed', 'rejected', 'mismatch', 'lost' ), true ) ? 'failed' : 'pending';
		}
		if ( $otherwise ) {
			call_user_func( $otherwise, $ctx, $rec, $state ); // May redirect and exit; the shutdown hook unlocks.
		}
		$this->unlock( $lock );
		return $state;
	}

	/**
	 * Cancellation is safe only when no charge is outstanding.
	 *
	 * Releasing inventory while a PIN prompt is still pending permits its late
	 * approval to republish a cancelled order after the seat has been sold.
	 * Unknown/lost outcomes are deliberately not treated as confirmed failures.
	 *
	 * @param array $rec Reconciled payment attempts.
	 * @return bool
	 */
	private function can_cancel( $rec ) {
		return empty( $rec['attempts'] ) || $this->all_failed( $rec );
	}

	/** True when every attempt has a final, unpaid answer from Shashura. */
	private function all_failed( $rec ) {
		foreach ( $rec['attempts'] as $a ) {
			if ( ! in_array( $a['status'], array( 'failed', 'rejected' ), true ) ) {
				return false;
			}
		}
		return (bool) $rec['attempts'];
	}

	/**
	 * Build CampTix payment data and warn admins on duplicate paid prompts.
	 *
	 * @param array $ctx Order context.
	 * @param array $rec Reconciled payment attempts.
	 * @return array
	 */
	private function payment_data( $ctx, $rec ) {
		global $camptix;

		// Once the order is completed, its transaction stays the one the buyer's receipt shows.
		// A prompt approved later is the duplicate, even if it was sent first.
		$p      = $rec['paid'][0];
		$stored = (string) get_post_meta( $ctx['first'], 'tix_transaction_id', true );
		if ( '' !== $stored ) {
			foreach ( $rec['paid'] as $a ) {
				if ( ( ! empty( $a['tid'] ) && $stored === $a['tid'] ) || $stored === $a['ref'] ) {
					$p = $a;
					break;
				}
			}
		}
		$extra = array();
		foreach ( $rec['paid'] as $a ) {
			if ( $a['ref'] !== $p['ref'] ) {
				$extra[] = $a['ref'];
			}
		}

		// Alert once per extra payment. Orders alerted before this was tracked keep their alert.
		$alerted = get_post_meta( $ctx['first'], '_shashura_dup_refs', true );
		if ( ! is_array( $alerted ) ) {
			$alerted = get_post_meta( $ctx['first'], '_shashura_dup_alerted', true ) ? $extra : array();
		}
		$refs = array_values( array_diff( $extra, $alerted ) );

		if ( $refs ) {
			$camptix->log(
				'DUPLICATE mobile money payment: more than one prompt was approved for this order. Refund the extra payment(s) manually.',
				$ctx['first'],
				$refs,
				'payment'
			);
			update_post_meta( $ctx['first'], '_shashura_dup_alerted', time() );
			update_post_meta( $ctx['first'], '_shashura_dup_refs', array_merge( $alerted, $refs ) );
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( '[%s] Duplicate mobile money payment – refund needed', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				sprintf(
					"A buyer approved more than one payment prompt for the same order.\n\nOrder attendee: %s\nKept: %s\nRefund in the Shashura dashboard: %s\n",
					admin_url( 'post.php?post=' . $ctx['first'] . '&action=edit' ),
					$p['ref'],
					implode( ', ', $refs )
				)
			);
		}

		return array(
			'transaction_id'      => $p['tid'] ? $p['tid'] : $p['ref'],
			'transaction_details' => array(
				'raw' => array(
					'gateway'            => 'shashura',
					'environment'        => $this->options['environment'],
					'reference'          => $p['ref'],
					'tid'                => $p['tid'],
					'network_ref'        => isset( $p['network_ref'] ) ? $p['network_ref'] : '',
					'network'            => $p['network'],
					'amount'             => $p['paid_amount'],
					'duplicate_payments' => $extra,
				),
			),
		);
	}

	/**
	 * Call payment_result(), non-interactive where CampTix supports it (2.x); CampTix 1.x always redirects.
	 *
	 * @param string $token       Order payment token.
	 * @param int    $result      CampTix payment status constant.
	 * @param array  $data        Transaction payload.
	 * @param bool   $interactive Whether CampTix may redirect and exit.
	 * @return int
	 */
	private function report( $token, $result, $data, $interactive ) {
		global $camptix;
		if ( $interactive ) {
			return $camptix->payment_result( $token, $result, $data );
		}
		$m = new ReflectionMethod( $camptix, 'payment_result' );
		if ( $m->getNumberOfParameters() >= 4 ) {
			return $camptix->payment_result( $token, $result, $data, false );
		}
		add_filter( 'wp_redirect', '__return_false', 999 );
		$out = $camptix->payment_result( $token, $result, $data );
		remove_filter( 'wp_redirect', '__return_false', 999 );
		return $out;
	}

	// ==================================================================
	// Refunds
	//
	// A CampTix refund means the attendee is made whole, not just an internal
	// ledger note, so send_to_customer is always true here: Shashura pushes a
	// real payout of the refunded amount back to the phone that paid. Only the
	// principal ever moves — Shashura keeps its processing fee, same as every
	// other refund surface on the gateway.
	// ==================================================================

	/**
	 * Full, single refund of an order — CampTix's "Refund" button.
	 *
	 * @param string $payment_token
	 * @return int CampTix_Plugin::PAYMENT_STATUS_REFUNDED|PAYMENT_STATUS_REFUND_FAILED
	 */
	public function payment_refund( $payment_token ) {
		$data = $this->send_refund_request( $payment_token, null, 'full' );
		return $this->report( $payment_token, $data['status'], $data, true );
	}

	/**
	 * Partial refund, leaving the rest of the order paid — CampTix's per-ticket refund.
	 *
	 * @param string $payment_token
	 * @param float  $amount            Amount to refund, in whole UGX.
	 * @param bool   $refunds_remainder Whether this refunds everything still paid on the order.
	 * @return array { status, refund_transaction_id, refund_transaction_details }
	 */
	public function payment_refund_partial( $payment_token, $amount, $refunds_remainder ) {
		return $this->send_refund_request( $payment_token, $refunds_remainder ? null : $amount, 'partial' );
	}

	/**
	 * Ask Shashura to reverse the order's collection.
	 *
	 * @param string     $payment_token
	 * @param float|null $amount Amount to refund; null refunds whatever remains.
	 * @return array { status, transaction_id, refund_transaction_id, refund_transaction_details }
	 */
	public function send_refund_request( $payment_token, $amount = null, $via = 'full' ) {
		$failed = array(
			'status' => CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,
			'transaction_id' => '', 'refund_transaction_id' => '',
			'refund_transaction_details' => array( 'error' => 'Refund requires manual reconciliation. Do not repeat the ledger reversal.' ),
		);
		$lock   = $this->lock( $payment_token );
		if ( ! $lock ) {
			return $failed;
		}
		try {
			$ids = $this->find_attendee_ids( $payment_token );
			if ( ! $ids ) {
				return $failed;
			}
			$first = $ids[0];
			if ( get_post_meta( $first, '_shashura_refund_hold', true ) ) {
				return $failed;
			}
			// Write before sending: a timeout or process crash must not invite another
			// reversal. 'via' records whether this came from the whole-order refund
			// button or a per-ticket partial refund — the later webhook confirmation
			// needs to know which CampTix bookkeeping call to replay.
			$hold = array(
				'phase' => 'request_started', 'at' => time(), 'amount' => $amount, 'via' => $via, 'env' => $this->options['environment'],
			);
			if ( ! update_post_meta( $first, '_shashura_refund_hold', $hold ) ) {
				return $failed;
			}
			$result = $this->execute_refund_request( $payment_token, $amount );
			if ( CampTix_Plugin::PAYMENT_STATUS_REFUNDED === $result['status'] ) {
				delete_post_meta( $first, '_shashura_refund_hold' );
			} else {
				$hold['phase']  = 'reconciliation_required';
				$hold['result'] = $result;
				update_post_meta( $first, '_shashura_refund_hold', wp_slash( $hold ) );
				wp_mail(
					get_option( 'admin_email' ),
					'CampTix Mobile Money refund needs reconciliation',
					sprintf( 'Customer payout is not confirmed. Refund reference: %s. Inspect the gateway ledger and payout status. Retry ONLY the payout in the Shashura dashboard if the reversal succeeded. Do not repeat the ledger reversal. The plugin has blocked further refund requests for this order pending manual reconciliation, and will clear automatically once Shashura confirms delivery. Order: %s', $result['refund_transaction_id'], admin_url( 'post.php?post=' . $first . '&action=edit' ) )
				);
			}
			return $result;
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Execute the refund API request and verify payout confirmation.
	 *
	 * @param string     $payment_token Order payment token.
	 * @param float|null $amount        Amount to refund, or null for remaining balance.
	 * @return array
	 */
	private function execute_refund_request( $payment_token, $amount = null ) {
		global $camptix;

		$result = array(
			'status'                     => CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,
			'transaction_id'             => '',
			'refund_transaction_id'      => '',
			'refund_transaction_details' => array(),
		);

		$order          = $this->get_order( $payment_token );
		$transaction_id = $camptix->get_post_meta_from_payment_token( $payment_token, 'tix_transaction_id' );

		if ( empty( $order ) || ! $transaction_id ) {
			$camptix->log( 'Could not refund: no order or transaction id for this payment token.', null, array( 'payment_token' => $payment_token ), 'refund' );
			return $result;
		}

		// Blank on refund-all / cron-triggered refunds, which send no POST body.
		$reason = isset( $_POST['tix_refund_request_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['tix_refund_request_reason'] ) ) : '';
		if ( '' === $reason ) {
			$reason = sprintf( 'Refund via %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		}

		$refund = $this->api()->refund( $transaction_id, $amount, $reason, true );

		if ( is_wp_error( $refund ) ) {
			$result['refund_transaction_details'] = array(
				'error' => $refund->get_error_message(),
				'code'  => Shasmomo_API::error_code( $refund ),
			);
			$camptix->log( 'Shashura refund failed: ' . $refund->get_error_message(), isset( $order['attendee_id'] ) ? $order['attendee_id'] : null, $result, 'refund' );
			return $result;
		}

		$result['transaction_id']             = $transaction_id;
		$result['refund_transaction_id']      = $refund['refund_ref'];
		$result['refund_transaction_details'] = array( 'raw' => $refund );

		// A successful reversal does not prove delivery to the customer's phone.
		// Pending, absent and unknown payout states need reconciliation too.
		if ( $refund['payout'] && 'TS' === $refund['payout']['status'] && $refund['amount_refunded'] > 0 && '' !== $refund['refund_ref'] ) {
			$result['status'] = CampTix_Plugin::PAYMENT_STATUS_REFUNDED;
		} else {
			$camptix->log(
				'Refund ledger response received, but customer payout is not confirmed. Further reversals are blocked; reconcile the ledger and retry only the payout in the gateway dashboard.',
				isset( $order['attendee_id'] ) ? $order['attendee_id'] : null,
				$result,
				'refund'
			);
		}

		return $result;
	}

	// ==================================================================
	// CampTix entry point
	// ==================================================================

	/**
	 * Start checkout and redirect the buyer to the Mobile Money payment page.
	 *
	 * @param string $payment_token Order payment token.
	 * @return int|void
	 */
	public function payment_checkout( $payment_token ) {
		global $camptix;

		if ( ! $this->is_configured() || ! in_array( $this->camptix_options['currency'], $this->supported_currencies, true ) ) {
			$camptix->log( 'Shashura is not configured, or the currency is not UGX.', 0, null, 'payment' );
			$camptix->error_flag( 'payment_failed' );
			return CampTix_Plugin::PAYMENT_STATUS_FAILED;
		}

		// Without this CampTix holds the seats for 24 hours, so scripted abandoned checkouts
		// could keep an event sold out. Sending a prompt extends the hold.
		if ( method_exists( $camptix, 'set_order_session_expiry' ) ) {
			$camptix->set_order_session_expiry( $payment_token, time() + self::CHECKOUT_HOLD );
		}

		wp_safe_redirect( $this->url( 'shashura_pay', $payment_token ) );
		die();
	}

	// ==================================================================
	// Routing
	// ==================================================================

	/**
	 * Route CampTix payment action requests for this gateway.
	 */
	public function template_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing (which template to render), not a state change. Every handler below that actually changes state verifies its own nonce.
		if ( empty( $_GET['tix_payment_method'] ) || $this->id !== $_GET['tix_payment_method'] || empty( $_GET['tix_action'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$action = sanitize_key( wp_unslash( $_GET['tix_action'] ) );
		if ( ! in_array( $action, array( 'shashura_pay', 'shashura_wait', 'shashura_status', 'payment_return', 'payment_cancel' ), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$token = isset( $_GET['tix_payment_token'] ) ? sanitize_key( wp_unslash( $_GET['tix_payment_token'] ) ) : '';
		$ctx   = $this->load( $token );

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is a fixed, unprefixed constant name that page-cache plugins (WP Super Cache, W3TC, etc.) look for literally; it cannot be renamed.
			define( 'DONOTCACHEPAGE', true ); // Page-cache plugins must never store these pages.
		}
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );

		if ( 'shashura_status' === $action ) {
			$this->handle_status( $ctx );
		}
		if ( ! $ctx ) {
			$this->render_message( __( 'Order not found', 'shashura-mobile-money-for-camptix' ), __( 'We could not find this order. Please start your ticket purchase again.', 'shashura-mobile-money-for-camptix' ), 404 );
		}

		switch ( $action ) {
			case 'payment_return':
				$this->handle_return( $ctx );
				break;
			case 'payment_cancel':
				$this->handle_cancel( $ctx );
				break;
			case 'shashura_wait':
				$this->handle_wait( $ctx );
				break;
			default:
				$this->handle_pay( $ctx );
		}
	}

	/** A collected amount mismatch is not a declined payment. */
	private function needs_reconciliation( $attempts ) {
		foreach ( $attempts as $a ) {
			if ( 'mismatch' === $a['status'] || ! empty( $a['fulfilment_hold'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the manual reconciliation notice page.
	 */
	private function reconciliation_message() {
		$this->render_message(
			__( 'Payment needs reconciliation', 'shashura-mobile-money-for-camptix' ),
			__( 'A payment was collected, but your tickets could not be confirmed. Please do not pay again. Contact the organiser to reconcile your payment or arrange a refund.', 'shashura-mobile-money-for-camptix' ),
			200
		);
	}

	/** Orders that are not drafts can't be paid again: send paid ones to their tickets. */
	private function guard_draft( $ctx ) {
		if ( $this->needs_reconciliation( $ctx['attempts'] ) && ! in_array( $ctx['status'], array( 'publish', 'refund' ), true ) ) {
			$this->reconciliation_message();
		}
		if ( 'draft' === $ctx['status'] ) {
			return;
		}
		if ( in_array( $ctx['status'], array( 'publish', 'pending' ), true ) ) {
			$this->redirect_to_tickets( $ctx );
		}
		// Cancelled, failed or timed out, but a prompt may have been approved since: ask first.
		if ( in_array( $ctx['status'], array( 'cancel', 'failed', 'timeout' ), true ) && $ctx['attempts'] && $this->can_recover( $ctx ) ) {
			$state = $this->settle( $ctx, true, true );
			if ( 'reconciliation' === $state ) {
				$this->reconciliation_message();
			}
		}
		$this->render_message(
			__( 'This order is closed', 'shashura-mobile-money-for-camptix' ),
			__( 'This order is no longer waiting for payment. If you were charged, contact the organiser with your phone number. Otherwise start a new ticket purchase.', 'shashura-mobile-money-for-camptix' ),
			410,
			true
		);
	}

	/** Closed orders remain queryable for evidence, without automatic reopening. */
	private function can_recover( $ctx ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		return true; // Closed orders are enquired for evidence, but never automatically reopened.
	}

	/**
	 * Redirect the buyer to their CampTix tickets page.
	 *
	 * @param array $ctx Order context.
	 */
	private function redirect_to_tickets( $ctx ) {
		global $camptix;
		$access = get_post_meta( $ctx['first'], 'tix_access_token', true );
		wp_safe_redirect(
			add_query_arg(
				array(
					'tix_action'       => 'access_tickets',
					'tix_access_token' => $access,
				),
				$camptix->get_tickets_url()
			) . '#tix'
		);
		die();
	}

	// ==================================================================
	// Step 1 + 2: number entry, KYC, confirm, push
	// ==================================================================

	/**
	 * Handle GET/POST requests for the phone entry and confirmation steps.
	 *
	 * @param array $ctx Order context.
	 */
	private function handle_pay( $ctx ) {
		$this->guard_draft( $ctx );

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
			$notice = '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag, nothing is changed.
			$flag = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
			if ( 'declined' === $flag ) {
				$notice = $this->failure_message( $this->last_failure( $ctx ) );
			}
			$this->render_phone( $ctx, '', $notice );
		}

		if ( ! isset( $_POST['_shasmomo_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_shasmomo_nonce'] ) ), 'shasmomo_' . $ctx['token'] ) ) {
			$this->render_phone( $ctx, __( 'Your session expired. Please try again.', 'shashura-mobile-money-for-camptix' ) );
		}

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		if ( 'confirm' === $step ) {
			$this->step_confirm( $ctx );
		}
		$this->step_lookup( $ctx );
	}

	/**
	 * Validate phone number, enforce rate limits, and run KYC verification.
	 *
	 * @param array $ctx Order context.
	 */
	private function step_lookup( $ctx ) {
		global $camptix;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller, handle_pay(), before step_lookup() is reached.
		$msisdn = Shasmomo_Phone::normalize( isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '' );
		if ( '' === $msisdn ) {
			$this->render_phone( $ctx, __( 'Enter a valid MTN or Airtel Uganda number, e.g. 0772 123 456.', 'shashura-mobile-money-for-camptix' ) );
		}
		if ( count( $ctx['attempts'] ) >= self::MAX_ATTEMPTS ) {
			$this->render_message( __( 'Too many attempts', 'shashura-mobile-money-for-camptix' ), __( 'This order has reached the maximum number of payment attempts. Please start a new ticket purchase.', 'shashura-mobile-money-for-camptix' ), 429, true );
		}

		// KYC reveals a subscriber's registered name, so it must not become a lookup service.
		// Every limit is checked before any is counted, so a refused lookup uses up nothing.
		// The per-order count is read and raised under the order lock, so parallel posts
		// can't go past MAX_LOOKUPS. The IP and number counters are shared across orders
		// and stay best-effort.
		$lock = $this->lock( $ctx['token'] );
		if ( ! $lock ) {
			$this->render_phone( $ctx, __( 'Please try again in a moment.', 'shashura-mobile-money-for-camptix' ) );
		}
		$fresh   = $this->load( $ctx['token'] );
		$lookups = $fresh ? $fresh['lookups'] : $ctx['lookups'];
		$allowed = $lookups < self::MAX_LOOKUPS && $this->ip_allowance( false ) && $this->number_allowance( $msisdn, false );
		if ( $allowed ) {
			$this->save( $ctx, '_shashura_lookups', $lookups + 1 );
			$this->ip_allowance();
			$this->number_allowance( $msisdn );
		}
		$this->unlock( $lock );
		if ( ! $allowed ) {
			$this->render_phone( $ctx, __( 'Too many number checks. Please wait a while and try again.', 'shashura-mobile-money-for-camptix' ) );
		}

		$kyc = $this->api()->kyc( $msisdn );
		if ( is_wp_error( $kyc ) ) {
			$http = Shasmomo_API::http_status( $kyc );
			$camptix->log(
				'Shashura KYC failed: ' . $kyc->get_error_message(),
				$ctx['first'],
				array(
					'code' => Shasmomo_API::error_code( $kyc ),
					'http' => $http,
				),
				'payment'
			);
			$msg = ( $http >= 400 && $http < 500 && 401 !== $http ) || 'shashura_kyc_empty' === $kyc->get_error_code()
				? __( 'This number is not a registered MTN or Airtel Mobile Money account. Check it and try again.', 'shashura-mobile-money-for-camptix' )
				: __( 'We could not reach the mobile money service. Please try again in a moment.', 'shashura-mobile-money-for-camptix' );
			$this->render_phone( $ctx, $msg );
		}
		if ( ! in_array( $kyc['network'], array( 'MTN', 'AIRTEL' ), true ) ) {
			$this->render_phone( $ctx, __( 'Only MTN and Airtel Mobile Money numbers are supported.', 'shashura-mobile-money-for-camptix' ) );
		}

		$pending = array(
			'msisdn'  => $msisdn,
			'name'    => $kyc['name'],
			'network' => $kyc['network'],
			'at'      => time(),
		);
		$this->save( $ctx, '_shashura_pending', $pending );
		$this->render_confirm( $ctx, $pending );
	}

	/**
	 * Push a Mobile Money PIN prompt after confirmation.
	 *
	 * @param array $ctx Order context.
	 */
	private function step_confirm( $ctx ) {
		global $camptix;
		$p = $ctx['pending'];

		if ( empty( $p['msisdn'] ) || $p['at'] < time() - self::CONFIRM_TTL ) {
			$this->render_phone( $ctx, __( 'Please enter your number again.', 'shashura-mobile-money-for-camptix' ) );
		}
		if ( $ctx['total'] <= 0 ) {
			$this->render_message( __( 'Nothing to pay', 'shashura-mobile-money-for-camptix' ), __( 'This order has no amount to pay.', 'shashura-mobile-money-for-camptix' ), 400, true );
		}

		// Anything already paid? Then complete instead of charging again.
		$state = $this->settle( $ctx, true, true );
		if ( 'reconciliation' === $state ) {
			$this->reconciliation_message();
		}
		if ( 'busy' === $state ) {
			wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
			die();
		}
		$lock = $this->lock( $ctx['token'] );
		if ( ! $lock ) {
			wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
			die();
		}

		// Every limit is checked again here, under the lock, so parallel clicks can't slip past.
		$ctx = $this->load( $ctx['token'] );
		if ( $ctx && $this->needs_reconciliation( $ctx['attempts'] ) ) {
			$this->unlock( $lock );
			$this->reconciliation_message();
		}
		$latest = $ctx['attempts'] ? end( $ctx['attempts'] ) : null;

		if ( 'draft' !== $ctx['status'] ) {
			$this->unlock( $lock );
			$this->guard_draft( $ctx );
		}
		if ( $latest && in_array( $latest['status'], array( 'sent', 'pending' ), true ) && $latest['created'] > time() - self::RETRY_AFTER ) {
			$this->unlock( $lock );
			$this->render_confirm( $ctx, $p, __( 'A payment request is already on your phone. Approve it, or wait two minutes before sending a new one.', 'shashura-mobile-money-for-camptix' ) );
		}
		if ( count( $ctx['attempts'] ) >= self::MAX_ATTEMPTS ) {
			$this->unlock( $lock );
			$this->render_message( __( 'Too many attempts', 'shashura-mobile-money-for-camptix' ), __( 'This order has reached the maximum number of payment attempts. Please start a new ticket purchase.', 'shashura-mobile-money-for-camptix' ), 429, true );
		}

		// Recorded before the push, so a fast webhook or a timed-out push can still be matched.
		$attempt    = array(
			'ref'     => 'CTX' . strtoupper( wp_generate_password( 16, false, false ) ),
			'tid'     => '',
			'msisdn'  => $p['msisdn'],
			'name'    => $p['name'],
			'network' => $p['network'],
			'amount'  => $ctx['total'],
			'env'     => $this->options['environment'],
			'status'  => 'sent',
			'created' => time(),
			'checked' => 0,
		);
		$attempts   = $ctx['attempts'];
		$attempts[] = $attempt;
		$this->save( $ctx, '_shashura_attempts', $attempts );
		add_post_meta( $ctx['first'], '_shashura_ref', $attempt['ref'] );

		if ( method_exists( $camptix, 'set_order_session_expiry' ) ) {
			$camptix->set_order_session_expiry( $ctx['token'], time() + self::SESSION_HOLD );
		}

		$res = $this->api()->request_to_pay( $attempt['msisdn'], $attempt['amount'], $attempt['ref'] );

		$ctx      = $this->load( $ctx['token'] );
		$attempts = $ctx['attempts'];
		$i        = count( $attempts ) - 1;

		if ( is_wp_error( $res ) ) {
			$http = Shasmomo_API::http_status( $res );
			$code = Shasmomo_API::error_code( $res );
			$camptix->log(
				'Shashura requestToPay failed: ' . $res->get_error_message(),
				$ctx['first'],
				array(
					'ref'  => $attempt['ref'],
					'code' => $code,
					'http' => $http,
				),
				'payment'
			);

			if ( $http >= 400 && $http < 500 ) {
				// Definitely not pushed.
				$attempts[ $i ]['status'] = 'rejected';
				$attempts[ $i ]           = $this->redact( $attempts[ $i ] );
				$this->save( $ctx, '_shashura_attempts', $attempts );
				$this->unlock( $lock );

				if ( 'GATEWAY_REJECTED' === $code ) {
					$msg = __( 'Your mobile network declined the request. Check your balance and try again.', 'shashura-mobile-money-for-camptix' );
				} elseif ( 'INVALID_INPUT' === $code ) {
					$msg = __( 'The number or amount was not accepted. Please check and try again.', 'shashura-mobile-money-for-camptix' );
				} else {
					$msg = __( 'The payment request could not be sent. Please try again.', 'shashura-mobile-money-for-camptix' );
				}
				$this->render_phone( $ctx, $msg );
			}
			// Timeout or 5xx: the prompt may still have gone out. The waiting page asks Shashura.
		} else {
			$attempts[ $i ]['tid'] = $res['tid'];
			$this->save( $ctx, '_shashura_attempts', $attempts );
		}

		$this->save( $ctx, '_shashura_pending', array() );
		$this->unlock( $lock );
		wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
		die();
	}

	/**
	 * Determine the visitor IP address using the configured source.
	 *
	 * @return string
	 */
	private function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = $remote;
		$source = $this->options['ip_source'];
		if ( 'cloudflare' === $source && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( 'x_real_ip' === $source && ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		} elseif ( 'x_forwarded_for' === $source && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// Each trusted proxy appends the address it received the request from, so the
			// visitor is N entries from the right. Entries further left come from the request
			// itself and can be set to anything, so they are never used.
			$list  = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) ) );
			$index = count( $list ) - max( 1, (int) $this->options['proxy_hops'] );
			$ip    = $index >= 0 ? $list[ $index ] : '';
		}
		// filter_var() below is the real validation: anything that isn't a well-formed IP
		// (a spoofed or malformed header) falls back to REMOTE_ADDR.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = $remote;
		}
		return (string) apply_filters( 'shasmomo_client_ip', $ip );
	}

	/**
	 * Counts a KYC lookup against the per-number hourly budget. The per-IP limit alone can be
	 * spread over many IPs; this stops the same number being looked up again and again.
	 * The number is hashed with a salt so it isn't readable in the options table.
	 * With $count false it only checks, without using up the budget.
	 */
	private function number_allowance( $msisdn, $count = true ) {
		$key = 'shasmomo_kycn_' . md5( wp_salt( 'nonce' ) . $msisdn . '|' . gmdate( 'YmdH' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= (int) apply_filters( 'shasmomo_lookups_per_number', self::LOOKUPS_PER_NUMBER ) ) {
			return false;
		}
		if ( ! $count ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Counts a KYC lookup against the per-IP hourly budget. Site-wide volume only raises an
	 * admin alert: a hard site cap would let an attacker with a few IPs block every buyer.
	 * With $count false it only checks, without using up the budget.
	 */
	private function ip_allowance( $count = true ) {
		$hour = gmdate( 'YmdH' );
		$key  = 'shasmomo_kyc_' . md5( $this->client_ip() . '|' . $hour );
		$n    = (int) get_transient( $key );
		if ( $n >= (int) apply_filters( 'shasmomo_lookups_per_ip', self::LOOKUPS_PER_IP ) ) {
			return false;
		}
		if ( ! $count ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$site_key = 'shasmomo_kyc_site_' . $hour;
		$site     = (int) get_transient( $site_key ) + 1;
		set_transient( $site_key, $site, HOUR_IN_SECONDS );
		if ( (int) apply_filters( 'shasmomo_lookups_alert', self::LOOKUPS_ALERT ) === $site ) {
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( '[%s] Unusual number of mobile money number checks', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				sprintf( "Mobile Money number checks reached %d in the last hour. This can be a busy sale, or someone using checkout to look up names behind phone numbers. Check Tickets → Attendees for many unfinished orders.\n", $site )
			);
		}
		return true;
	}

	// ==================================================================
	// Step 3: waiting, polling, return, cancel
	// ==================================================================

	/**
	 * Render the waiting screen while the buyer approves the prompt.
	 *
	 * @param array $ctx Order context.
	 */
	private function handle_wait( $ctx ) {
		$this->guard_draft( $ctx );
		if ( ! $ctx['attempts'] ) {
			wp_safe_redirect( $this->url( 'shashura_pay', $ctx['token'] ) );
			die();
		}
		$this->render_wait( $ctx, end( $ctx['attempts'] ) );
	}

	/**
	 * Check whether CampTix supports non-interactive payment_result() calls.
	 *
	 * @return bool
	 */
	private function noninteractive_supported() {
		$m = new ReflectionMethod( $GLOBALS['camptix'], 'payment_result' );
		return $m->getNumberOfParameters() >= 4;
	}

	/**
	 * Serve JSON payment status updates for the waiting screen poller.
	 *
	 * @param array|null $ctx Order context.
	 */
	private function handle_status( $ctx ) {
		$nonce = isset( $_GET['_shasmomo_nonce'] ) ? sanitize_key( wp_unslash( $_GET['_shasmomo_nonce'] ) ) : '';
		if ( ! $ctx || ! wp_verify_nonce( $nonce, 'shasmomo_' . $ctx['token'] ) ) {
			wp_send_json( array( 'state' => 'error' ), 403 );
		}

		if ( in_array( $ctx['status'], array( 'publish', 'pending' ), true ) ) {
			$state = 'paid';
		} else {
			$open               = 'draft' === $ctx['status'];
			$closed_recoverable = in_array( $ctx['status'], array( 'cancel', 'failed', 'timeout' ), true ) && $ctx['attempts'] && $this->can_recover( $ctx );
			if ( $this->noninteractive_supported() && ( $open || $closed_recoverable ) ) {
				$state = $this->settle( $ctx, false );
			} elseif ( $open ) {
				// CampTix 1.x: report what Shashura says; the return page records it.
				$lock = $this->lock( $ctx['token'] );
				if ( ! $lock ) {
					wp_send_json( array( 'state' => 'pending' ) );
				}
				try {
					$rec = $this->reconcile( $this->load( $ctx['token'] ) );
				} finally {
					$this->unlock( $lock );
				}
				$state = $rec['paid'] ? 'paid' : ( $this->needs_reconciliation( $rec['attempts'] ) ? 'reconciliation' : ( $rec['latest'] && in_array( $rec['latest']['status'], array( 'failed', 'rejected', 'mismatch', 'lost' ), true ) ? 'failed' : 'pending' ) );
			} else {
				$state = 'closed';
			}
			if ( ! $open && ! in_array( $state, array( 'paid', 'reconciliation' ), true ) ) {
				$state = 'closed';
			}
			if ( 'busy' === $state || 'none' === $state ) {
				$state = 'pending';
			}
		}

		$out = array( 'state' => $state );
		if ( 'paid' === $state ) {
			$ctx  = $this->load( $ctx['token'] );
			$paid = array_values(
				array_filter(
					$ctx['attempts'],
					function ( $a ) {
						return 'paid' === $a['status'];
					}
				)
			);
			if ( $paid ) {
				$out['details'] = array(
					'name'      => $this->display_name( $paid[0]['name'] ),
					'phone'     => $this->masked( $paid[0] ),
					'network'   => 'AIRTEL' === $paid[0]['network'] ? 'Airtel' : 'MTN',
					'amount'    => 'UGX ' . number_format_i18n( $paid[0]['paid_amount'] ),
					'reference' => $paid[0]['tid'] ? $paid[0]['tid'] : $paid[0]['ref'],
				);
			}
			$out['next'] = $this->url( 'payment_return', $ctx['token'] );
		} elseif ( 'failed' === $state ) {
			$fresh          = $this->load( $ctx['token'] );
			$out['message'] = $this->failure_message( $this->last_failure( $fresh ? $fresh : $ctx ) );
			$out['next']    = count( $ctx['attempts'] ) < self::MAX_ATTEMPTS
				? $this->url( 'shashura_pay', $ctx['token'], array( 'notice' => 'declined' ) )
				: $this->url( 'payment_return', $ctx['token'] );
		} elseif ( 'reconciliation' === $state ) {
			$out['next'] = $this->url( 'payment_return', $ctx['token'] );
		} elseif ( 'closed' === $state ) {
			$out['next'] = $this->url( 'shashura_pay', $ctx['token'] );
		}
		wp_send_json( $out );
	}

	/**
	 * Handle buyer return from the waiting screen and settle or fail the order.
	 *
	 * @param array $ctx Order context.
	 */
	private function handle_return( $ctx ) {
		$this->guard_draft( $ctx ); // Paid orders go to their tickets; closed ones are re-checked first.

		// Paid: CampTix records it and redirects. Every prompt definitely failed and none left: fail the order.
		$state = $this->settle(
			$ctx,
			true,
			true,
			function ( $ctx, $rec, $state ) {
				if ( 'failed' === $state && count( $rec['attempts'] ) >= self::MAX_ATTEMPTS && $this->all_failed( $rec ) ) {
					$this->report( $ctx['token'], CampTix_Plugin::PAYMENT_STATUS_FAILED, array( 'error_code' => 'shashura_attempts_exhausted' ), true );
					die();
				}
			}
		);

		if ( 'reconciliation' === $state ) {
			$this->reconciliation_message();
		}
		if ( 'failed' === $state && count( $ctx['attempts'] ) >= self::MAX_ATTEMPTS ) {
			$this->render_message(
				__( 'Payment not confirmed yet', 'shashura-mobile-money-for-camptix' ),
				__( 'We could not confirm your payment yet. If money left your account, your tickets will be emailed as soon as it is confirmed. Otherwise please start a new ticket purchase.', 'shashura-mobile-money-for-camptix' ),
				200,
				true
			);
		}
		if ( 'failed' === $state ) {
			wp_safe_redirect( $this->url( 'shashura_pay', $ctx['token'], array( 'notice' => 'declined' ) ) );
			die();
		}
		if ( 'none' === $state ) {
			wp_safe_redirect( $this->url( 'shashura_pay', $ctx['token'] ) );
			die();
		}
		wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
		die();
	}

	/**
	 * Handle buyer cancellation when no prompt is still pending.
	 *
	 * @param array $ctx Order context.
	 */
	private function handle_cancel( $ctx ) {
		$nonce = isset( $_GET['_shasmomo_nonce'] ) ? sanitize_key( wp_unslash( $_GET['_shasmomo_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'shasmomo_' . $ctx['token'] ) ) {
			$this->render_message( __( 'Link expired', 'shashura-mobile-money-for-camptix' ), __( 'This cancel link has expired. Go back and try again.', 'shashura-mobile-money-for-camptix' ), 403, true );
		}
		$this->guard_draft( $ctx );

		// Decided under the order lock: if any prompt was approved the order completes instead,
		// and a webhook recording a payment right now can't be overwritten by this cancel.
		$state = $this->settle(
			$ctx,
			true,
			true,
			function ( $ctx, $rec ) {
				// An unconfirmed PIN prompt can still be approved after cancellation.
				// Keep the seat reserved until Shashura confirms every prompt failed.
				if ( 'draft' === $ctx['status'] && $this->can_cancel( $rec ) ) {
					$this->report( $ctx['token'], CampTix_Plugin::PAYMENT_STATUS_CANCELLED, array(), true );
					die();
				}
			}
		);

		if ( 'busy' === $state ) {
			// Something is recording this order right now. Let the waiting page show the outcome.
			wp_safe_redirect( $this->url( 'shashura_wait', $ctx['token'] ) );
			die();
		}
		if ( in_array( $state, array( 'pending', 'failed' ), true ) ) {
			// A latest failed attempt does not prove an older prompt is settled.
			$this->render_message(
				__( 'Payment is still being checked', 'shashura-mobile-money-for-camptix' ),
				__( 'Your order cannot be cancelled while a Mobile Money prompt may still be approved. Please let the prompt expire or contact the organiser before trying again.', 'shashura-mobile-money-for-camptix' ),
				200
			);
		}
		$this->guard_draft( $this->load( $ctx['token'] ) );
		$this->redirect_to_tickets( $ctx );
	}

	// ==================================================================
	// Webhook (REST): POST /wp-json/shasmomo/v1/webhook
	// ==================================================================

	/**
	 * Register the webhook REST API routes.
	 */
	public function register_rest_routes() {
		// camptix-shashura/v1 is the original route. It stays registered so Callback URLs
		// already saved in the Shashura dashboard keep working.
		foreach ( array( 'shasmomo/v1', 'camptix-shashura/v1' ) as $namespace ) {
			register_rest_route(
				$namespace,
				'/webhook',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_webhook' ),
					'permission_callback' => '__return_true', // Authenticated by the HMAC signature below.
				)
			);
		}
	}

	/**
	 * Handle incoming Shashura webhook notifications.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function rest_webhook( WP_REST_Request $request ) {
		global $camptix;
		$raw = (string) $request->get_body();

		if ( strlen( $raw ) > self::WEBHOOK_MAX_BYTES ) {
			return new WP_REST_Response( array( 'ok' => false ), 413 );
		}
		if ( '' === $this->options['webhook_secret'] ) {
			$camptix->log( 'Shashura webhook rejected: no webhook secret configured.', 0, null, 'payment' );
			return new WP_REST_Response(
				array(
					'ok' => false, 'error' => 'not_configured',
				),
				401
			);
		}
		if ( ! $this->api()->verify_signature( $raw, (string) $request->get_header( 'x_shashura_signature' ) ) ) {
			return new WP_REST_Response(
				array(
					'ok' => false, 'error' => 'bad_signature',
				),
				401
			);
		}

		// The same signed body again (a retry or a replay) after it already settled the order:
		// acknowledge it without asking Shashura again.
		$seen_key = 'shasmomo_wh_' . md5( $raw );
		if ( get_transient( $seen_key ) ) {
			return new WP_REST_Response(
				array(
					'ok' => true, 'duplicate' => true,
				),
				200
			);
		}

		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) ) {
			return new WP_REST_Response(
				array(
					'ok' => false, 'error' => 'bad_json',
				),
				400
			);
		}

		// A refund's customer payout can resolve asynchronously (MTN) well after
		// the original refund request returned "needs reconciliation". This is a
		// completely different lifecycle from a collection settling — handle it
		// on its own path rather than threading it through settlement logic
		// (environment/attempt matching, /enquiry, etc.) that doesn't apply to it.
		if ( isset( $event['event'] ) && 'transaction.refunded' === $event['event'] ) {
			return $this->handle_refund_confirmation( $event );
		}

		// Signed but not for us: acknowledge so Shashura stops retrying, change nothing.
		if ( isset( $event['type'] ) && 'COLLECTION' !== $event['type'] ) {
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}

		$ref   = isset( $event['external_ref'] ) && is_string( $event['external_ref'] ) ? $event['external_ref'] : '';
		$token = $this->find_token_by_reference( $ref );
		$ctx   = $token ? $this->load( $token ) : null;
		if ( ! $ctx ) {
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}

		// The event must come from the environment the prompt was sent in.
		$sent_in = $this->options['environment'];
		foreach ( $ctx['attempts'] as $a ) {
			if ( $a['ref'] === $ref && ! empty( $a['env'] ) ) {
				$sent_in = $a['env'];
			}
		}
		if ( isset( $event['environment'] ) && $event['environment'] !== $sent_in ) {
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}
		if ( $sent_in !== $this->options['environment'] ) {
			// Can't verify it with the current credentials. Ask Shashura to retry later rather than drop it.
			$camptix->log( 'Webhook for a prompt sent in ' . $sent_in . ' while the plugin is set to ' . $this->options['environment'] . '; asking Shashura to retry.', $ctx['first'], array( 'ref' => $ref ), 'payment' );
			return new WP_REST_Response(
				array(
					'ok' => false, 'retry' => true,
				),
				503
			);
		}
		if ( 'refund' === $ctx['status'] ) {
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}
		if ( ! $this->can_recover( $ctx ) ) {
			$camptix->log( 'Payment webhook for a timed-out order; CampTix 1.x cannot reopen it. Check the payment manually.', $ctx['first'], array( 'ref' => $ref ), 'payment' );
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}

		// The event body is only a hint. settle() asks /enquiry before anything changes.
		$state = $this->settle( $ctx, false, true );
		if ( 'busy' === $state ) {
			return new WP_REST_Response(
				array(
					'ok' => false, 'retry' => true,
				),
				503
			); // Shashura retries later.
		}
		if ( in_array( $state, array( 'paid', 'failed' ), true ) ) {
			set_transient( $seen_key, 1, 10 * MINUTE_IN_SECONDS );
		}
		return new WP_REST_Response(
			array(
				'ok' => true, 'state' => $state,
			),
			200
		);
	}

	/**
	 * A refund's customer payout was pending when the refund request returned
	 * (MTN resolves asynchronously via its own callback; Airtel never reaches
	 * here because it settles inline). This is Shashura confirming — later,
	 * out of band — whether the money actually arrived.
	 *
	 * The webhook is keyed to `tid`: the ORIGINAL collection's reference, i.e.
	 * `tix_transaction_id` — never this payout's own ref, which the plugin
	 * never recorded anywhere a lookup could use.
	 *
	 * @param array $event Decoded `transaction.refunded` webhook body.
	 * @return WP_REST_Response
	 */
	private function handle_refund_confirmation( array $event ) {
		$tid = isset( $event['tid'] ) && is_string( $event['tid'] ) ? $event['tid'] : '';
		if ( '' === $tid ) {
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}

		$ids = get_posts( array(
			'post_type'        => 'tix_attendee',
			'post_status'      => self::STATUSES,
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- a webhook must resolve its order regardless of any front-end query filter.
			'suppress_filters' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the gateway's tid is only stored as post meta; there is no other lookup path.
			'meta_query'       => array(
				array(
					'key' => 'tix_transaction_id', 'value' => $tid, 'compare' => '=', 'type' => 'CHAR',
				),
			),
		) );
		if ( ! $ids ) {
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}
		$first = $ids[0];

		// Same lock as refunds and settlement, so a confirmation can't race a new refund
		// request on this order. Busy: ask Shashura to deliver it again later.
		$token = (string) get_post_meta( $first, 'tix_payment_token', true );
		if ( '' === $token ) {
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}
		$lock = $this->lock( $token );
		if ( ! $lock ) {
			return new WP_REST_Response(
				array(
					'ok' => false, 'error' => 'busy',
				),
				503
			);
		}
		try {
			return $this->apply_refund_confirmation( $event, $first, $token );
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Why a refund confirmation can't belong to the refund this order is waiting on, or ''.
	 * Only fields the event actually carries are compared: a missing field can't prove a
	 * mismatch. Covers an older refund's event arriving (or being replayed) after a newer
	 * refund was requested, and an event from the other environment.
	 */
	private function refund_event_mismatch( array $event, array $hold ) {
		$env = isset( $hold['env'] ) ? (string) $hold['env'] : (string) $this->options['environment'];
		if ( isset( $event['environment'] ) && is_string( $event['environment'] ) && strtolower( $event['environment'] ) !== $env ) {
			return 'environment';
		}

		if ( isset( $event['timestamp'] ) && is_scalar( $event['timestamp'] ) && ! empty( $hold['at'] ) ) {
			$at = is_numeric( $event['timestamp'] ) ? (int) $event['timestamp'] : strtotime( (string) $event['timestamp'] );
			// A minute of slack for clock differences; an older refund's event is older than that.
			if ( $at && $at < (int) $hold['at'] - MINUTE_IN_SECONDS ) {
				return 'older than the refund request';
			}
		}

		$held_ref  = isset( $hold['result']['refund_transaction_id'] ) ? (string) $hold['result']['refund_transaction_id'] : '';
		$event_ref = '';
		if ( isset( $event['refund_ref'] ) && is_scalar( $event['refund_ref'] ) ) {
			$event_ref = (string) $event['refund_ref'];
		}
		if ( '' !== $held_ref && '' !== $event_ref && $held_ref !== $event_ref ) {
			return 'refund reference';
		}

		$event_amount = isset( $event['amount_refunded'] ) && is_numeric( $event['amount_refunded'] ) ? (float) $event['amount_refunded'] : null;
		$held_amount  = isset( $hold['result']['refund_transaction_details']['raw']['amount_refunded'] ) && is_numeric( $hold['result']['refund_transaction_details']['raw']['amount_refunded'] )
			? (float) $hold['result']['refund_transaction_details']['raw']['amount_refunded']
			: null;
		if ( null !== $event_amount && null !== $held_amount && $held_amount > 0 && abs( $event_amount - $held_amount ) > 0.001 ) {
			return 'amount';
		}

		return '';
	}

	/** The rest of handle_refund_confirmation(), run under the order lock. */
	private function apply_refund_confirmation( array $event, $first, $token ) {
		/** @var $camptix Camptix_Plugin */
		global $camptix;

		// Read the hold under the lock: a refund request may have just replaced it.
		$hold = get_post_meta( $first, '_shashura_refund_hold', true );
		if ( ! is_array( $hold ) || 'reconciliation_required' !== ( $hold['phase'] ?? '' ) ) {
			// Already resolved (or never held) — e.g. a retried webhook delivery. Idempotent no-op.
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}

		$mismatch = $this->refund_event_mismatch( $event, $hold );
		if ( '' !== $mismatch ) {
			$camptix->log( 'Refund confirmation ignored: it does not match the refund this order is waiting on (' . $mismatch . '). The hold stays.', $first, $event, 'refund' );
			return new WP_REST_Response(
				array(
					'ok' => true, 'ignored' => true,
				),
				200
			);
		}

		$payout = isset( $event['payout'] ) && is_array( $event['payout'] ) ? $event['payout'] : array();
		$status = isset( $payout['status'] ) ? (string) $payout['status'] : '';

		if ( 'TS' !== $status ) {
			// Still not delivered (e.g. a FAILED notification arriving after the
			// initial PENDING). Keep the hold, but record what we now know instead
			// of leaving the admin to guess from the original, now-stale message.
			$hold['result']['refund_transaction_details'] = array( 'raw' => $event );
			update_post_meta( $first, '_shashura_refund_hold', wp_slash( $hold ) );
			$camptix->log(
				'Refund payout confirmed NOT delivered (' . ( $payout['message'] ?? 'no reason given' ) . '). Reconciliation hold remains; the ledger reversal was not repeated.',
				$first,
				$event,
				'refund'
			);
			return new WP_REST_Response(
				array(
					'ok' => true, 'state' => 'still_held',
				),
				200
			);
		}

		// Delivered. Release the hold either way; what happens next depends on
		// whether the original call was a whole-order or a per-ticket refund.
		delete_post_meta( $first, '_shashura_refund_hold' );
		$data = isset( $hold['result'] ) && is_array( $hold['result'] ) ? $hold['result'] : array();

		if ( 'partial' === ( $hold['via'] ?? 'full' ) ) {
			// CampTix's own per-ticket bookkeeping (which attendees, what share of
			// the amount each) only exists transiently inside camptix.php's own
			// refund_attendees_partially() call — this hold cannot reconstruct it
			// safely days later. Confirming delivery and unblocking further action
			// is what matters here; applying the per-ticket split is a deliberate
			// manual step, same as the rest of this patch's conservative posture.
			$camptix->log(
				'Refund payout for a PARTIAL refund is now confirmed delivered. The reconciliation hold is cleared. CampTix\'s per-ticket refund status was not changed automatically — update the affected attendee(s) manually if it has not already settled.',
				$first,
				$data,
				'refund'
			);
			wp_mail(
				get_option( 'admin_email' ),
				'CampTix Mobile Money partial refund payout confirmed',
				sprintf( 'The customer payout for a partial refund is now confirmed delivered (reference: %s). The reconciliation hold has been cleared. Please confirm the affected attendee(s) are marked refunded in CampTix. Order: %s', $data['refund_transaction_id'] ?? '', admin_url( 'post.php?post=' . $first . '&action=edit' ) )
			);
			return new WP_REST_Response(
				array(
					'ok' => true, 'state' => 'cleared_partial',
				),
				200
			);
		}

		// Whole-order refund: replay the same call payment_refund() would have
		// made had the payout already been confirmed at request time.
		$data['status'] = CampTix_Plugin::PAYMENT_STATUS_REFUNDED;
		$this->report( $token, CampTix_Plugin::PAYMENT_STATUS_REFUNDED, $data, false );
		$camptix->log( 'Refund payout now confirmed delivered; order recorded as refunded.', $first, $data, 'refund' );

		return new WP_REST_Response(
			array(
				'ok' => true, 'state' => 'refunded',
			),
			200
		);
	}

	// ==================================================================
	// CampTix timeout sweep: ask Shashura before an order is timed out
	// ==================================================================

	/**
	 * Reset the per-sweep cache of checked tokens.
	 */
	public function reset_sweep() {
		$this->swept = array();
	}

	/**
	 * Reconcile pending Mobile Money orders before CampTix times them out.
	 *
	 * @param int $attendee_id Draft attendee ID being evaluated for timeout.
	 */
	public function pre_attendee_timeout( $attendee_id ) {
		global $camptix;
		if ( 'draft' !== get_post_status( $attendee_id ) || get_post_meta( $attendee_id, 'tix_payment_method', true ) !== $this->id ) {
			return;
		}
		$token = (string) get_post_meta( $attendee_id, 'tix_payment_token', true );
		if ( isset( $this->swept[ $token ] ) ) {
			return;
		}
		$this->swept[ $token ] = true;

		$ctx = $this->load( $token );
		if ( ! $ctx || ! $ctx['attempts'] ) {
			return;
		}

		$state = $this->settle( $ctx, false, true );
		if ( 'paid' === $state ) {
			return;
		}

		// settle() may have updated the attempts, so decide on the saved copy, not the one loaded above.
		$ctx = $this->load( $token );
		if ( ! $ctx || ! $ctx['attempts'] ) {
			return;
		}

		// A prompt still in flight (or Shashura unreachable): hold the seats a little longer, up to a day.
		$latest = end( $ctx['attempts'] );
		if ( in_array( $state, array( 'pending', 'busy' ), true ) && $latest['created'] > time() - DAY_IN_SECONDS && method_exists( $camptix, 'set_order_session_expiry' ) ) {
			$camptix->set_order_session_expiry( $token, time() + 10 * MINUTE_IN_SECONDS );
			$camptix->log( 'Mobile money payment still pending at timeout; holding the order.', $attendee_id, null, 'payment' );
		}
	}

	// ==================================================================
	// Privacy: WordPress personal data export / erase (Tools → Export/Erase Personal Data)
	// ==================================================================

	/**
	 * Register the personal data exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['shashura-mobile-money-for-camptix'] = array(
			'exporter_friendly_name' => __( 'CampTix Mobile Money', 'shashura-mobile-money-for-camptix' ), 'callback' => array( $this, 'privacy_export' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['shashura-mobile-money-for-camptix'] = array(
			'eraser_friendly_name' => __( 'CampTix Mobile Money', 'shashura-mobile-money-for-camptix' ), 'callback' => array( $this, 'privacy_erase' ),
		);
		return $erasers;
	}

	/**
	 * Find attendee IDs matching an email address that have Shashura attempt records.
	 *
	 * @param string $email Buyer email address.
	 * @param int    $page  Pagination page number.
	 * @return int[]
	 */
	private function attendees_by_email( $email, $page ) {
		return get_posts( array(
			'post_type'      => 'tix_attendee',
			'post_status'    => self::STATUSES,
			'posts_per_page' => 50,
			'paged'          => max( 1, (int) $page ),
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WP core's Privacy Export/Erase tooling only identifies a person by email, and CampTix stores it as post meta; this runs only on an admin-triggered privacy request, never in the buyer-facing hot path.
			'meta_query'     => array(
				array(
					'key' => 'tix_email', 'value' => $email,
				),
				array(
					'key' => '_shashura_attempts', 'compare' => 'EXISTS',
				),
			),
		) );
	}

	/**
	 * Export stored Mobile Money attempt records for a given email address.
	 *
	 * @param string $email Buyer email address.
	 * @param int    $page  Pagination page number.
	 * @return array
	 */
	public function privacy_export( $email, $page = 1 ) {
		$items = array();
		$ids   = $this->attendees_by_email( $email, $page );
		foreach ( $ids as $id ) {
			$attempts = get_post_meta( $id, '_shashura_attempts', true );
			foreach ( is_array( $attempts ) ? $attempts : array() as $a ) {
				$items[] = array(
					'group_id'    => 'shashura-mobile-money-for-camptix',
					'group_label' => __( 'Mobile Money payments', 'shashura-mobile-money-for-camptix' ),
					'item_id'     => 'shashura-' . $id . '-' . $a['ref'],
					'data'        => array(
						array(
							'name' => __( 'Reference', 'shashura-mobile-money-for-camptix' ), 'value' => $a['ref'],
						),
						array(
							'name' => __( 'Date', 'shashura-mobile-money-for-camptix' ), 'value' => gmdate( 'Y-m-d H:i', (int) $a['created'] ) . ' UTC',
						),
						array(
							'name' => __( 'Mobile number', 'shashura-mobile-money-for-camptix' ), 'value' => ! empty( $a['msisdn'] ) ? $a['msisdn'] : $this->masked( $a ),
						),
						array(
							'name' => __( 'Registered name', 'shashura-mobile-money-for-camptix' ), 'value' => $a['name'],
						),
						array(
							'name' => __( 'Network', 'shashura-mobile-money-for-camptix' ), 'value' => $a['network'],
						),
						array(
							'name' => __( 'Amount (UGX)', 'shashura-mobile-money-for-camptix' ), 'value' => (string) $a['amount'],
						),
						array(
							'name' => __( 'Status', 'shashura-mobile-money-for-camptix' ), 'value' => $a['status'],
						),
					),
				);
			}
		}
		return array(
			'data' => $items, 'done' => count( $ids ) < 50,
		);
	}

	/** Removes payer names and numbers; keeps references, amounts and statuses for the accounts. */
	public function privacy_erase( $email, $page = 1 ) {
		$removed = false;
		$ids     = $this->attendees_by_email( $email, $page );
		foreach ( $ids as $id ) {
			$attempts = get_post_meta( $id, '_shashura_attempts', true );
			foreach ( is_array( $attempts ) ? $attempts : array() as $i => $a ) {
				$attempts[ $i ]['msisdn']        = '';
				$attempts[ $i ]['msisdn_masked'] = '';
				$attempts[ $i ]['name']          = '';
			}
			update_post_meta( $id, '_shashura_attempts', wp_slash( $attempts ) );
			delete_post_meta( $id, '_shashura_pending' );

			$txn = get_post_meta( $id, 'tix_transaction_details', true );
			if ( is_array( $txn ) && isset( $txn['raw']['payer_name'] ) ) {
				$txn['raw']['payer_name']   = '';
				$txn['raw']['payer_number'] = '';
				update_post_meta( $id, 'tix_transaction_details', wp_slash( $txn ) );
			}
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $removed,
			'messages'       => $removed ? array( __( 'Mobile Money payment references, amounts and statuses were kept for the event\'s accounting records.', 'shashura-mobile-money-for-camptix' ) ) : array(),
			'done'           => count( $ids ) < 50,
		);
	}

	// ==================================================================
	// Views
	// ==================================================================

	/**
	 * Format the subscriber's registered name according to the name_display setting.
	 *
	 * @param string $name Registered subscriber name.
	 * @return string
	 */
	private function display_name( $name ) {
		if ( 'off' === $this->options['name_display'] ) {
			return '';
		}
		if ( 'partial' !== $this->options['name_display'] ) {
			return $name;
		}
		$parts = preg_split( '/\s+/', trim( $name ) );
		$first = array_shift( $parts );
		$inits = array_map(
			function ( $p ) {
				return mb_substr( $p, 0, 1 ) . '.';
			},
			$parts
		);
		return trim( $first . ' ' . implode( ' ', $inits ) );
	}

	/**
	 * Format the order total in UGX.
	 *
	 * @param array $ctx Order context.
	 * @return string
	 */
	private function amount_text( $ctx ) {
		return 'UGX ' . number_format_i18n( $ctx['total'] );
	}

	/**
	 * Build a nonce-protected cancellation URL for the order.
	 *
	 * @param array $ctx Order context.
	 * @return string
	 */
	private function cancel_url( $ctx ) {
		return $this->url( 'payment_cancel', $ctx['token'], array( '_shasmomo_nonce' => wp_create_nonce( 'shasmomo_' . $ctx['token'] ) ) );
	}

	/**
	 * Supported networks, with the MTN MoMo and Airtel Money logos bundled in assets/images.
	 * The shasmomo_network_logo filter can swap a logo for another image URL, or return ''
	 * to show the coloured badge with the network name instead.
	 *
	 * @return array[] key => { key, label, short, logo }
	 */
	private function networks() {
		$networks = array(
			'mtn'    => array(
				'key'   => 'mtn',
				'label' => __( 'MTN Mobile Money', 'shashura-mobile-money-for-camptix' ),
				'short' => __( 'MTN MoMo', 'shashura-mobile-money-for-camptix' ),
			),
			'airtel' => array(
				'key'   => 'airtel',
				'label' => __( 'Airtel Money', 'shashura-mobile-money-for-camptix' ),
				'short' => __( 'Airtel Money', 'shashura-mobile-money-for-camptix' ),
			),
		);
		foreach ( $networks as $key => $network ) {
			$default                  = SHASMOMO_URL . 'assets/images/' . ( 'mtn' === $key ? 'mtn-momo.png' : 'airtel-money.png' );
			$networks[ $key ]['logo'] = esc_url_raw( (string) apply_filters( 'shasmomo_network_logo', $default, $key ) );
		}
		return $networks;
	}

	/**
	 * Return display metadata for a mobile money network code.
	 *
	 * @param string $code Network code (AIRTEL or MTN).
	 * @return array
	 */
	private function network( $code ) {
		$networks = $this->networks();
		return 'AIRTEL' === $code ? $networks['airtel'] : $networks['mtn'];
	}

	/** Ticket lines and total for the header of every payment screen. */
	private function summary( $ctx ) {
		$order = $this->get_order_by_attendee_id( $ctx['first'] );
		$items = array();
		$count = 0;
		foreach ( isset( $order['items'] ) && is_array( $order['items'] ) ? $order['items'] : array() as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}
			$quantity = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			$price    = isset( $item['price'] ) ? (float) $item['price'] : 0;
			$count   += $quantity;
			$items[]  = array(
				'name'     => (string) $item['name'],
				'quantity' => $quantity,
				'price'    => 'UGX ' . number_format_i18n( $price * $quantity ),
			);
		}
		return array(
			'items' => $items,
			'total' => $this->amount_text( $ctx ),
			'count' => $count,
		);
	}

	/**
	 * The site's own logo for the page header: the Site Icon, then the theme's Custom Logo.
	 * Empty when neither is set; the first letter of the site name is shown instead.
	 */
	private function merchant_logo() {
		$url = get_site_icon_url( 96 );
		if ( ! $url ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				$image = wp_get_attachment_image_src( $logo_id, 'medium' );
				$url   = $image ? $image[0] : '';
			}
		}
		return (string) apply_filters( 'shasmomo_merchant_logo', (string) $url );
	}

	/**
	 * Text colour for the accent background: white when it reaches WCAG AA (4.5:1),
	 * otherwise near-black, so any accent set through shasmomo_accent_color stays readable.
	 */
	private function readable_ink( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$lum = 0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			$lum += $weight * $c;
		}
		return ( 1.05 / ( $lum + 0.05 ) ) >= 4.5 ? '#ffffff' : '#0b1220';
	}

	/** Plugin version plus file time, so a changed CSS/JS file isn't served from cache. */
	private function asset_version( $relative ) {
		$file = SHASMOMO_DIR . $relative;
		return SHASMOMO_VERSION . ( file_exists( $file ) ? '.' . filemtime( $file ) : '' );
	}

	/**
	 * Path of a screen template. A theme can override any of them by copying it to
	 * {theme}/shashura-mobile-money-for-camptix/{name}.php.
	 */
	private function template_path( $name ) {
		$name  = in_array( $name, array( 'layout', 'phone', 'confirm', 'wait', 'message' ), true ) ? $name : 'message';
		$theme = locate_template( 'shashura-mobile-money-for-camptix/' . $name . '.php' );
		$path  = $theme ? $theme : SHASMOMO_DIR . 'templates/' . $name . '.php';
		return (string) apply_filters( 'shasmomo_template', $path, $name );
	}

	/** Failure code of the latest attempt, if Shashura gave one. */
	private function last_failure( $ctx ) {
		$latest = $ctx && $ctx['attempts'] ? end( $ctx['attempts'] ) : null;
		return $latest && 'failed' === $latest['status'] && ! empty( $latest['failure'] ) ? $latest['failure'] : '';
	}

	/** What to tell the buyer after a failed prompt, from Shashura's failure code. */
	private function failure_message( $code ) {
		switch ( $code ) {
			case 'INSUFFICIENT_FUNDS':
				return __( 'There was not enough balance on this Mobile Money account. Top up and try again, or use another number.', 'shashura-mobile-money-for-camptix' );
			case 'WRONG_PIN':
				return __( 'The PIN entered on the phone was wrong. Try again and enter your Mobile Money PIN.', 'shashura-mobile-money-for-camptix' );
			case 'CANCELLED_BY_USER':
				return __( 'The payment was cancelled on the phone. You can try again.', 'shashura-mobile-money-for-camptix' );
			case 'TIMED_OUT':
				return __( 'The prompt expired before it was approved. Try again and approve it when it appears on your phone.', 'shashura-mobile-money-for-camptix' );
			case 'LIMIT_EXCEEDED':
				return __( 'This payment is over the Mobile Money limit for this account. Use another number or contact your network.', 'shashura-mobile-money-for-camptix' );
			case 'ACCOUNT_ERROR':
				return __( 'This Mobile Money account could not be reached. Check the number or use another one.', 'shashura-mobile-money-for-camptix' );
			case 'PROVIDER_ERROR':
				return __( 'The mobile network had a problem. Please try again in a moment.', 'shashura-mobile-money-for-camptix' );
			default:
				return __( 'The last payment request was declined or expired. You can try again.', 'shashura-mobile-money-for-camptix' );
		}
	}

	/**
	 * Render the mobile number entry step.
	 *
	 * @param array  $ctx    Order context.
	 * @param string $error  Validation error message.
	 * @param string $notice Informational notice message.
	 */
	private function render_phone( $ctx, $error = '', $notice = '' ) {
		$this->render_page(
			__( 'Pay with Mobile Money', 'shashura-mobile-money-for-camptix' ),
			'phone',
			array(
				'notice'       => $notice,
				'error'        => $error,
				'prefill'      => ! empty( $ctx['pending']['msisdn'] ) ? substr( $ctx['pending']['msisdn'], 3 ) : '',
				'networks'     => $this->networks(),
				'action_url'   => $this->url( 'shashura_pay', $ctx['token'] ),
				'nonce_action' => 'shasmomo_' . $ctx['token'],
				'nonce_name'   => '_shasmomo_nonce',
			),
			$error ? 422 : 200,
			null,
			$ctx,
			1
		);
	}

	/**
	 * Render the account confirmation step.
	 *
	 * @param array  $ctx   Order context.
	 * @param array  $p     Verified KYC details.
	 * @param string $error Error message.
	 */
	private function render_confirm( $ctx, $p, $error = '' ) {
		$this->render_page(
			__( 'Confirm payment', 'shashura-mobile-money-for-camptix' ),
			'confirm',
			array(
				'name'         => '' !== $p['name'] ? $this->display_name( $p['name'] ) : '',
				'phone'        => Shasmomo_Phone::pretty( $p['msisdn'] ),
				'network'      => $this->network( $p['network'] ),
				'networks'     => $this->networks(),
				'amount'       => $this->amount_text( $ctx ),
				'error'        => $error,
				'action_url'   => $this->url( 'shashura_pay', $ctx['token'] ),
				'nonce_action' => 'shasmomo_' . $ctx['token'],
				'nonce_name'   => '_shasmomo_nonce',
				'change_url'   => $this->url( 'shashura_pay', $ctx['token'] ),
			),
			200,
			null,
			$ctx,
			2
		);
	}

	/**
	 * Render the approval waiting screen.
	 *
	 * @param array $ctx Order context.
	 * @param array $a   Latest payment attempt.
	 */
	private function render_wait( $ctx, $a ) {
		$elapsed = max( 0, time() - (int) $a['created'] );
		$script  = array(
			'screen'    => 'wait',
			'statusUrl' => $this->url( 'shashura_status', $ctx['token'], array( '_shasmomo_nonce' => wp_create_nonce( 'shasmomo_' . $ctx['token'] ) ) ),
			'window'    => self::RETRY_AFTER,
			'remaining' => max( 0, self::RETRY_AFTER - $elapsed ),
			'labels'    => array(
				'name'      => __( 'Paid by', 'shashura-mobile-money-for-camptix' ),
				'phone'     => __( 'Mobile number', 'shashura-mobile-money-for-camptix' ),
				'network'   => __( 'Network', 'shashura-mobile-money-for-camptix' ),
				'amount'    => __( 'Amount', 'shashura-mobile-money-for-camptix' ),
				'reference' => __( 'Reference', 'shashura-mobile-money-for-camptix' ),
				'reconciliation' => __( 'Payment collected; tickets need reconciliation. Do not pay again; contact the organiser.', 'shashura-mobile-money-for-camptix' ),
				'failed'    => __( 'Declined or expired.', 'shashura-mobile-money-for-camptix' ),
				'slow'      => __( 'Still waiting. Keep this page open.', 'shashura-mobile-money-for-camptix' ),
				'expired'   => __( 'No approval yet. Resend the prompt.', 'shashura-mobile-money-for-camptix' ),
			),
		);

		$this->render_page(
			__( 'Approve payment', 'shashura-mobile-money-for-camptix' ),
			'wait',
			array(
				'network'    => $this->network( $a['network'] ),
				'phone'      => $this->masked( $a ),
				'amount'     => 'UGX ' . number_format_i18n( $a['amount'] ),
				'return_url' => $this->url( 'payment_return', $ctx['token'] ),
				'retry_url'  => $this->url( 'shashura_pay', $ctx['token'] ),
			),
			200,
			$script,
			$ctx,
			3
		);
	}

	/**
	 * Render a standalone status or error message screen.
	 *
	 * @param string $title  Heading and page title.
	 * @param string $text   Body message.
	 * @param int    $status HTTP status code.
	 * @param bool   $home   Whether to include a link back to the tickets page.
	 */
	private function render_message( $title, $text, $status = 200, $home = false ) {
		global $camptix;
		$this->render_page(
			$title,
			'message',
			array(
				'heading'  => $title,
				'text'     => $text,
				'tone'     => $status >= 400 ? 'error' : 'info',
				'home_url' => $home ? $camptix->get_tickets_url() : '',
			),
			$status
		);
	}

	/**
	 * Output a standalone payment page and stop.
	 *
	 * The page is its own HTML document (no get_header()/wp_head()), so only this plugin's
	 * assets are printed, by handle, rather than everything other plugins and the theme add.
	 *
	 * @param string     $title    Page title.
	 * @param string     $template Screen template: phone, confirm, wait or message.
	 * @param array      $args     Template variables, escaped in the templates.
	 * @param int        $status   HTTP status.
	 * @param array|null $script   Data for checkout.js (waiting screen).
	 * @param array|null $ctx      Order, for the header summary and the cancel link.
	 * @param int        $step     Progress step 1-3, or 0 for none.
	 */
	private function render_page( $title, $template, $args, $status = 200, $script = null, $ctx = null, $step = 0 ) {
		status_header( $status );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		$accent = sanitize_hex_color( (string) apply_filters( 'shasmomo_accent_color', '#1d4ed8' ) );
		$accent = $accent ? $accent : '#1d4ed8';

		wp_enqueue_style( 'shasmomo-checkout', SHASMOMO_URL . 'assets/css/checkout.css', array(), $this->asset_version( 'assets/css/checkout.css' ) );
		wp_add_inline_style( 'shasmomo-checkout', ':root{--sc-accent:' . $accent . ';--sc-accent-ink:' . $this->readable_ink( $accent ) . ';}' );
		wp_enqueue_script( 'shasmomo-checkout', SHASMOMO_URL . 'assets/js/checkout.js', array(), $this->asset_version( 'assets/js/checkout.js' ), true );
		if ( $script ) {
			wp_add_inline_script( 'shasmomo-checkout', 'window.shasmomoCheckout = ' . wp_json_encode( $script ) . ';', 'before' );
		}

		$layout = array(
			'title'         => $title . ' – ' . get_bloginfo( 'name' ),
			'site_name'     => get_bloginfo( 'name' ),
			'logo'          => $this->merchant_logo(),
			'cancel_url'    => $ctx && $step ? $this->cancel_url( $ctx ) : '',
			'screen'        => $this->template_path( $template ),
			'step'          => (int) $step,
			'steps'         => array(
				1 => __( 'Number', 'shashura-mobile-money-for-camptix' ),
				2 => __( 'Confirm', 'shashura-mobile-money-for-camptix' ),
				3 => __( 'Approve', 'shashura-mobile-money-for-camptix' ),
			),
			'summary'       => $ctx ? $this->summary( $ctx ) : null,
			'show_credit'   => ! empty( $this->options['show_branding'] ),
			'style_handle'  => 'shasmomo-checkout',
			'script_handle' => 'shasmomo-checkout',
		);

		$this->load_template( $this->template_path( 'layout' ), $layout, $args );
		die();
	}

	/** Includes a template with only $layout and $args in scope. */
	private function load_template( $file, $layout, $args ) {
		include $file;
	}
}
