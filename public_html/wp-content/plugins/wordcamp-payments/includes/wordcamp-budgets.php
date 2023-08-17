<?php

/*
 * Main class to provide functionality common to all other classes
 */
class WordCamp_Budgets {
	const VERSION                       = '0.1.4';
	const PAYMENT_INFO_RETENTION_PERIOD = 7; // days

	const VIEWER_CAP = 'publish_posts';
	const ADMIN_CAP  = 'manage_options';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init',                   array( __CLASS__, 'register_post_statuses' )               );
		add_action( 'admin_menu',             array( $this, 'register_budgets_menu' )                    );
		add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_common_assets' ),             11    );
		add_filter( 'user_has_cap',           array( __CLASS__, 'user_can_view_payment_details' ), 10, 4 );
		add_filter( 'default_title',          array( $this, 'set_default_payments_title' ),        10, 2 );
	}

	/**
	 * Register all post statuses used by any CPTs.
	 */
	public static function register_post_statuses() {
		// Uses core's draft status too.

		register_post_status( 'wcb-incomplete', array(
			'label'       => esc_html_x( 'Incomplete', 'payment request', 'wordcamporg' ),
			'public'      => false,
			'protected'   => true,
			'label_count' => _nx_noop(
				'Incomplete <span class="count">(%s)</span>',
				'Incomplete <span class="count">(%s)</span>',
				'wordcamporg'
			),
		) );

		register_post_status( 'wcb-pending-approval', array(
			'label'       => esc_html_x( 'Pending Approval', 'payment request', 'wordcamporg' ),
			'public'      => false,
			'protected'   => true,
			'label_count' => _nx_noop(
				'Pending Approval <span class="count">(%s)</span>',
				'Pending Approval <span class="count">(%s)</span>',
				'wordcamporg'
			),
		) );

		register_post_status( 'wcb-approved', array(
			'label'       => esc_html_x( 'Approved', 'payment request', 'wordcamporg' ),
			'public'      => false,
			'protected'   => true,
			'label_count' => _nx_noop(
				'Approved <span class="count">(%s)</span>',
				'Approved <span class="count">(%s)</span>',
				'wordcamporg'
			),
		) );

		register_post_status( 'wcb-pending-payment', array(
			'label'       => esc_html_x( 'Payment Sent', 'payment request', 'wordcamporg' ),
			'public'      => false,
			'protected'   => true,
			'label_count' => _nx_noop(
				'Payment Sent <span class="count">(%s)</span>',
				'Payment Sent <span class="count">(%s)</span>',
				'wordcamporg'
			),
		) );

		register_post_status( 'wcb-paid', array(
			'label'       => esc_html_x( 'Paid', 'payment request', 'wordcamporg' ),
			'public'      => false,
			'protected'   => true,
			'label_count' => _nx_noop(
				'Paid <span class="count">(%s)</span>',
				'Paid <span class="count">(%s)</span>',
				'wordcamporg'
			),
		) );

		register_post_status( 'wcb-failed', array(
			'label'       => esc_html_x( 'Failed', 'payment request', 'wordcamporg' ),
			'public'      => false,
			'protected'   => true,
			'label_count' => _nx_noop(
				'Failed <span class="count">(%s)</span>',
				'Failed <span class="count">(%s)</span>',
				'wordcamporg'
			),
		) );

		register_post_status( 'wcb-cancelled', array(
			'label'       => esc_html_x( 'Cancelled', 'payment request', 'wordcamporg' ),
			'public'      => false,
			'protected'   => true,
			'label_count' => _nx_noop(
				'Cancelled <span class="count">(%s)</span>',
				'Cancelled <span class="count">(%s)</span>',
				'wordcamporg'
			),
		) );
	}

	/**
	 * Register the Budgets menu
	 *
	 * This is just an empty page so that a top-level menu can be created to hold the various post types and pages.
	 *
	 * @todo This may no longer be needed once the Budgets post type and Overview pages are added
	 */
	public function register_budgets_menu() {
		add_menu_page(
			esc_html__( 'WordCamp Budget', 'wordcamporg' ),
			esc_html__( 'Budget',          'wordcamporg' ),
			self::VIEWER_CAP,
			'wordcamp-budget',
			function() {
				do_action( 'wcb_render_budget_page' );
			},
			plugins_url( 'images/dollar-sign-icon.svg', dirname( __FILE__ ) ),
			30
		);
	}

	/**
	 * Set default post title for reimbursements, vendor payments and sponsor invoices.
	 *
	 * @param  string  $post_title Default post title.
	 * @param  WP_Post $post       Current post object.
	 *
	 * @return string $post_title Post title.
	 */
	public function set_default_payments_title( $post_title, $post ) {
		if ( $post instanceof WP_Post && ! empty( $post->post_type ) ) {
			$new_title = '';

			// Generate default title for payment CPTs.
			switch ( $post->post_type ) {
				case 'wcb_reimbursement':
					$new_title = __( 'Reimbursement Request', 'wordcamporg' );
					break;
				case 'wcp_payment_request':
					$new_title = __( 'Vendor Payment', 'wordcamporg' );
					break;
				case 'wcb_sponsor_invoice':
					$new_title = __( 'Sponsor Invoice', 'wordcamporg' );
					break;
			}

			// Prepend title with post ID to make it unique.
			if ( $new_title ) {
				$post_title = sprintf( __( '[%1$s] Untitled %2$s', 'wordcamporg' ), $post->ID, $new_title );
			}
		}

		return $post_title;
	}

	/**
	 * Enqueue scripts and stylesheets common to all modules
	 */
	public function enqueue_common_assets() {
		// todo setup grunt to concat/minify js

		wp_enqueue_script(
			'wordcamp-budgets',
			plugins_url( 'javascript/wordcamp-budgets.js', __DIR__ ),
			array( 'jquery', 'jquery-ui-datepicker', 'media-upload', 'media-views' ),
			filemtime( WORDCAMP_PAYMENTS_PATH . '/javascript/wordcamp-budgets.js' ),
			true
		);

		wp_register_script(
			'wcb-attached-files',
			plugins_url( 'javascript/attached-files.js', __DIR__ ),
			array( 'wordcamp-budgets', 'backbone', 'wp-util' ),
			1,
			true
		);

		wp_localize_script(
			'wordcamp-budgets',
			'wcbLocalizedStrings',      // todo merge into WordCampBudgets var
			array(
				'uploadModalTitle'  => esc_html__( 'Attach Supporting Documentation', 'wordcamporg' ),
				'uploadModalButton' => esc_html__( 'Attach Files', 'wordcamporg' ),
			)
		);

		// Let's still include our .css file even if these are unavailable.
		$soft_deps = array( 'jquery-ui', 'wp-datepicker-skins' );
		foreach ( $soft_deps as $key => $handle ) {
			if ( ! wp_style_is( $handle, 'registered' ) ) {
				unset( $soft_deps[ $key ] );
			}
		}

		// Enqueue it on every screen, because it styles the menu icon
		wp_enqueue_style(
			'wordcamp-budgets',
			plugins_url( 'css/wordcamp-budgets.css', __DIR__ ),
			$soft_deps,
			7
		);
	}

	/**
	 * Validate an amount value
	 *
	 * @param string $amount
	 *
	 * @return float
	 */
	public static function validate_amount( $amount ) {
		$amount = sanitize_text_field( $amount );
		$amount = preg_replace( '#[^\d.-]+#', '', $amount );
		$amount = round( floatval( $amount ), 2 );

		return $amount;
	}

	/**
	 * Get a list of valid payment methods
	 *
	 * @param $post_type
	 *
	 * @return array
	 */
	public static function get_valid_payment_methods( $post_type ) {
		$methods = array( 'Direct Deposit', 'Check', 'Wire' );

		if ( WCP_Payment_Request::POST_TYPE === $post_type ) {
			$methods[] = 'Credit Card';
		}

		return $methods;
	}

	/**
	 * Validate and save payment method fields
	 *
	 * @param int $post_id
	 */
	public static function validate_save_payment_method_fields( $post_id, $meta_key_prefix ) {
		if ( ! current_user_can( 'view_wordcamp_payment_details' ) ) {
			return;
		}

		foreach ( $_POST as $key => $unsafe_value ) {
			$unsafe_value = wp_unslash( $unsafe_value );

			switch ( $key ) {
				case 'invoice_number':
				case 'bank_name':
				case 'bank_street_address':
				case 'bank_city':
				case 'bank_state':
				case 'bank_zip_code':
				case 'bank_bic':

				case 'interm_bank_name':
				case 'interm_bank_street_address':
				case 'interm_bank_city':
				case 'interm_bank_state':
				case 'interm_bank_zip_code':
				case 'interm_bank_swift':
				case 'interm_bank_account':

				case 'beneficiary_account_number':
				case 'beneficiary_name':
				case 'beneficiary_street_address':
				case 'beneficiary_city':
				case 'beneficiary_state':
				case 'beneficiary_zip_code':

				case 'payable_to':
				case 'check_street_address':
				case 'check_city':
				case 'check_state':
				case 'check_zip_code':

				case 'ach_bank_name':
				case 'ach_routing_number':
				case 'ach_account_number':
				case 'ach_account_holder_name':
					$safe_value = sanitize_text_field( $unsafe_value );
					break;

				case 'ach_account_type':
					if ( in_array( $unsafe_value, array( 'Personal', 'Company' ) ) ) {
						$safe_value = $unsafe_value;
					} else {
						$safe_value = false;
					}
					break;

				case 'payment_method':
					if ( in_array( $unsafe_value, self::get_valid_payment_methods( $_POST['post_type'] ), true ) ) {
						$safe_value = $unsafe_value;
					} else {
						$safe_value = false;
					}
					break;

				/**
				 * Country names now come from CLDR instead of directly from ISO-3166. These meta key
				 * names are therefore legacy, but still technically accurate, and not worth changing.
				 */
				case 'vendor_country_iso3166':
				case 'bank_country_iso3166':
				case 'interm_bank_country_iso3166':
				case 'beneficiary_country_iso3166':
				case 'check_country':
					if ( array_key_exists( $unsafe_value, wcorg_get_countries() ) ) {
						$safe_value = $unsafe_value;
					}
					break;

				default:
					$safe_value = null;
					break;
			}

			if ( is_null( $safe_value ) ) {
				continue;
			}

			if ( in_array( $key, self::get_encrypted_fields() ) ) {
				$encrypted_value = WCP_Encryption::encrypt( $safe_value );

				if ( ! is_wp_error( $encrypted_value ) ) {
					$safe_value = $encrypted_value;
				}
			}

			update_post_meta( $post_id, "_{$meta_key_prefix}_" . $key, $safe_value );
		}

		// Checkboxes
		foreach ( array( 'needs_intermediary_bank' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, "_{$meta_key_prefix}_" . $key, sanitize_text_field( $_POST[ $key ] ) );
			} else {
				delete_post_meta( $post_id, "_{$meta_key_prefix}_" . $key );
			}
		}
	}

	/**
	 * Get the names of all the fields that should be encrypted
	 *
	 * @return array
	 */
	public static function get_encrypted_fields() {
		return array(
			'payable_to',
			'check_street_address',
			'check_city',
			'check_state',
			'check_zip_code',
			'check_country',
			'beneficiary_name',
			'beneficiary_account_number',
			'beneficiary_street_address',
			'beneficiary_city',
			'beneficiary_state',
			'beneficiary_zip_code',
			'beneficiary_country_iso3166',
			'bank_name',
			'bank_street_address',
			'bank_city',
			'bank_state',
			'bank_zip_code',
			'bank_country_iso3166',
			'bank_bic',
			'interm_bank_name',
			'interm_bank_street_address',
			'interm_bank_city',
			'interm_bank_state',
			'interm_bank_zip_code',
			'interm_bank_country_iso3166',
			'interm_bank_swift',
			'interm_bank_account',
			'ach_bank_name',
			'ach_routing_number',
			'ach_account_number',
			'ach_account_holder_name',
		);
	}

	/**
	 * Get a list of ISO 4217 currencies, sorted by name.
	 *
	 * @todo Move this to helper-functions.php so it can be more cleanly reused, and update calls to it.
	 *
	 * @return array
	 */
	public static function get_currencies() {
		$currencies = array(
			'AFN' => 'Afghan Afghani',
			'ALL' => 'Albanian Lek',
			'DZD' => 'Algerian Dinar',
			'AOA' => 'Angolan Kwanza',
			'ARS' => 'Argentine Peso',
			'AMD' => 'Armenian Dram',
			'AWG' => 'Aruban Florin',
			'AUD' => 'Australian Dollar',
			'AZN' => 'Azerbaijan Manat',
			'BSD' => 'Bahamian Dollar',
			'BHD' => 'Bahraini Dinar',
			'BDT' => 'Bangladeshi Taka',
			'BBD' => 'Barbados Dollar',
			'BYN' => 'Belarusian Ruble',
			'BZD' => 'Belize Dollar',
			'BMD' => 'Bermudian Dollar',
			'BTN' => 'Bhutanese Ngultrum',
			'BOB' => 'Bolivian Boliviano',
			'BOV' => 'Bolivian Mvdol',
			'BAM' => 'Bosnia and Herzegovina Convertible Mark',
			'BWP' => 'Botswana Pula',
			'BRL' => 'Brazilian Real',
			'BND' => 'Bruneian Dollar',
			'BGN' => 'Bulgarian Lev',
			'MMK' => 'Burmese (Myanmar) Kyat',
			'BIF' => 'Burundian Franc',
			'CVE' => 'Cabo Verde Escudo',
			'KHR' => 'Cambodian Riel',
			'CAD' => 'Canadian Dollar',
			'KYD' => 'Cayman Islands Dollar',
			'XAF' => 'Central African CFA Franc BEAC',
			'XPF' => 'Change Franc Pacifique (CFP) Franc',
			'CLP' => 'Chilean Peso',
			'CNY' => 'Chinese Yuan Renminbi',
			'COP' => 'Colombian Peso',
			'KMF' => 'Comorian Franc ',
			'CDF' => 'Congolese Franc',
			'NIO' => 'Cordoba Oro',
			'CRC' => 'Costa Rican Colon',
			'HRK' => 'Croatian Kuna',
			'CUC' => 'Cuban Convertible Peso',
			'CUP' => 'Cuban Peso',
			'CZK' => 'Czech Koruna',
			'DKK' => 'Danish Krone',
			'DJF' => 'Djibouti Franc',
			'DOP' => 'Dominican Peso',
			'XCD' => 'East Caribbean Dollar',
			'XSU' => 'Ecuadorian Sucre',
			'EGP' => 'Egyptian Pound',
			'SVC' => 'El Salvador Colon',
			'ERN' => 'Eritrean Nakfa',
			'ETB' => 'Ethiopian Birr',
			'EUR' => 'European Zone Euro',
			'FKP' => 'Falkland Islands Pound',
			'FJD' => 'Fiji Dollar',
			'GMD' => 'Gambian Dalasi',
			'GEL' => 'Georgian Lari',
			'GHS' => 'Ghana Cedi',
			'GIP' => 'Gibraltar Pound',
			'GTQ' => 'Guatemalan Quetzal',
			'GNF' => 'Guinean Franc',
			'GYD' => 'Guyana Dollar',
			'HTG' => 'Haitian Gourde',
			'HNL' => 'Honduran Lempira',
			'HKD' => 'Hong Kong Dollar',
			'HUF' => 'Hungarian Forint',
			'ISK' => 'Iceland Krona',
			'INR' => 'Indian Rupee',
			'IDR' => 'Indonesian Rupiah',
			'IRR' => 'Iranian Rial',
			'IQD' => 'Iraqi Dinar',
			'JMD' => 'Jamaican Dollar',
			'JPY' => 'Japanese Yen',
			'JOD' => 'Jordanian Dinar',
			'KZT' => 'Kazakhstani Tenge',
			'KES' => 'Kenyan Shilling',
			'KWD' => 'Kuwaiti Dinar',
			'KGS' => 'Kyrgystani Som',
			'LAK' => 'Lao Kip',
			'LBP' => 'Lebanese Pound',
			'LSL' => 'Lesotho Loti',
			'LRD' => 'Liberian Dollar',
			'LYD' => 'Libyan Dinar',
			'MOP' => 'Macanese Pataca',
			'MKD' => 'Macedonian Denar',
			'MGA' => 'Malagasy Ariary',
			'MWK' => 'Malawi Kwacha',
			'MYR' => 'Malaysian Ringgit',
			'MVR' => 'Maldivian Rufiyaa',
			'MRO' => 'Mauritanian Ouguiya',
			'MUR' => 'Mauritius Rupee',
			'MXN' => 'Mexican Peso',
			'MXV' => 'Mexican Unidad de Inversion (UDI)',
			'MDL' => 'Moldovan Leu',
			'MNT' => 'Mongolian Tugrik',
			'MAD' => 'Moroccan Dirham',
			'MZN' => 'Mozambique Metical',
			'NAD' => 'Namibia Dollar',
			'NPR' => 'Nepalese Rupee',
			'ANG' => 'Netherlands Antillean Guilder',
			'ILS' => 'New Israeli Sheqel',
			'TWD' => 'New Taiwan Dollar',
			'NZD' => 'New Zealand Dollar',
			'NGN' => 'Nigerian Naira',
			'KPW' => 'North Korean Won',
			'NOK' => 'Norwegian Krone',
			'PKR' => 'Pakistan Rupee',
			'PAB' => 'Panamanian Balboa',
			'PGK' => 'Papua New Guinean Kina',
			'PYG' => 'Paraguayan Guarani',
			'PEN' => 'Peruvian Sol',
			'UYU' => 'Peso Uruguayo',
			'PHP' => 'Philippine Piso',
			'PLN' => 'Polish Zloty',
			'QAR' => 'Qatari Rial',
			'OMR' => 'Rial Omani',
			'RON' => 'Romanian Leu',
			'RUB' => 'Russian Ruble',
			'RWF' => 'Rwanda Franc',
			'SHP' => 'Saint Helena Pound',
			'WST' => 'Samoan Tala',
			'SAR' => 'Saudi Riyal',
			'RSD' => 'Serbian Dinar',
			'SCR' => 'Seychelles Rupee',
			'SLL' => 'Sierra Leonean Leone',
			'SGD' => 'Singapore Dollar',
			'SBD' => 'Solomon Islands Dollar',
			'SOS' => 'Somali Shilling',
			'ZAR' => 'South African Rand',
			'KRW' => 'South Korean Won',
			'SSP' => 'South Sudanese Pound',
			'LKR' => 'Sri Lanka Rupee',
			'SDG' => 'Sudanese Pound',
			'SRD' => 'Surinam Dollar',
			'SZL' => 'Swazi Lilangeni',
			'SEK' => 'Swedish Krona',
			'CHF' => 'Swiss Franc',
			'SYP' => 'Syrian Pound',
			'STD' => 'São Tomé & Príncipe Dobra',
			'TJS' => 'Tajikistani Somoni',
			'TZS' => 'Tanzanian Shilling',
			'THB' => 'Thai Baht',
			'TOP' => 'Tongan Paʻanga',
			'TTD' => 'Trinidad and Tobago Dollar',
			'TND' => 'Tunisian Dinar',
			'TRY' => 'Turkish Lira',
			'TMT' => 'Turkmenistan New Manat',
			'UGX' => 'Uganda Shilling',
			'UAH' => 'Ukrainian Hryvnia',
			'CLF' => 'Unidad de Fomento',
			'COU' => 'Unidad de Valor Real',
			'AED' => 'United Arab Emirates Dirham',
			'GBP' => 'United Kingdom Pound Sterling',
			'USD' => 'United States Dollar',
			'UZS' => 'Uzbekistan Sum',
			'VUV' => 'Vanuatu Vatu',
			'VEF' => 'Venezuelan Bolívar',
			'VND' => 'Vietnamese Dong',
			'CHE' => 'WIR Euro',
			'CHW' => 'WIR Franc',
			'XOF' => 'West African CFA Franc',
			'YER' => 'Yemeni Rial',
			'ZMW' => 'Zambian Kwacha',
			'ZWL' => 'Zimbabwe Dollar',
		);

		return $currencies;
	}

	/**
	 * Define the payment categories
	 *
	 * The slugs are explicitly registered in English so they will match across sites that use different locales,
	 * which facilitates aggregating the data into reports.
	 *
	 * @return array
	 */
	public static function get_payment_categories() {
		$categories = array(
			// Changes here may need to be synchronized with `_get_default_budget_og_wordcamp()` or `_get_default_budget_next_gen_wordcamp`.
			'after-party'     => esc_html__( 'After Party',                    'wordcamporg' ),
			'audio-visual'    => esc_html__( 'Audio Visual',                   'wordcamporg' ),
			'camera-shipping' => esc_html__( 'Camera Shipping',                'wordcamporg' ),
			'food-beverages'  => esc_html__( 'Food & Beverage',                'wordcamporg' ),
			'office-supplies' => esc_html__( 'Office Supplies',                'wordcamporg' ),
			'signage-badges'  => esc_html__( 'Signage & Badges',               'wordcamporg' ),
			'speaker-event'   => esc_html__( 'Speaker Event',                  'wordcamporg' ),
			'swag'            => esc_html__( 'Swag (t-shirts, stickers, etc)', 'wordcamporg' ),
			'venue'           => esc_html__( 'Venue',                          'wordcamporg' ),
			'other'           => esc_html__( 'Other',                          'wordcamporg' ), // This one is intentionally last, regardless of alphabetical order
		);

		if ( is_wordcamp_type('next-gen') ) {
			unset($categories['speaker-event'], $categories['after-party'], $categories['camera-shipping']);
		}

		return $categories;
	}

	/**
	 * Determines whether we want to perform actions on the given post based on the current context.
	 *
	 * Examples of actions we might perform are saving the meta fields during the `save_post` hook, or send out an
	 * e-mail notification during the `transition_post_status` hook.
	 *
	 * This function is called by several other functions, each of which may require additional checks that are
	 * specific to their circumstances. This function only covers checks that are common to all of its callers.
	 *
	 * @param WP_Post|array $post
	 * @param string        $valid_post_type
	 *
	 * @return bool
	 */
	public static function post_edit_is_actionable( $post, $valid_post_type ) {
		if ( is_array( $post ) ) {
			$post = (object) $post;
		}

		$is_actionable   = true;
		$ignored_actions = array( 'trash', 'untrash', 'restore', 'bulk_edit' ); // todo ignore bulk deletion too

		// Don't take action on other post types
		if ( ! $post || $post->post_type != $valid_post_type ) {
			$is_actionable = false;
		}

		// Don't take action if the user isn't allowed. The ID will be missing from new posts during `wp_insert_post_data`, though, so skip it then.
		if ( $is_actionable && isset( $post->ID ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			$is_actionable = false;
		}

		// Don't take action while trashing the post, etc
		if ( $is_actionable && isset( $_GET['action'] ) && in_array( $_GET['action'], $ignored_actions ) ) {
			$is_actionable = false;
		}

		// Don't take action during autosaves
		if ( $is_actionable && ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || $post->post_status == 'auto-draft' ) ) {
			$is_actionable = false;
		}

		return $is_actionable;
	}

	/**
	 * Get the name of the requester
	 *
	 * @param int $post_author_id
	 *
	 * @return string
	 */
	public static function get_requester_name( $post_author_id ) {
		$requester_name = '';

		$author = get_user_by( 'id', $post_author_id );

		if ( is_a( $author, 'WP_User' ) ) {
			$requester_name = $author->get( 'display_name' );
		}

		return $requester_name;
	}

	/**
	 * Get the e-mail address of the requester in `Name <address>` format
	 *
	 * @param int $post_author_id
	 *
	 * @return false|string
	 */
	public static function get_requester_formatted_email( $post_author_id ) {
		$address   = false;
		$requester = get_user_by( 'id', $post_author_id );

		if ( is_a( $requester, 'WP_User' ) ) {
			$address = sprintf( '%s <%s>', $requester->get( 'display_name' ), $requester->get( 'user_email' ) );
		}

		return $address;
	}

	/**
	 * Check if a request post meets the requirements to be submitted for review.
	 *
	 * @param WP_Post $post
	 */
	public static function can_submit_request( $post ) {
		if ( ! current_user_can( 'manage_network' ) ) {
			// A request must have documentation attached before it can be submitted.
			$files = self::get_attached_files( $post );
			if ( empty( $files ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the files attached to a post
	 *
	 * @param WP_Post $post
	 *
	 * @return array
	 */
	public static function get_attached_files( $post ) {
		$files = get_posts( array(
			'post_parent'    => $post->ID,
			'post_type'      => 'attachment',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',

			// Make sure that `WordCamp\Budgets\Privacy\hide_others_payment_files()` runs.
			'suppress_filters' => false,
		) );

		foreach ( $files as &$file ) {
			$file->filename = wp_basename( $file->guid );
			$file->url      = wp_get_attachment_url( $file->ID );
		}

		return $files;
	}

	/**
	 * Attach unattached files to the Vendor Payment post
	 *
	 * Sometimes users will upload the files manually to the Media Library, instead of using the Add Files button,
	 * and we need to attach them to the request so that they show up in the metabox.
	 *
	 * NOTE: The calling function must remove any of its save_post callbacks before calling this, in order to
	 * avoid infinite recursion
	 *
	 * @param int   $post_id
	 * @param array $request
	 */
	public static function attach_existing_files( $post_id, $request ) {
		if ( empty( $request['wcb_existing_files_to_attach'] ) ) {
			return;
		}

		if ( ! $files = json_decode( $request['wcb_existing_files_to_attach'] ) ) {
			return;
		}

		foreach ( $files as $file_id ) {
			wp_update_post( array(
				'ID'          => $file_id,
				'post_parent' => $post_id,
			) );
		}
	}

	/**
	 * Display the indicator that marks a form field as required
	 */
	public static function render_form_field_required_indicator() {
		require dirname( __DIR__ ) . '/views/wordcamp-budgets/form-field-required-indicator.php';
	}

	/**
	 * Get the current post when inside a `map_meta_cap` callback
	 *
	 * Normally it's just the global $post, but sometimes you have to dig it out of $args (e.g., a bulk edit)
	 *
	 * @param array $args The $args that was passed to the `map_meta_cap` callback
	 *
	 * @return WP_Post|null
	 */
	public static function get_map_meta_cap_post( $args ) {
		$post = null;

		/*
		 * Use a reference to the global $post if it already exists, but don't create one otherwise
		 *
		 * i.e., Don't use `global $post` and then assign the result of `get_post()` to that.
		 *
		 * If $GLOBAL['post'] didn't already exist and then we created one, then that could have unintentional
		 * side-effects outside of this function.
		 */
		if ( isset( $GLOBALS['post'] ) ) {
			$post = $GLOBALS['post'];
		} elseif ( isset( $args[0] ) && is_int( $args[0] ) ) {
			$post = get_post( $args[0] );
		}

		return $post;
	}

	/**
	 * Limit access to payment details to protect privacy.
	 *
	 * Only network admins and the request's author should be able to see the details. Trusted deputies
	 * do not need access, since they can't issue payments.
	 *
	 * @filter user_has_cap.
	 *
	 * @param array   $users_capabilities  All of the user's capabilities.
	 * @param array   $mapped_capabilities All capabilities required to perform the given capability.
	 * @param array   $args                (optional) Additional parameters passed to WP_User::has_cap().
	 * @param WP_User $user                The user whose capabilities we're modifying.
	 *
	 * @return array
	 */
	public static function user_can_view_payment_details( $users_capabilities, $mapped_capabilities, $args, $user ) {
		global $post;

		$target_capability                        = 'view_wordcamp_payment_details';
		$users_capabilities[ $target_capability ] = false;

		/*
		 * We also want network admins to have access, but it isn't necessary to explicitly add them
		 * here, because `has_cap()` always returns `true` for them.
		 */
		if ( in_array( $target_capability, $args ) && isset( $post->post_author ) && $post->post_author == $user->ID ) {
			$users_capabilities[ $target_capability ] = true;
		}

		return $users_capabilities;
	}

	/**
	 * Insert an entry into a log for one of the custom post types
	 *
	 * @param int    $post_id The post ID.
	 * @param string $message A log message.
	 * @param array  $data    Optional data.
	 */
	public static function log( $post_id, $user_id, $message, $data = array() ) {
		global $wpdb;

		$data['user_id'] = $user_id; // for backwards-compatibility

		$entry = array(
			'timestamp' => time(),
			'message'   => $message,
			'data'      => $data,
		);

		$log = get_post_meta( $post_id, '_wcp_log', true );
		if ( empty( $log ) ) {
			$log = '[]';
		}

		$log   = json_decode( $log, true );
		$log[] = $entry;
		$log   = json_encode( $log );

		update_post_meta( $post_id, '_wcp_log', wp_slash( $log ) );
	}
}
