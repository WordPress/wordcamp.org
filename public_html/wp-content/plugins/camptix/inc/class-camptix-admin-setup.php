<?php
// phpcs:ignoreFile
/**
 * CampTix Admin Setup
 *
 * Handles the Tickets > Setup admin page, including settings registration,
 * field rendering, options validation, and the setup page UI.
 *
 * @since 2.0.0
 */
class CampTix_Admin_Setup {

	/**
	 * @var CampTix_Plugin
	 */
	protected $plugin;

	/**
	 * @param CampTix_Plugin $plugin The main CampTix plugin instance.
	 */
	public function __construct( CampTix_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks for admin setup functionality.
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_head', array( $this, 'admin_menu_fix' ) );
	}

	/**
	 * Runs during admin_init. Registers settings and loads help tabs.
	 */
	function admin_init() {
		register_setting( 'camptix_options', 'camptix_options', array( $this, 'validate_options' ) );

		// Add settings fields
		$this->menu_setup_controls();

		// Let's add some help tabs.
		require_once dirname( __FILE__ ) . '/../help.php';
	}

	function menu_setup_controls() {
		wp_enqueue_script( 'jquery-ui' );
		$section = $this->get_setup_section();

		add_action( 'admin_notices', array( $this, 'admin_notice_supported_currencies' ) );

		switch ( $section ) {
			case 'general':
				add_settings_section( 'general', __( 'General Configuration', 'wordcamporg' ), array( $this, 'menu_setup_section_general' ), 'camptix_options' );
				$this->add_settings_field_helper( 'event_name', __( 'Event Name', 'wordcamporg' ), 'field_text' );
				$this->add_settings_field_helper( 'currency', __( 'Currency', 'wordcamporg' ), 'field_currency' );

				$this->add_settings_field_helper( 'refunds_enabled', __( 'Enable Refund Requests by Attendees', 'wordcamporg' ), 'field_enable_refunds', false,
					__( "This will allows your customers to refund their tickets purchase by filling out a simple refund form. Organizers are able to refund regardless of this setting.", 'wordcamporg' )
				);

				break;
			case 'payment':
				foreach ( $this->plugin->get_available_payment_methods() as $key => $payment_method ) {
					$payment_method_obj = $this->plugin->get_payment_method_by_id( $key );

					add_settings_section( 'payment_' . $key, $payment_method_obj->name, array( $payment_method_obj, '_camptix_settings_section_callback' ), 'camptix_options' );
					$options = $this->plugin->get_options();
					add_settings_field( 'payment_method_' . $key . '_enabled', __( 'Enabled', 'wordcamporg' ), array( $payment_method_obj, '_camptix_settings_enabled_callback' ), 'camptix_options', 'payment_' . $key, array(
						'name' => "camptix_options[payment_methods][{$key}]",
						'value' => isset( $options['payment_methods'][$key] ) ? (bool) $options['payment_methods'][ $key ] : false,
					) );

					$payment_method_obj->payment_settings_fields();
				}
				break;
			case 'email-templates':
				add_settings_section( 'general', __( 'E-mail Templates', 'wordcamporg' ), array( $this, 'menu_setup_section_email_templates' ), 'camptix_options' );
				$this->add_settings_field_helper( 'email_template_single_purchase', __( 'Single purchase', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_multiple_purchase', __( 'Multiple purchase', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_multiple_purchase_receipt', __( 'Multiple purchase (receipt)', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_pending_succeeded', __( 'Pending Payment Succeeded', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_pending_failed', __( 'Pending Payment Failed', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_single_refund', __( 'Single Refund', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_multiple_refund', __( 'Multiple Refund', 'wordcamporg' ), 'field_textarea' );

				foreach ( apply_filters( 'camptix_custom_email_templates', array() ) as $key => $template ) {
					$this->add_settings_field_helper( $key, $template['title'], $template['callback_method'] );
				}

				// Add a reset templates button
				add_action( 'camptix_setup_buttons', array( $this, 'setup_buttons_reset_templates' ) );
				break;
			case 'beta':

				if ( ! $this->plugin->beta_features_enabled )
					break;

				add_settings_section( 'general', __( 'Beta Features', 'wordcamporg' ), array( $this, 'menu_setup_section_beta' ), 'camptix_options' );

				$this->add_settings_field_helper( 'reservations_enabled', __( 'Enable Reservations', 'wordcamporg' ), 'field_yesno', false,
					__( "Reservations is a way to make sure that a certain group of people, can always purchase their tickets, even if you sell out fast.", 'wordcamporg' )
				);

				if ( current_user_can( $this->plugin->caps['refund_all'] ) ) {
					$this->add_settings_field_helper( 'refund_all_enabled', __( 'Enable Refund All', 'wordcamporg' ), 'field_yesno', false,
						__( "Allows to refund all purchased tickets by an admin via the Tools menu.", 'wordcamporg' )
					);
				}

				$this->add_settings_field_helper( 'archived', __( 'Archived Event', 'wordcamporg' ), 'field_yesno', false,
					__( "Archived events are read-only.", 'wordcamporg' )
				);
				break;
			default:
				do_action( 'camptix_menu_setup_controls', $section );
				break;
		}
	}

	function menu_setup_section_beta() {
		echo '<p>' . __( 'Beta features are things that are being worked on in CampTix, but are not quite finished yet. You can try them out, but we do not recommend doing that in a live environment on a real event. If you have any kind of feedback on any of the beta features, please let us know.', 'wordcamporg' ) . '</p>';
	}

	function menu_setup_section_email_templates() {
		?>

		<p><?php _e( 'Customize your confirmation e-mail templates.', 'wordcamporg' ); ?></p>

		<p>
			<?php _e( 'You can use the following shortcodes inside the message: [buyer_full_name], [first_name], [last_name], [email], [event_name], [ticket_url], and [receipt].', 'wordcamporg' ); ?>
		</p>

		<?php if ( CampTix_Plugin::html_mail_enabled() ) : ?>
			<p>
				<?php printf(
					__( 'You can use the following HTML tags inside the message: %s.', 'wordcamporg' ),
					esc_html( CampTix_Plugin::get_allowed_html_mail_tags( 'display' ) )
				); ?>
			</p>
		<?php endif; ?>

		<?php
	}

	function menu_setup_section_general() {
		echo '<p>' . __( 'General configuration.', 'wordcamporg' ) . '</p>';
	}

	/**
	 * I don't like repeating code, so here's a helper for simple fields.
	 */
	function add_settings_field_helper( $key, $title, $callback_method, $section = false, $description = false ) {
		if ( ! $section )
			$section = 'general';

		$options = $this->plugin->get_options();

		$args = array(
			'name' => sprintf( 'camptix_options[%s]', $key ),
			'value' => ( ! empty( $options[ $key ] ) ) ? $options[ $key ] : null,
		);

		if ( $description )
			$args['description'] = $description;

		add_settings_field( $key, $title, array( $this, $callback_method ), 'camptix_options', $section, $args );
	}

	function setup_buttons_reset_templates() {
		submit_button( __( 'Reset Default', 'wordcamporg' ), 'secondary', 'tix-reset-templates', false );
	}

	/**
	 * Validates options in Tickets > Setup.
	 */
	function validate_options( $input ) {
		$output = $this->plugin->get_options();

		// General
		if ( isset( $input['event_name'] ) )
			$output['event_name'] = sanitize_text_field( strip_tags( $input['event_name'] ) );

		if ( isset( $input['currency'] ) && array_key_exists( $input['currency'], $this->plugin->get_currencies() ) )
			$output['currency'] = $input['currency'];

		if ( isset( $input['refunds_date_end'], $input['refunds_enabled'] ) && (bool) $input['refunds_enabled'] && strtotime( $input['refunds_date_end'] ) )
			$output['refunds_date_end'] = $input['refunds_date_end'];

		$yesno_fields = array( 'refunds_enabled' );

		// Beta features checkboxes
		if ( $this->plugin->beta_features_enabled )
			$yesno_fields = array_merge( $yesno_fields, $this->plugin->get_beta_features() );

		foreach ( $yesno_fields as $field )
			if ( isset( $input[ $field ] ) )
				$output[ $field ] = (bool) $input[ $field ];

		if ( isset( $input['version'] ) )
			$output['version'] = $input['version'];

		// Enabled/disabled payment methods.
		if ( isset( $input['payment_methods'] ) ) {
			foreach ( $this->plugin->get_available_payment_methods() as $key => $method ) {
				if ( isset( $input['payment_methods'][ $key ] ) ) {
					$output['payment_methods'][ $key ] = (bool) $input['payment_methods'][ $key ];
				}
			}
		}

		// E-mail templates
		$email_templates = array_merge(
			array(
				'email_template_single_purchase',
				'email_template_multiple_purchase',
				'email_template_multiple_purchase_receipt',
				'email_template_pending_succeeded',
				'email_template_pending_failed',
				'email_template_single_refund',
				'email_template_multiple_refund',
			),
			array_keys( apply_filters( 'camptix_custom_email_templates', array() ) )
		);

		foreach ( $email_templates as $template ) {
			if ( isset( $input[ $template ] ) ) {
				$output[ $template ] = wp_kses( $input[ $template ], CampTix_Plugin::get_allowed_html_mail_tags() );
			}
		}

		// If the Reset Defaults button was hit
		if ( isset( $_POST['tix-reset-templates'] ) ) {
			foreach ( $email_templates as $template ) {
				unset( $output[ $template ] );
			}
		}

		$output = apply_filters( 'camptix_validate_options', $output, $input );

		$current_user = wp_get_current_user();
		$log_data = array(
			'old'      => $this->plugin->get_options(),
			'new'      => $output,
			'username' => $current_user->user_login,
		);
		$this->plugin->log( 'Options updated.', 0, $log_data );

		return $output;
	}

	/**
	 * Show an admin notice when the selected currency is not supported by any enabled payment methods.
	 *
	 * @return void
	 */
	public function admin_notice_supported_currencies() {
		global $pagenow;
		$page = wp_unslash( $_GET['page'] ?? '' );

		if ( 'edit.php' !== $pagenow || 'camptix_options' !== $page ) {
			return;
		}

		$options    = $this->plugin->get_options();
		$currencies = $this->plugin->get_currencies();

		if ( ! array_key_exists( $options['currency'], $currencies ) ) {
			$base_url = add_query_arg(
				array(
					'post_type' => 'tix_ticket',
					'page'      => 'camptix_options',
				),
				admin_url( 'edit.php' )
			);
			?>
			<div class="notice notice-warning">
				<?php
				echo wpautop( sprintf(
					__( 'The <a href="%1$s">currently selected currency</a> is not supported by any of the <a href="%2$s">enabled payment methods</a>.' ),
					esc_url( add_query_arg( 'tix_section', 'general', $base_url ) ),
					esc_url( add_query_arg( 'tix_section', 'payment', $base_url ) )
				) );
				?>
			</div>
			<?php
		}
	}

	/**
	 * A text input for the Settings API, name and value attributes
	 * should be specified in $args. Same goes for the rest.
	 */
	function field_text( $args ) {
		?>
		<input type="text" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo esc_attr( $args['value'] ); ?>" class="regular-text" />
		<?php
	}

	function field_textarea( $args ) {
		?>
		<textarea class="large-text" rows="5" name="<?php echo esc_attr( $args['name'] ); ?>"><?php echo esc_textarea( $args['value'] ); ?></textarea>
		<?php
	}

	/**
	 * A checkbox field for the Settings API.
	 */
	function field_checkbox( $args ) {
		$args = array_merge(
			array(
				'id'    => '',
				'name'  => '',
				'class' => '',
				'value' => ''
			),
			$args
		)

		?>

		<input
			type="checkbox"
			id="<?php echo esc_attr( $args['name'] ); ?>"
			name="<?php echo esc_attr( $args['name'] ); ?>"
			class="<?php echo sanitize_html_class( $args['class'] ); ?>"
			value="1"
			<?php checked( $args['value'] ); ?> />

		<?php
	}

	/**
	 * A yes-no field for the Settings API.
	 */
	function field_yesno( $args ) {
		?>
		<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="1" <?php checked( $args['value'], true ); ?>> <?php _e( 'Yes', 'wordcamporg' ); ?></label>
		<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="0" <?php checked( $args['value'], false ); ?>> <?php _e( 'No', 'wordcamporg' ); ?></label>

		<?php if ( isset( $args['description'] ) ) : ?>
		<p class="description"><?php echo wp_kses_data( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	function field_enable_refunds( $args ) {
		$options = $this->plugin->get_options();
		$refunds_enabled = (bool) $options['refunds_enabled'];
		$refunds_date_end = isset( $options['refunds_date_end'] ) && strtotime( $options['refunds_date_end'] ) ? $options['refunds_date_end'] : date( 'Y-m-d' );
		?>
		<div id="tix-refunds-enabled-radios">
			<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="1" <?php checked( $args['value'], true ); ?>> <?php _e( 'Yes', 'wordcamporg' ); ?></label>
			<label class="tix-yes-no description"><input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="0" <?php checked( $args['value'], false ); ?>> <?php _e( 'No', 'wordcamporg' ); ?></label>
		</div>

		<div id="tix-refunds-date" class="<?php if ( ! $refunds_enabled ) echo 'hide-if-js'; ?>" style="margin: 20px 0;">
			<label><?php _e( 'Allow refunds until:', 'wordcamporg' ); ?></label>
			<input type="text" name="camptix_options[refunds_date_end]" value="<?php echo esc_attr( $refunds_date_end ); ?>" class="tix-date-field" />
		</div>

		<?php if ( isset( $args['description'] ) ) : ?>
		<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * The currency field for the Settings API.
	 */
	function field_currency( $args ) {
		$currencies = $this->plugin->get_currencies();
		?>
			<select name="<?php echo esc_attr( $args['name'] ); ?>">
				<?php if ( ! array_key_exists( $args['value'], $currencies ) ) : ?>
					<option value="<?php echo esc_attr( $args['value'] ); ?>" selected >
						<?php
						printf(
							__( '%s: No payment method', 'wordcamporg' ),
							esc_html( $args['value'] )
						);
						?>
					</option>
				<?php endif; ?>
				<?php foreach ( $currencies as $key => $currency ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $args['value'] ); ?>><?php
						echo esc_html( $currency['label'] );
						echo " (" . esc_html( $this->plugin->append_currency( 10000, true, $key ) ) . ")";
					?></option>
				<?php endforeach; ?>
			</select>

			<p class="description">
				<?php _e( 'If you don\'t see your desired currency in the list, make sure you have at least one payment method enabled that supports it.', 'wordcamporg' ); ?>
			</p>
		<?php
	}

	/**
	 * When squeezing several custom post types under one top-level menu item, WordPress
	 * tends to get confused which menu item is currently active, especially around post-new.php.
	 * This function runs during admin_head and hacks into some of the global variables that are
	 * used to construct the menu.
	 */
	function admin_menu_fix() {
		global $self, $parent_file, $submenu_file, $plugin_page, $pagenow, $typenow;

		// Make sure Coupons is selected when adding a new coupon
		if ( 'post-new.php' == $pagenow && 'tix_coupon' == $typenow )
			$submenu_file = 'edit.php?post_type=tix_coupon';

		// Make sure Attendees is selected when adding a new attendee
		if ( 'post-new.php' == $pagenow && 'tix_attendee' == $typenow )
			$submenu_file = 'edit.php?post_type=tix_attendee';

		// Make sure Tickets is selected when creating a new ticket
		if ( 'post-new.php' == $pagenow && 'tix_ticket' == $typenow )
			$submenu_file = 'edit.php?post_type=tix_ticket';
	}

	/**
	 * The Tickets > Setup screen, uses the Settings API.
	 */
	function menu_setup() {
		?>
		<div class="wrap">
			<h1><?php _e( 'CampTix Setup', 'wordcamporg' ); ?></h1>
			<?php settings_errors(); ?>
			<h3 class="nav-tab-wrapper"><?php $this->menu_setup_tabs(); ?></h3>
			<form method="post" action="options.php" class="tix-setup-form">
				<?php
					settings_fields( 'camptix_options' );
					do_settings_sections( 'camptix_options' );
				?>
				<p class="submit">
					<?php submit_button( '', 'primary', 'submit', false ); ?>
					<?php do_action( 'camptix_setup_buttons' ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Returns the current setup section from the request.
	 */
	function get_setup_section() {
		if ( isset( $_REQUEST['tix_section'] ) )
			return strtolower( $_REQUEST['tix_section'] );

		return 'general';
	}

	/**
	 * Tabs for Tickets > Setup, outputs the markup.
	 */
	function menu_setup_tabs() {
		$current_section = $this->get_setup_section();
		$sections = array(
			'general' => __( 'General', 'wordcamporg' ),
			'payment' => __( 'Payment', 'wordcamporg' ),
			'email-templates' => __( 'E-mail Templates', 'wordcamporg' ),
		);

		if ( $this->plugin->beta_features_enabled )
			$sections['beta'] = __( 'Beta', 'wordcamporg' );

		$sections = apply_filters( 'camptix_setup_sections', $sections );

		foreach ( $sections as $section_key => $section_caption ) {
			$active = $current_section === $section_key ? 'nav-tab-active' : '';
			$url = add_query_arg( 'tix_section', $section_key );
			echo '<a class="nav-tab ' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $section_caption ) . '</a>';
		}
	}
}
