<?php

namespace WordCamp\CampTix_Tweaks;
use CampTix_Plugin, CampTix_Addon;
use WP_Post;

defined( 'WPINC' ) || die();

/**
 * Require attendees to agree to a COVID-19 waiver when they register for tickets.
 *
 * See https://make.wordpress.org/community/2021/09/13/announcement-updated-guidelines-for-in-person-wordcamps/
 */
class Covid_Waiver_Field extends CampTix_Addon {
	const SLUG = 'covid-waiver';

	/**
	 * Hook into WordPress and Camptix.
	 */
	public function camptix_init() {
		$wordcamp   = get_wordcamp_post();
		$is_virtual = isset( $wordcamp->meta['Virtual event only'][0] ) && '1' === $wordcamp->meta['Virtual event only'][0];

		if ( $is_virtual ) {
			return; // There's no need for the waiver since online events have no risk of transmission.
		}

		// Registration field.
		add_action( 'camptix_attendee_form_after_questions', array( $this, 'render_registration_field' ), 15, 2 );
		add_filter( 'camptix_checkout_attendee_info', array( $this, 'validate_registration_field' ) );
		add_action( 'camptix_form_attendee_info_errors', array( $this, 'add_registration_field_validation_error' ) );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'populate_attendee_object' ), 10, 2 );
		add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_registration_field' ), 10, 2 );

		// Metabox.
		add_filter( 'camptix_metabox_attendee_info_additional_rows', array( $this, 'add_metabox_row' ), 15, 2 );
	}

	/**
	 * Render the new field for the registration form during checkout.
	 *
	 * @param array $form_data
	 * @param int   $i
	 */
	public function render_registration_field( $form_data, $i ) {
		$current_data = isset( $form_data['tix_attendee_info'][ $i ] ) ? $form_data['tix_attendee_info'][ $i ] : array();

		$current_data = wp_parse_args(
			$current_data,
			array(
				self::SLUG => false,
			)
		);

		$current_data[ self::SLUG ] = wp_validate_boolean( $current_data[ self::SLUG ] );

		?>

		<tr class="tix-row-<?php echo esc_attr( self::SLUG ); ?>">
			<td class="tix-required tix-left" colspan="2">

				<p>
					<label>
						<input
							name="tix_attendee_info[<?php echo esc_attr( $i ); ?>][<?php echo esc_attr( self::SLUG ); ?>]"
							type="checkbox"
							<?php checked( $current_data[ self::SLUG ] ); ?>
							required
						/>
						<?php esc_html_e( 'Please check this box to confirm your acceptance to these conditions and risks:', 'wordcamporg' ); ?>
					</label>

					<span aria-hidden="true" class="tix-required-star">*</span>
				</p>

				<p>
					<?php

					esc_html_e( 'An inherent risk of exposure to COVID-19 exists in any public place where people are present.', 'wordcamporg' );
					echo '&nbsp;';
					esc_html_e( 'COVID-19 is an extremely contagious disease that can lead to severe illness and death.', 'wordcamporg' );
					echo '&nbsp;';
					esc_html_e( 'According to the World Health Organization, senior citizens and guests with underlying medical conditions are especially vulnerable.', 'wordcamporg' );

					?>
				</p>

				<p>
					<?php

					// translators: %s: name of WordCamp (e.g., "WordCamp São Paulo 2021").
					echo esc_html( sprintf(
						__( 'By attending %s, you voluntarily assume all risks related to exposure to COVID-19 and waive any claims against the event organizers; volunteers; sponsors; the WordPress Foundation; WordPress Community Support, PBC; and their respective affiliates.', 'wordcamporg' ),
						get_wordcamp_name()
					) );

					?>
				</p>
			</td>
		</tr>

		<?php
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

		if ( ! isset( $data[ self::SLUG ] ) || empty( $data[ self::SLUG ] ) ) {
			$camptix->error_flags[ self::SLUG . '_unchecked' ] = true;
		}

		$data[ self::SLUG ] = wp_validate_boolean( $data[ self::SLUG ] );

		if ( true !== $data[ self::SLUG ] ) {
			$camptix->error_flags[ self::SLUG . '_unchecked' ] = true;
		}

		return $data;
	}

	/**
	 * Add a validation message when the checkbox isn't checked.
	 *
	 * @param array $error_flags
	 */
	public function add_registration_field_validation_error( $error_flags ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		if ( isset( $error_flags[ self::SLUG . '_unchecked' ] ) ) {
			$camptix->error( __( 'You must agree to accept COVID-19 conditions and risks in to obtain a ticket.', 'wordcamporg' ) );
		}
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
		$attendee->{ self::SLUG } = $data[ self::SLUG ];

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
	 * Add a row to the Attendee Info metabox table for the new field and value.
	 *
	 * @param array   $rows
	 * @param WP_Post $post
	 *
	 * @return mixed
	 */
	public function add_metabox_row( $rows, $post ) {
		$value   = get_post_meta( $post->ID, 'tix_' . self::SLUG, true ) ? _x( 'Yes', 'ticket registration option', 'wordcamporg' ) : '';
		$new_row = array( __( 'Do you agree to COVID-19 conditions and risks?', 'wordcamporg' ), esc_html( $value ) );

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

			$slice = array_slice( $rows, $position );

			array_unshift( $slice, $new_row );
			array_splice( $rows, $position, count( $rows ), $slice );
		} else {
			$rows[] = $new_row;
		}

		return $rows;
	}
}

camptix_register_addon( __NAMESPACE__ . '\Covid_Waiver_Field' );
