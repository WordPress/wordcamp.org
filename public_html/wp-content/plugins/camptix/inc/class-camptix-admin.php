<?php

class Camptix_Admin extends CampTix_Plugin {

	/**
	 * Fired as soon as this file is loaded, don't do anything
	 * but filters and actions here.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * runs on init action.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_head', array( $this, 'admin_menu_fix' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	/**
	 * Oh the holy admin menu!
	 */
	public function admin_menu() {
		add_submenu_page( 'edit.php?post_type=tix_ticket', __( 'Tools', 'wordcamporg' ), __( 'Tools', 'wordcamporg' ), $this->caps['manage_tools'], 'camptix_tools', array( $this, 'menu_tools' ) );
		add_submenu_page( 'edit.php?post_type=tix_ticket', __( 'Setup', 'wordcamporg' ), __( 'Setup', 'wordcamporg' ), $this->caps['manage_options'], 'camptix_options', array( $this, 'menu_setup' ) );
		add_submenu_page( 'edit.php?post_type=tix_ticket', __( 'Badges', 'wordcamporg' ), __( 'Badges', 'wordcamporg' ), $this->caps['manage_options'], 'camptix_badges', array( $this, 'menu_badges' ) );
		add_submenu_page( 'edit.php?post_type=tix_ticket', __( 'Profile Badges', 'wordcamporg' ), __( 'Profile Badges', 'wordcamporg' ), $this->caps['manage_options'], 'camptix_badges', 'Camptix\Profile_Badges\menu_badges' );
		remove_submenu_page( 'edit.php?post_type=tix_ticket', 'post-new.php?post_type=tix_ticket' );
	}

	/**
	 * When squeezing several custom post types under one top-level menu item, WordPress
	 * tends to get confused which menu item is currently active, especially around post-new.php.
	 * This function runs during admin_head and hacks into some of the global variables that are
	 * used to construct the menu.
	 */
	public function admin_menu_fix() {
		global $self, $parent_file, $submenu_file, $plugin_page, $pagenow, $typenow;

		// Make sure Coupons is selected when adding a new coupon.
		if ( 'post-new.php' == $pagenow && 'tix_coupon' == $typenow ) {
			$submenu_file = 'edit.php?post_type=tix_coupon';
		}

		// Make sure Attendees is selected when adding a new attendee.
		if ( 'post-new.php' == $pagenow && 'tix_attendee' == $typenow ) {
			$submenu_file = 'edit.php?post_type=tix_attendee';
		}

		// Make sure Tickets is selected when creating a new ticket.
		if ( 'post-new.php' == $pagenow && 'tix_ticket' == $typenow ) {
			$submenu_file = 'edit.php?post_type=tix_ticket';
		}
	}

	/**
	 * The Tickets > Setup screen, uses the Settings API.
	 */
	public function menu_setup() {
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
	 * Tabs for Tickets > Tools, outputs the markup.
	 */
	public function menu_setup_tabs() {
		$current_section = $this->get_setup_section();
		$sections = array(
			'general' => __( 'General', 'wordcamporg' ),
			'payment' => __( 'Payment', 'wordcamporg' ),
			'email-templates' => __( 'E-mail Templates', 'wordcamporg' ),
		);

		if ( $this->beta_features_enabled ) {
			$sections['beta'] = __( 'Beta', 'wordcamporg' );
		}

		$sections = apply_filters( 'camptix_setup_sections', $sections );

		foreach ( $sections as $section_key => $section_caption ) {
			$active = $current_section === $section_key ? 'nav-tab-active' : '';
			$url = add_query_arg( 'tix_section', $section_key );
			echo '<a class="nav-tab ' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $section_caption ) . '</a>';
		}
	}

	/**
	 * The Tickets > Tools screen, doesn't use the settings API, but does use tabs.
	 */
	public function menu_tools() {
		?>
		<div class="wrap">
			<h1><?php _e( 'CampTix Tools', 'wordcamporg' ); ?></h1>
			<?php settings_errors(); ?>
			<h3 class="nav-tab-wrapper"><?php $this->menu_tools_tabs(); ?></h3>
			<?php
			$section = $this->get_tools_section();
			if ( 'summarize' == $section ) {
				$this->menu_tools_summarize();
			} elseif ( 'revenue' == $section ) {
				$this->menu_tools_revenue();
			} elseif ( 'export' == $section  ) {
				$this->menu_tools_export();
			} elseif ( 'notify' == $section  ) {
				$this->menu_tools_notify();
			} elseif ( 'refund' == $section  && ! $this->options['archived'] ) {
				$this->menu_tools_refund();
			} else {
				do_action( 'camptix_menu_tools_' . $section );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Remember the tabs in Tickets > Tools? This tells
	 * us which tab is currently active.
	 */
	public function get_setup_section() {
		if ( isset( $_REQUEST['tix_section'] ) ) {
			return strtolower( $_REQUEST['tix_section'] );
		}

		return 'general';
	}

	/**
	 * Remember the tabs in Tickets > Tools? This tells
	 * us which tab is currently active.
	 */
	public function get_tools_section() {
		if ( isset( $_REQUEST['tix_section'] ) ) {
			return strtolower( $_REQUEST['tix_section'] );
		}

		return 'summarize';
	}

	/**
	 * Tabs for Tickets > Tools, outputs the markup.
	 */
	public function menu_tools_tabs() {
		$current_section = $this->get_tools_section();
		$sections = apply_filters( 'camptix_menu_tools_tabs', array(
			'summarize' => __( 'Summarize', 'wordcamporg' ),
			'revenue' => __( 'Revenue', 'wordcamporg' ),
			'export' => __( 'Export', 'wordcamporg' ),
			'notify' => __( 'Notify', 'wordcamporg' ),
		) );

		if ( current_user_can( $this->caps['refund_all'] ) && ! $this->options['archived'] && $this->options['refund_all_enabled'] ) {
			$sections['refund'] = esc_html__( 'Refund', 'wordcamporg' );
		}

		foreach ( $sections as $section_key => $section_caption ) {
			$active = $current_section === $section_key ? 'nav-tab-active' : '';
			$url = add_query_arg( 'tix_section', $section_key );
			echo '<a class="nav-tab ' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $section_caption ) . '</a>';
		}
	}

	/**
	 * Tools > Summarize, the screen that outputs the summary tables,
	 * provides an export option, powered by the summarize_admin_init method,
	 * hooked (almost) at admin_init, because of additional headers. Doesn't use
	 * the Settings API so check for nonces/referrers and caps.
	 * @see summarize_admin_init()
	 */
	public function menu_tools_summarize() {
		$summarize_by = isset( $_POST['tix_summarize_by'] ) ? $_POST['tix_summarize_by'] : 'ticket';
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'tix_summarize', 1 ) ); ?>">
			<table class="form-table">
				<tbody>
				<tr>
					<th scope="row"><?php _e( 'Summarize by', 'wordcamporg' ); ?></th>
					<td>
						<select name="tix_summarize_by">
							<?php foreach ( $this->get_available_summary_fields() as $value => $caption ) : ?>
								<?php
								if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) )
									$caption = mb_strlen( $caption ) > 30 ? mb_substr( $caption, 0, 30 ) . '...' : $caption;
								else
									$caption = strlen( $caption ) > 30 ? substr( $caption, 0, 30 ) . '...' : $caption;
								?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $summarize_by ); ?>><?php echo esc_html( $caption ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				</tbody>
			</table>
			<p class="submit">
				<?php wp_nonce_field( 'tix_summarize' ); ?>
				<input type="hidden" name="tix_summarize_submit" value="1" />
				<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Show Summary', 'wordcamporg' ); ?>" />
				<input type="submit" name="tix_export_summary" value="<?php esc_attr_e( 'Export Summary to CSV', 'wordcamporg' ); ?>" class="button" />
			</p>
		</form>

		<?php if ( isset( $_POST['tix_summarize_submit'] ) && check_admin_referer( 'tix_summarize' ) && array_key_exists( $summarize_by, $this->get_available_summary_fields() ) ) : ?>
			<?php
			$fields = $this->get_available_summary_fields();
			$summary = $this->get_summary( $summarize_by );
			$summary_title = $fields[ $summarize_by ];
			$alt = '';

			$rows = array();
			foreach ( $summary as $entry ) {
				$rows[] = array(
					esc_html( $summary_title )   => esc_html( $entry['label'] ),
					esc_html__( 'Count', 'wordcamporg' ) => esc_html( $entry['count'] )
				);
			}
			// Render the widefat table.
			$this->table( $rows, 'widefat tix-summarize' );
			?>

		<?php endif; // summarize_submit ?>
		<?php
	}

	/**
	 * Hooked at (almost) admin_init, fired if one requested a
	 * Summarize export. Serves the download file.
	 * @see menu_tools_summarize()
	 */
	public function summarize_admin_init() {
		if ( ! current_user_can( $this->caps['manage_tools'] ) || 'summarize' != $this->get_tools_section() ) {
			return;
		}

		if ( isset( $_POST['tix_export_summary'], $_POST['tix_summarize_by'] ) && check_admin_referer( 'tix_summarize' ) ) {
			$summarize_by = $_POST['tix_summarize_by'];
			if ( ! array_key_exists( $summarize_by, $this->get_available_summary_fields() ) )
				return;

			$fields = $this->get_available_summary_fields();
			$summary = $this->get_summary( $summarize_by );
			$summary_title = $fields[ $summarize_by ];
			$filename = sprintf( 'camptix-summary-%s-%s.csv', sanitize_title_with_dashes( $summary_title ), date( 'Y-m-d' ) );

			header( 'Content-Type: text/csv' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( "Cache-control: private" );
			header( 'Pragma: private' );
			header( "Expires: Mon, 26 Jul 1997 05:00:00 GMT" );

			$stream = fopen( "php://output", 'w' );

			$headers = array( $summary_title, __( 'Count', 'wordcamporg' ) );
			fputcsv( $stream, self::esc_csv( $headers ) );
			foreach ( $summary as $entry ) {
				fputcsv( $stream, self::esc_csv( $entry ), ',', '"' );
			}

			fclose( $stream );
			die();
		}
	}

	/**
	 * Helper function to create admin tables, give me a
	 * $rows array and I'll do the rest.
	 */
	public function table( $rows, $classes='widefat' ) {

		if ( ! is_array( $rows ) || ! isset( $rows[0] ) ) {
			return;
		}

		$alt = '';
		?>
		<table class="tix-table <?php echo esc_attr( $classes ); ?>">
			<?php if ( ! is_numeric( implode( '', array_keys( $rows[0] ) ) ) ) : ?>
				<thead>
				<tr>
					<?php foreach ( array_keys( $rows[0] ) as $column ) : ?>
						<th class="tix-<?php echo esc_attr( sanitize_title_with_dashes( $column ) ); ?>">
							<?php echo wp_kses( $column, 'post' ); ?>
						</th>
					<?php endforeach; ?>
				</tr>
				</thead>
			<?php endif; ?>

			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$alt = ( $alt == '' ) ? 'alternate' : '';
				$values = array_values( $row );
				?>
				<tr class="<?php echo esc_attr( $alt ); ?> tix-row-<?php echo sanitize_title_with_dashes( array_shift( $values ) ); ?>">
					<?php foreach ( $row as $column => $value ) : ?>
						<td class="tix-<?php echo esc_attr( sanitize_title_with_dashes( $column ) ); ?>">
							<span><?php echo wp_kses( $value, 'post' ); ?></span>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Menu Setup general section.
	 */
	public function admin_enqueue_scripts() {
		global $wp_query;

		if ( ! $wp_query->query_vars ) { // only on singular admin pages.
			if ( 'tix_ticket' == get_post_type() || 'tix_coupon' == get_post_type() ) {
			}
		}

		// Let's see whether to include admin.css and admin.js.
		if ( is_admin() ) {
			$screen = get_current_screen();
			$post_types = array( 'tix_ticket', 'tix_coupon', 'tix_email', 'tix_attendee' );
			$pages = array( 'camptix_options', 'camptix_tools' );
			$screen_ids = array( 'dashboard' );
			if (
				( in_array( get_post_type(), $post_types ) ) ||
				( in_array( $screen->id, $screen_ids ) ) ||
				( isset( $_REQUEST['post_type'] ) && in_array( $_REQUEST['post_type'], $post_types ) ) ||
				( isset( $_REQUEST['page'] ) && in_array( $_REQUEST['page'], $pages ) )
			) {
				wp_enqueue_script( 'jquery-ui-datepicker' );
				wp_enqueue_style(
					'jquery-ui',
					plugins_url( '/external/jquery-ui.css', __FILE__ ),
					array(),
					filemtime( __DIR__ . '/external/jquery-ui.css' )
				);

				wp_enqueue_style(
					'camptix-admin',
					plugins_url( '/admin.css', __FILE__ ),
					array(),
					filemtime( __DIR__ . '/admin.css' )
				);

				wp_enqueue_script(
					'camptix-admin',
					plugins_url( '/admin.js', __FILE__ ),
					array( 'jquery', 'jquery-ui-datepicker', 'backbone' ),
					filemtime( __DIR__ . '/admin.js' )
				);

				wp_dequeue_script( 'autosave' );
			}
		}

		$screen = get_current_screen();
		if ( 'tix_ticket_page_camptix_options' == $screen->id ) {
			wp_enqueue_script( 'jquery-ui-datepicker' );
			wp_enqueue_style( 'jquery-ui', plugins_url( '/external/jquery-ui.css', __FILE__ ), array(), $this->version );
		}
	}

	/**
	 * Runs during admin_init, mainly for Settings API things.
	 */
	public function admin_init() {
		register_setting( 'camptix_options', 'camptix_options', array( $this, 'validate_options' ) );

		// Add settings fields.
		$this->menu_setup_controls();

		// Let's add some help tabs.
		require_once dirname( __FILE__ ) . '/help.php';
	}

	/**
	 * Menu Setup general section.
	 */
	public function menu_setup_controls() {
		wp_enqueue_script( 'jquery-ui' );
		$section = $this->get_setup_section();

		add_action( 'admin_notices', array( $this, 'admin_notice_supported_currencies' ) );

		switch ( $section ) {
			case 'general':
				add_settings_section( 'general', __( 'General Configuration', 'wordcamporg' ), array( $this, 'menu_setup_section_general' ), 'camptix_options' );
				$this->add_settings_field_helper( 'event_name', __( 'Event Name', 'wordcamporg' ), 'field_text' );
				$this->add_settings_field_helper( 'currency', __( 'Currency', 'wordcamporg' ), 'field_currency' );

				$this->add_settings_field_helper( 'refunds_enabled', __( 'Enable Refunds', 'wordcamporg' ), 'field_enable_refunds', false,
					esc_html__( "This will allows your customers to refund their tickets purchase by filling out a simple refund form.", 'wordcamporg' )
				);

				break;
			case 'payment':
				foreach ( $this->get_available_payment_methods() as $key => $payment_method ) {
					$payment_method_obj = $this->get_payment_method_by_id( $key );

					add_settings_section( 'payment_' . $key, $payment_method_obj->name, array( $payment_method_obj, '_camptix_settings_section_callback' ), 'camptix_options' );
					add_settings_field(
						'payment_method_' . $key . '_enabled',
						__( 'Enabled', 'wordcamporg' ),
						array( $payment_method_obj, '_camptix_settings_enabled_callback' ),
						'camptix_options', 'payment_' . $key, array(
							'name' => "camptix_options[payment_methods][{$key}]",
							'value' => isset( $this->options[ 'payment_methods' ][$key] ) ? (bool) $this->options[ 'payment_methods' ][ $key ] : false,
						)
					);

					$payment_method_obj->payment_settings_fields();
				}
				break;
			case 'email-templates':
				add_settings_section( 'general', __( 'E-mail Templates', 'wordcamporg' ), array( $this, 'menu_setup_section_email_templates' ), 'camptix_options' );
				$this->add_settings_field_helper( 'email_template_single_purchase', esc_html__( 'Single purchase', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_multiple_purchase', esc_html__( 'Multiple purchase', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_multiple_purchase_receipt', esc_html__( 'Multiple purchase (receipt)', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_pending_succeeded', esc_html__( 'Pending Payment Succeeded', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_pending_failed', esc_html__( 'Pending Payment Failed', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_single_refund', esc_html__( 'Single Refund', 'wordcamporg' ), 'field_textarea' );
				$this->add_settings_field_helper( 'email_template_multiple_refund', esc_html__( 'Multiple Refund', 'wordcamporg' ), 'field_textarea' );

				foreach ( apply_filters( 'camptix_custom_email_templates', array() ) as $key => $template ) {
					$this->add_settings_field_helper( $key, $template['title'], $template['callback_method'] );
				}

				// Add a reset templates button.
				add_action( 'camptix_setup_buttons', array( $this, 'setup_buttons_reset_templates' ) );
				break;
			case 'beta':
				if ( ! $this->beta_features_enabled ) {
					break;
				}

				add_settings_section( 'general', esc_html__( 'Beta Features', 'wordcamporg' ), array( $this, 'menu_setup_section_beta' ), 'camptix_options' );

				$this->add_settings_field_helper(
						'reservations_enabled',
						esc_html__( 'Enable Reservations', 'wordcamporg' ),
						'field_yesno',
						false,
						esc_html__( 'Reservations is a way to make sure that a certain group of people, can always purchase their tickets, even if you sell out fast.', 'wordcamporg' )
				);

				if ( current_user_can( $this->caps['refund_all'] ) ) {
					$this->add_settings_field_helper(
						'refund_all_enabled',
						esc_html__( 'Enable Refund All', 'wordcamporg' ),
						'field_yesno', false,
						esc_html__( 'Allows to refund all purchased tickets by an admin via the Tools menu.', 'wordcamporg' )
					);
				}

				$this->add_settings_field_helper(
					'archived',
					esc_html__( 'Archived Event', 'wordcamporg' ),
					'field_yesno',
					false,
					esc_html__( 'Archived events are read-only.', 'wordcamporg' )
				);
				break;
			default:
				do_action( 'camptix_menu_setup_controls', $section );
				break;
		}
	}

	/**
	 * Menu Setup Beta section.
	 */
	public function menu_setup_section_beta() {
		echo '<p>' . esc_html__( 'Beta features are things that are being worked on in CampTix, but are not quite finished yet. You can try them out, but we do not recommend doing that in a live environment on a real event. If you have any kind of feedback on any of the beta features, please let us know.', 'wordcamporg' ) . '</p>';
	}

	/**
	 * Menu Setup Email template section.
	 */
	public function menu_setup_section_email_templates() {
		?>

		<p><?php _e( 'Customize your confirmation e-mail templates.', 'wordcamporg' ); ?></p>

		<p>
			<?php esc_html_e( 'You can use the following shortcodes inside the message: [buyer_full_name], [first_name], [last_name], [email], [event_name], [ticket_url], and [receipt].', 'wordcamporg' ); ?>
		</p>

		<?php if ( self::html_mail_enabled() ) : ?>
			<p>
				<?php printf(
					__( 'You can use the following HTML tags inside the message: %s.', 'wordcamporg' ),
					esc_html( self::get_allowed_html_mail_tags( 'display' ) )
				); ?>
			</p>
		<?php endif; ?>

		<?php
	}

	/**
	 * Menu Setup general section.
	 */
	public function menu_setup_section_general() {
		echo '<p>' . esc_html__( 'General configuration.', 'wordcamporg' ) . '</p>';
	}
}

new Camptix_Admin();