<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Camptix_Indian_Payment_Methods {
	/**
	 * Instance.
	 *
	 * @since
	 * @access static
	 * @var
	 */
	static private $instance;

	/**
	 * Singleton pattern.
	 *
	 * @since
	 * @access private
	 */
	private function __construct() {
	}

	/**
	 * Get instance.
	 *
	 * @since
	 * @access static
	 * @return static
	 */
	static function get_instance() {
		if ( null === static::$instance ) {
			self::$instance = new static();
		}

		return self::$instance;
	}


	/**
	 * Setup
	 *
	 * @since  1.0
	 * @access public
	 */
	public function setup() {
		$this->setup_hooks();
	}


	/**
	 * Setup hooks
	 *
	 * @since  1.0
	 * @access private
	 */
	private function setup_hooks() {
		// Add, save and show extra fields.
		add_action( 'camptix_attendee_form_additional_info', array( $this, 'add_fields' ), 10, 3 );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'add_attendee_info' ), 10, 3 );
		add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_attendee_info' ), 10, 2 );
		add_filter( 'camptix_metabox_attendee_info_additional_rows', array( $this, 'show_attendee_info' ), 10, 2 );
		add_filter( 'camptix_form_edit_attendee_additional_info', array( $this, 'add_fields_edit_attendee_info', ), 10, 1 );
		add_filter( 'camptix_form_edit_attendee_update_post_meta', array( $this, 'save_edited_attendee_info' ), 10, 2 );
		add_filter( 'camptix_form_edit_attendee_custom_error_flags', array( $this, 'edit_attendee_info_Form_error', ), 10, 1 );
		add_filter( 'camptix_attendee_report_extra_columns', array( $this, 'export_attendee_data_column' ), 10, 1 );
		add_filter( 'camptix_attendee_report_column_value', array( $this, 'export_attendee_data_value' ), 10, 3 );
	}

	/**
	 * Add phone field
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @param $form_data
	 * @param $current_count
	 * @param $tickets_selected_count
	 *
	 * @return string
	 */
	public function add_fields( $form_data, $current_count, $tickets_selected_count ) {
		ob_start();
		?>
		<tr class="tix-row-phone">
			<td class="tix-required tix-left">
				<?php esc_html_e( 'Phone Number', 'campt-indian-payment-gateway' ); ?>
				<span class="tix-required-star">*</span>
			</td>
			<?php $value = isset( $form_data['tix_attendee_info'][ $current_count ]['phone'] ) ? $form_data['tix_attendee_info'][ $current_count ]['phone'] : ''; ?>
			<td class="tix-right">
				<input name="tix_attendee_info[<?php echo esc_attr( $current_count ); ?>][phone]" type="text" class="mobile" value="<?php echo esc_attr( $value ); ?>"/><br>
				<small class="message"></small>
			</td>
		</tr>
		<?php
		echo ob_get_clean();
	}


	/**
	 * Add extra attendee information
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @param $attendee
	 * @param $attendee_info
	 * @param $current_count
	 *
	 * @return mixed
	 */
	public function add_attendee_info( $attendee, $attendee_info, $current_count ) {
		// Phone.
		if ( ! empty( $_POST['tix_attendee_info'][ $current_count ]['phone'] ) ) {
			$attendee->phone = trim( $_POST['tix_attendee_info'][ $current_count ]['phone'] );
		}

		return $attendee;
	}


	/**
	 * Save extra attendee information
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @param $attendee_id
	 * @param $attendee
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
	 * @since 1.0
	 * access public
	 *
	 * @param $rows
	 * @param $attendee
	 *
	 * @return array
	 */
	public function show_attendee_info( $rows, $attendee ) {
		// Phone.
		if ( $attendee_phone = get_post_meta( $attendee->ID, 'tix_phone', true ) ) {
			$rows[] = array(
				__( 'Phone Number', 'campt-indian-payment-gateway' ),
				$attendee_phone,
			);
		}

		return $rows;
	}

	/**
	 * Show extra attendee information
	 *
	 * @since 1.0
	 * access public
	 *
	 * @param $attendee
	 *
	 * @return array
	 */
	public function add_fields_edit_attendee_info( $attendee ) {
		ob_start();
		?>
		<tr>
			<td class="tix-required tix-left">
				<?php esc_html_e( 'Phone Number', 'campt-indian-payment-gateway' ); ?>
				<span class="tix-required-star">*</span></td>
			<td class="tix-right">
				<input name="tix_ticket_info[phone]" type="text" value="<?php echo esc_attr( get_post_meta( $attendee->ID, 'tix_phone', true ) ); ?>"/>
			</td>
		</tr>
		<?php
		echo ob_get_clean();
	}


	/**
	 * Set custom error for edit attendee information form
	 *
	 * @since 1.0
	 * access public
	 *
	 * @param $attendee
	 *
	 * @return array
	 */
	public function edit_attendee_info_Form_error( $attendee ) {
		/* @var  CampTix_Plugin $camptix */
		global $camptix;

		// Phone.
		if ( isset( $_POST['tix_attendee_save'] ) ) {
			if ( empty( $_POST['tix_ticket_info']['phone'] ) ) {
				// $camptix->error( __( 'Please fill in all required fields.', 'camptix-indian-payments' ) );
				$_POST['tix_ticket_info']['phone'] = get_post_meta( $attendee->ID, 'tix_phone', true );
			}
		}
	}


	/**
	 * Save edited attendee information
	 *
	 * @since 1.0
	 * access public
	 *
	 * @param $new_ticket_info
	 * @param $attendee
	 *
	 * @return array
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
	 * @since 1.0
	 * access public
	 *
	 * @param array $extra_columns
	 *
	 * @return array
	 */
	public function export_attendee_data_column( $extra_columns ) {
		return array(
			'phone' => __( 'Phone Number', 'campt-indian-payment-gateway' ),
		);
	}

	/**
	 * Add column to export extra attendee information
	 *
	 * @since 1.0
	 * access public
	 *
	 * @param $value
	 * @param $column_name
	 * @param $attendee
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

Camptix_Indian_Payment_Methods::get_instance()->setup();
