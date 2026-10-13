<?php
namespace CamptixBD;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Phone_Field {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Setup hooks
	 */
	private function setup_hooks() {
		// Add, save and show extra fields.
		add_action( 'camptix_attendee_form_additional_info', array( $this, 'add_fields' ), 10, 3 );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'add_attendee_info' ), 10, 3 );
		add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_attendee_info' ), 10, 2 );
		add_filter( 'camptix_metabox_attendee_info_additional_rows', array( $this, 'show_attendee_info' ), 10, 2 );
		add_filter( 'camptix_form_edit_attendee_additional_info', array( $this, 'add_fields_edit_attendee_info' ), 10, 1 );
		add_filter( 'camptix_form_edit_attendee_update_post_meta', array( $this, 'save_edited_attendee_info' ), 10, 2 );
		add_filter( 'camptix_form_edit_attendee_custom_error_flags', array( $this, 'edit_attendee_info_form_error' ), 10, 1 );
		add_filter( 'camptix_attendee_report_extra_columns', array( $this, 'export_attendee_data_column' ), 10, 1 );
		add_filter( 'camptix_attendee_report_column_value', array( $this, 'export_attendee_data_value' ), 10, 3 );
		add_action( 'camptix_checkout_start', array( $this, 'validate_phone_on_checkout' ), 10, 2 );
		add_action( 'camptix_form_attendee_info_errors', array( $this, 'render_phone_errors' ), 10, 1 );
	}

	/**
	 * Add phone field
	 *
	 * @param array $form_data              Form data.
	 * @param int   $current_count          Current attendee index.
	 * @param int   $tickets_selected_count Number of selected tickets.
	 *
	 * @return void
	 */
	public function add_fields( $form_data, $current_count, $tickets_selected_count ) {
		?>
		<tr class="tix-row-phone">
			<td class="tix-required tix-left">
				<?php esc_html_e( 'Phone Number', 'bd-payments-camptix' ); ?>
				<span class="tix-required-star">*</span>
			</td>
			<?php $value = isset( $form_data['tix_attendee_info'][ $current_count ]['phone'] ) ? $form_data['tix_attendee_info'][ $current_count ]['phone'] : ''; ?>
			<td class="tix-right">
				<input name="tix_attendee_info[<?php echo esc_attr( $current_count ); ?>][phone]" type="text" class="mobile" value="<?php echo esc_attr( $value ); ?>"/><br>
				<small class="message"></small>
			</td>
		</tr>
		<?php
	}


	/**
	 * Add extra attendee information
	 *
	 * @param object $attendee      Attendee object.
	 * @param array  $attendee_info Attendee info.
	 * @param int    $current_count Current attendee index.
	 *
	 * @return mixed
	 */
	public function add_attendee_info( $attendee, $attendee_info, $current_count ) {
		// Phone.
		if ( ! empty( $attendee_info['phone'] ) ) {
			$phone = sanitize_text_field( $attendee_info['phone'] );

			if ( '' !== $phone ) {
				$attendee->phone = $phone;
			}
		}

		return $attendee;
	}


	/**
	 * Save extra attendee information
	 *
	 * @param int    $attendee_id Attendee post ID.
	 * @param object $attendee    Attendee object.
	 */
	public function save_attendee_info( $attendee_id, $attendee ) {
		// Phone.
		if ( property_exists( $attendee, 'phone' ) ) {
			update_post_meta( $attendee_id, 'tix_phone', $attendee->phone );
		}
	}

	/**
	 * Show extra attendee information
	 *
	 * @param array  $rows     Existing attendee info rows.
	 * @param object $attendee Attendee post object.
	 *
	 * @return array
	 */
	public function show_attendee_info( $rows, $attendee ) {
		// Phone.
		$attendee_phone = get_post_meta( $attendee->ID, 'tix_phone', true );

		if ( $attendee_phone ) {
			$rows[] = array(
				__( 'Phone Number', 'bd-payments-camptix' ),
				$attendee_phone,
			);
		}

		return $rows;
	}

	/**
	 * Show extra attendee information
	 *
	 * @since 1.0
	 * @param object $attendee Attendee post object.
	 *
	 * @return void
	 */
	public function add_fields_edit_attendee_info( $attendee ) {
		?>
		<tr>
			<td class="tix-required tix-left">
				<?php esc_html_e( 'Phone Number', 'bd-payments-camptix' ); ?>
				<span class="tix-required-star">*</span>
			</td>
			<td class="tix-right">
				<input name="tix_ticket_info[phone]" type="text" value="<?php echo esc_attr( get_post_meta( $attendee->ID, 'tix_phone', true ) ); ?>"/>
			</td>
		</tr>
		<?php
	}


	/**
	 * Set custom error for edit attendee information form
	 *
	 * @since 1.0
	 * @param object $attendee Attendee post object.
	 *
	 * @return void
	 */
	public function edit_attendee_info_form_error( $attendee ) {
		/* @var  CampTix_Plugin $camptix */
		global $camptix;

		// Phone.
		if ( isset( $_POST['tix_attendee_save'] ) ) {
			if ( empty( $_POST['tix_ticket_info']['phone'] ) ) {
				$_POST['tix_ticket_info']['phone'] = get_post_meta( $attendee->ID, 'tix_phone', true );
			} else {
				$_POST['tix_ticket_info']['phone'] = sanitize_text_field( $_POST['tix_ticket_info']['phone'] );
			}
		}
	}


	/**
	 * Validate phone number during checkout.
	 *
	 * Fires on the `camptix_checkout_start` action, which passes the submitted
	 * attendee info and the current order. Errors are flagged via CampTix's own
	 * error_flag() API so they surface in `camptix_form_attendee_info_errors`.
	 *
	 * @param array $attendee_info Submitted attendee information.
	 * @param array $order         Current CampTix order.
	 *
	 * @return void
	 */
	public function validate_phone_on_checkout( $attendee_info, $order ) {
		global $camptix;

		if ( ! $this->selected_gateway_requires_phone( $order ) ) {
			return;
		}

		foreach ( (array) $attendee_info as $info ) {
			$phone = sanitize_text_field( $info['phone'] ?? '' );

			if ( empty( $phone ) ) {
				$camptix->error_flag( 'tix_phone_missing' );
			} elseif ( ! $this->is_valid_bd_phone( $phone ) ) {
				$camptix->error_flag( 'tix_phone_invalid' );
			}
		}
	}

	/**
	 * Render phone validation errors on the attendee info form.
	 *
	 * Fires on the `camptix_form_attendee_info_errors` action. CampTix passes
	 * the current error_flags array so we can output the appropriate messages.
	 *
	 * @param array $error_flags Current error flags.
	 *
	 * @return void
	 */
	public function render_phone_errors( $error_flags ) {
		global $camptix;

		if ( isset( $error_flags['tix_phone_missing'] ) ) {
			$camptix->error( __( 'Please enter a phone number for Bangladeshi payment processing.', 'bd-payments-camptix' ) );
		}

		if ( isset( $error_flags['tix_phone_invalid'] ) ) {
			$camptix->error( __( 'Please enter a valid Bangladeshi phone number.', 'bd-payments-camptix' ) );
		}
	}

	/**
	 * Check if the selected checkout gateway needs a Bangladeshi phone number.
	 *
	 * @param array $order Current CampTix order passed from camptix_checkout_start.
	 *
	 * @return bool
	 */
	private function selected_gateway_requires_phone( $order ) {
		$payment_method = sanitize_text_field( $_POST['tix_payment_method'] ?? '' );

		if ( ! in_array( $payment_method, array( 'sslcommerz', 'surjopay' ), true ) ) {
			return false;
		}

		return ! empty( $order['total'] ) && (float) $order['total'] > 0;
	}

	/**
	 * Validate Bangladeshi phone number format
	 *
	 * Accepts:
	 * - 01XXXXXXXXX (11 digits, local format)
	 * - +8801XXXXXXXXX (14 digits, international)
	 * - 8801XXXXXXXXX (13 digits, international without +)
	 *
	 * @param string $phone Phone number to validate.
	 *
	 * @return bool
	 */
	private function is_valid_bd_phone( $phone ) {
		$cleaned = preg_replace( '/[\s\-\(\)]/', '', $phone );

		// Local format: 01XXXXXXXXX (11 digits).
		if ( preg_match( '/^01[3-9]\d{8}$/', $cleaned ) ) {
			return true;
		}

		// International format: +8801XXXXXXXXX (14 chars).
		if ( preg_match( '/^\+8801[3-9]\d{8}$/', $cleaned ) ) {
			return true;
		}

		// International without +: 8801XXXXXXXXX (13 digits).
		if ( preg_match( '/^8801[3-9]\d{8}$/', $cleaned ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Save edited attendee information
	 *
	 * @param array  $new_ticket_info Updated ticket info.
	 * @param object $attendee        Attendee post object.
	 *
	 * @return void
	 */
	public function save_edited_attendee_info( $new_ticket_info, $attendee ) {
		// Phone.
		if ( array_key_exists( 'phone', $new_ticket_info ) ) {
			update_post_meta( $attendee->ID, 'tix_phone', sanitize_text_field( $new_ticket_info['phone'] ) );
		}
	}


	/**
	 * Add column to export extra attendee information
	 *
	 * @param array $extra_columns
	 *
	 * @return array
	 */
	public function export_attendee_data_column( $extra_columns ) {
		$extra_columns['phone'] = __( 'Phone Number', 'bd-payments-camptix' );

		return $extra_columns;
	}

	/**
	 * Add column to export extra attendee information
	 *
	 * @param mixed  $value       Column value.
	 * @param string $column_name Column name.
	 * @param object $attendee    Attendee post object.
	 *
	 * @return mixed
	 */
	public function export_attendee_data_value( $value, $column_name, $attendee ) {
		switch ( $column_name ) {
			case 'phone':
				$value = get_post_meta( $attendee->ID, 'tix_phone', true );
				break;
		}

		return $value;
	}
}
