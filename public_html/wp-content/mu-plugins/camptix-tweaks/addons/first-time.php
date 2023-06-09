<?php

namespace WordCamp\CampTix_Tweaks;

defined( 'WPINC' ) || die();

use CampTix_Addon;
use WP_Post;

/**
 * Add an required attendee field asking if they've attended a WordCamp before.
 */
class First_Time_Field extends CampTix_Addon {
	const SLUG = 'first_time_attending_wp_event';

	public $label    = '';
	public $question = '';
	public $options  = array();


	/**
	 * Hook into WordPress and CampTix.
	 */
	public function camptix_init() {
		$this->label    = __( 'First Time Attending', 'wordcamporg' );
		$this->question = __( 'Will this be your first time attending a WordPress event?', 'wordcamporg' );

		$this->options = array(
			'yes' => _x( 'Yes', 'answer to question during ticket registration', 'wordcamporg' ),
			'no'  => _x( 'No', 'answer to question during ticket registration', 'wordcamporg' ),

			// Sometimes people buy tickets for others, and they may not know.
			'unsure'  => _x( "I don't know", 'answer to question during ticket registration', 'wordcamporg' ),
		);

		// Registration field.
		add_action( 'camptix_attendee_form_after_questions', array( $this, 'render_registration_field' ), 12, 2 );
		add_filter( 'camptix_checkout_attendee_info', array( $this, 'validate_registration_field' ), 11 );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'populate_attendee_object' ), 10, 2 );
		add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_registration_field' ), 10, 2 );

		// Edit info field.
		add_filter( 'camptix_form_edit_attendee_ticket_info', array( $this, 'populate_ticket_info_array' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_update_post_meta', array( $this, 'validate_save_ticket_info_field' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_after_questions', array( $this, 'render_ticket_info_field' ), 12 );

		// Metabox.
		add_filter( 'camptix_metabox_attendee_info_additional_rows', array( $this, 'add_metabox_row' ), 12, 2 );

		// Reporting.
		add_filter( 'camptix_summary_fields', array( $this, 'add_summary_field' ) );
		add_action( 'camptix_summarize_by_' . self::SLUG, array( $this, 'summarize' ), 10, 2 );
		add_filter( 'camptix_attendee_report_extra_columns', array( $this, 'add_export_column' ) );
		add_filter( 'camptix_attendee_report_column_value_' . self::SLUG, array( $this, 'add_export_column_value' ), 10, 2 );

		// Privacy.
		add_filter( 'camptix_privacy_attendee_props_to_export', array( $this, 'attendee_props_to_export' ) );
		add_filter( 'camptix_privacy_export_attendee_prop', array( $this, 'export_attendee_prop' ), 10, 4 );
		add_filter( 'camptix_privacy_attendee_props_to_erase', array( $this, 'attendee_props_to_erase' ) );
		add_action( 'camptix_privacy_erase_attendee_prop', array( $this, 'erase_attendee_prop' ), 10, 3 );
	}

	/**
	 * Render the field, used on both Registration and when editing an existing ticket.
	 *
	 * @param string $name
	 * @param string $value
	 * @param int    $ticket_id
	 */
	public function render_field( $name, $value, $ticket_id ) {
		if ( apply_filters( 'camptix_first_time_should_skip', false ) ) {
			return;
		}

		$question = apply_filters( 'camptix_first_time_question_text', $this->question, $ticket_id );
		?>

		<tr class="tix-row-<?php echo esc_attr( self::SLUG ); ?>">
			<td class="tix-required tix-left">
				<?php echo esc_html( $question ); ?>
				<span aria-hidden="true" class="tix-required-star">*</span>
			</td>

			<td class="tix-right">
				<fieldset class="tix-screen-reader-fieldset" aria-label="<?php echo esc_attr( $question ); ?>">
					<label>
						<input
							name="<?php echo esc_attr( $name ); ?>"
							type="radio"
							value="yes"
							<?php checked( 'yes', $value ); ?>
							required
						/>
						<?php echo esc_html( $this->options['yes'] ); ?>
					</label>

					<br />

					<label>
						<input
							name="<?php echo esc_attr( $name ); ?>"
							type="radio"
							value="no"
							<?php checked( 'no', $value ); ?>
							required
						/>
						<?php echo esc_html( $this->options['no'] ); ?>
					</label>

					<br />

					<label>
						<input
							name="<?php echo esc_attr( $name ); ?>"
							type="radio"
							value="unsure"
							<?php checked( 'unsure', $value ); ?>
							required
						/>
						<?php echo esc_html( $this->options['unsure'] ); ?>
					</label>
				</fieldset>
			</td>
		</tr>

		<?php
	}

	/**
	 * Render the new field for the registration form during checkout.
	 *
	 * @param array $form_data
	 * @param int   $i
	 */
	public function render_registration_field( $form_data, $i ) {
		$current_data = $form_data['tix_attendee_info'][ $i ] ?? array();
		$current_data = wp_parse_args( $current_data, array( self::SLUG => '' ) );

		$this->render_field(
			sprintf( 'tix_attendee_info[%d][%s]', $i, self::SLUG ),
			$current_data[ self::SLUG ],
			$current_data['ticket_id']
		);
	}

	/**
	 * Validate the value of the new field submitted to the registration form during checkout.
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function validate_registration_field( $data ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$skip_question = apply_filters( 'camptix_first_time_should_skip', false );
		if ( $skip_question ) {
			return $data;
		}

		$data[ self::SLUG ] = $this->validate_value( $data[ self::SLUG ] ?? '' );

		if ( empty( $data[ self::SLUG ] ) ) {
			$camptix->error_flags['required_fields'] = true;
		}

		return $data;
	}

	/**
	 * Add the value of the new field to the attendee object during checkout processing.
	 *
	 * @param WP_Post $attendee
	 * @param array   $data
	 *
	 * @return WP_Post
	 */
	public function populate_attendee_object( $attendee, $data ) {
		$attendee->{ self::SLUG } = $data[ self::SLUG ] ?? '';

		return $attendee;
	}

	/**
	 * Save the value of the new field to the attendee post upon completion of checkout.
	 *
	 * @param int     $post_id
	 * @param WP_Post $attendee
	 *
	 * @return bool|int
	 */
	public function save_registration_field( $post_id, $attendee ) {
		return update_post_meta( $post_id, 'tix_' . self::SLUG, $attendee->{ self::SLUG } );
	}

	/**
	 * Retrieve the stored value of the new field for use on the Edit Info form.
	 *
	 * @param array   $ticket_info
	 * @param WP_Post $attendee
	 *
	 * @return array
	 */
	public function populate_ticket_info_array( $ticket_info, $attendee ) {
		$ticket_info[ self::SLUG ] = get_post_meta( $attendee->ID, 'tix_' . self::SLUG, true );

		return $ticket_info;
	}

	/**
	 * Update the stored value of the new field if it was changed in the Edit Info form.
	 *
	 * @param array   $data
	 * @param WP_Post $attendee
	 *
	 * @return bool|int
	 */
	public function validate_save_ticket_info_field( $data, $attendee ) {
		if ( empty( $data[ self::SLUG ] ) ) {
			return true;
		}

		$value = $this->validate_value( $data[ self::SLUG ] );

		return update_post_meta( $attendee->ID, 'tix_' . self::SLUG, $value );
	}

	/**
	 * Validate the given value against the valid options.
	 */
	public function validate_value( $value ) {
		if ( ! in_array( $value, array_keys( $this->options ), true ) ) {
			$value = '';
		}

		return $value;
	}

	/**
	 * Render the new field for the Edit Info form.
	 *
	 * @param array $ticket_info
	 */
	public function render_ticket_info_field( $ticket_info ) {
		$current_data = wp_parse_args( $ticket_info, array( self::SLUG => '' ) );

		$this->render_field(
			sprintf( 'tix_ticket_info[%s]', self::SLUG ),
			$current_data[ self::SLUG ],
			$current_data['ticket_id']
		);
	}

	/**
	 * Add a row to the Attendee Info metabox table for the new field and value.
	 *
	 * @param array   $rows
	 * @param WP_Post $post
	 *
	 * @return mixed
	 */
	public function add_metabox_row( $rows, $post ) {
		$label = '';
		$value = get_post_meta( $post->ID, 'tix_' . self::SLUG, true );

		if ( $value && isset( $this->options[ $value ] ) ) {
			$label = $this->options[ $value ];
		}

		$question = apply_filters( 'camptix_first_time_question_text', $this->question, $post->tix_ticket_id );
		$new_row  = array( $question, esc_html( $label ) );

		add_filter( 'locale', array( $this, 'set_locale_to_en_US' ) );

		$ticket_row = array_filter(
			$rows,
			function( $row ) {
				if ( 'Ticket' === $row[0] ) {
					return true;
				}

				return false;
			}
		);

		remove_filter( 'locale', array( $this, 'set_locale_to_en_US' ) );

		if ( ! empty( $ticket_row ) ) {
			$ticket_row_key = key( $ticket_row );
			$row_indexes    = array_keys( $rows );
			$position       = array_search( $ticket_row_key, $row_indexes, true );
			$slice          = array_slice( $rows, $position );

			array_unshift( $slice, $new_row );
			array_splice( $rows, $position, count( $rows ), $slice );
		} else {
			$rows[] = $new_row;
		}

		return $rows;
	}

	/**
	 * Filter: Set the locale to en_US.
	 *
	 * @return string
	 */
	public function set_locale_to_en_US() {
		return 'en_US';
	}

	/**
	 * Add an option to the `Summarize by` dropdown.
	 *
	 * @param array $fields
	 *
	 * @return array
	 */
	public function add_summary_field( $fields ) {
		$fields[ self::SLUG ] = $this->label;

		return $fields;
	}

	/**
	 * Callback to summarize the answers for this field.
	 *
	 * @param array   $summary
	 * @param WP_Post $attendee
	 */
	public function summarize( &$summary, $attendee ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		$answer = get_post_meta( $attendee->ID, 'tix_' . self::SLUG, true );

		if ( isset( $this->options[ $answer ] ) ) {
			$camptix->increment_summary( $summary, $this->options[ $answer ] );
		} else {
			$camptix->increment_summary( $summary, __( 'No answer', 'wordcamporg' ) );
		}
	}

	/**
	 * Add a column to the CSV export.
	 *
	 * @param array $columns
	 *
	 * @return array
	 */
	public function add_export_column( $columns ) {
		$columns[ self::SLUG ] = $this->label;

		return $columns;
	}

	/**
	 * Add the human-readable value of the field to the CSV export.
	 *
	 * @param string  $value
	 * @param WP_Post $attendee
	 *
	 * @return string
	 */
	public function add_export_column_value( $value, $attendee ) {
		$value = get_post_meta( $attendee->ID, 'tix_' . self::SLUG, true );

		if ( isset( $this->options[ $value ] ) ) {
			return $this->options[ $value ];
		}

		return '';
	}

	/**
	 * Include the new field in the personal data exporter.
	 *
	 * @param array $props
	 *
	 * @return array
	 */
	public function attendee_props_to_export( $props ) {
		$props[ 'tix_' . self::SLUG ] = $this->question;

		return $props;
	}

	/**
	 * Add the new field's value and label to the aggregated personal data for export.
	 *
	 * @param array   $export
	 * @param string  $key
	 * @param string  $label
	 * @param WP_Post $post
	 *
	 * @return array
	 */
	public function export_attendee_prop( $export, $key, $label, $post ) {
		if ( 'tix_' . self::SLUG === $key ) {
			$value = get_post_meta( $post->ID, 'tix_' . self::SLUG, true );

			if ( isset( $this->options[ $value ] ) ) {
				$value = $this->options[ $value ];
			}

			if ( ! empty( $value ) ) {
				$export[] = array(
					'name'  => $label,
					'value' => $value,
				);
			}
		}

		return $export;
	}

	/**
	 * Include the new field in the personal data eraser.
	 *
	 * @param array $props
	 *
	 * @return array
	 */
	public function attendee_props_to_erase( $props ) {
		$props[ 'tix_' . self::SLUG ] = 'camptix_yesnounsure';

		return $props;
	}

	/**
	 * Anonymize the value of the new field during personal data erasure.
	 *
	 * @param string  $key
	 * @param string  $type
	 * @param WP_Post $post
	 */
	public function erase_attendee_prop( $key, $type, $post ) {
		if ( 'tix_' . self::SLUG === $key ) {
			$anonymized_value = wp_privacy_anonymize_data( $type );
			update_post_meta( $post->ID, $key, $anonymized_value );
		}
	}
}

camptix_register_addon( __NAMESPACE__ . '\First_Time_Field' );
