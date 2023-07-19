<?php

namespace WordCamp\CampTix_Tweaks;
defined( 'WPINC' ) or die();

use CampTix_Plugin, CampTix_Addon;
use WP_Post;

/**
 * Class Code_Of_Conduct_Field.
 *
 * Add a non-optional attendee field confirming that they agree to follow the event code of conduct.
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Code_Of_Conduct_Field extends CampTix_Addon {
	const SLUG = 'coc';

	/**
	 * Hook into WordPress and Camptix.
	 */
	public function camptix_init() {
		// Registration field
		add_action( 'camptix_attendee_form_after_questions', array( $this, 'render_registration_field' ), 15, 2 );
		add_filter( 'camptix_checkout_attendee_info', array( $this, 'validate_registration_field' ) );
		add_action( 'camptix_form_attendee_info_errors', array( $this, 'add_registration_field_validation_error' ) );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'populate_attendee_object' ), 10, 2 );
		add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_registration_field' ), 10, 2 );

		// Metabox
		add_filter( 'camptix_metabox_attendee_info_additional_rows', array( $this, 'add_metabox_row' ), 15, 2 );
	}

	/**
	 * Render the new field for the registration form during checkout.
	 *
	 * @param array $form_data
	 * @param int   $i
	 */
	public function render_registration_field( $form_data, $i ) {
		$current_data = ( isset( $form_data['tix_attendee_info'][ $i ] ) ) ?: array();

		$current_data = wp_parse_args( $current_data, array(
			self::SLUG => false,
		) );

		$current_data[ self::SLUG ] = wp_validate_boolean( $current_data[ self::SLUG ] );

		$coc_url = $this->maybe_get_coc_url();
		$question = __( 'Do you agree to follow the event Code of Conduct?', 'wordcamporg' );
		if ( $coc_url ) {
			$question = sprintf(
				/* translators: %s placeholder is a URL */
				__( 'Do you agree to follow the event <a href="%s" target="_blank">Code of Conduct</a>?', 'wordcamporg' ),
				esc_url( $coc_url )
			);
		}

		?>

		<tr class="tix-row-<?php echo esc_attr( self::SLUG ); ?>">
			<td class="tix-required tix-left">
				<?php echo wp_kses_post( $question ); ?>
				<span aria-hidden="true" class="tix-required-star">*</span>
			</td>

			<td class="tix-right">
				<fieldset class="tix-screen-reader-fieldset" aria-label="<?php echo esc_attr( strip_tags( $question ) ); ?>">
					<label>
						<input name="tix_attendee_info[<?php echo esc_attr( $i ); ?>][<?php echo esc_attr( self::SLUG ); ?>]" type="checkbox" <?php checked( $current_data[ self::SLUG ] ); ?> required />
						<?php echo esc_html_x( 'Yes', 'ticket registration option', 'wordcamporg' ); ?>
					</label>
				</fieldset>
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
		/* @var CampTix_Plugin $camptix */
		global $camptix;

		$data[ self::SLUG ] = wp_validate_boolean( $data[ self::SLUG ] ?? false );

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
		/* @var CampTix_Plugin $camptix */
		global $camptix;

		if ( isset( $error_flags[ self::SLUG . '_unchecked' ] ) ) {
			$camptix->error( __( 'You must agree to follow the event Code of Conduct to obtain a ticket.', 'wordcamporg' ) );
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
		$value = get_post_meta( $post->ID, 'tix_' . self::SLUG, true ) ? _x( 'Yes', 'ticket registration option', 'wordcamporg' ) : '';
		$new_row = array( __( 'Do you agree to follow the event Code of Conduct?', 'wordcamporg' ), esc_html( $value ) );

		add_filter( 'locale', array( $this, 'set_locale_to_en_US' ) );

		$ticket_row = array_filter( $rows, function( $row ) {
			if ( 'Ticket' === $row[0] ) {
				return true;
			}

			return false;
		} );

		remove_filter( 'locale', array( $this, 'set_locale_to_en_US' ) );

		if ( ! empty( $ticket_row ) ) {
			$ticket_row_key = key( $ticket_row );
			$row_indexes    = array_keys( $rows );
			$position       = array_search( $ticket_row_key, $row_indexes );

			$slice = array_slice( $rows, $position );

			array_unshift( $slice, $new_row );
			array_splice( $rows, $position, count( $rows ), $slice );
		} else {
			$rows[] = $new_row;
		}

		return $rows;
	}

	/**
	 * If the Code of Conduct page is still the same one created with the site, get its URL.
	 *
	 * @return false|string
	 */
	protected function maybe_get_coc_url() {
		$url = '';

		$coc_page = get_posts( array(
			'post_type'   => 'page',
			'name'        => 'code-of-conduct',
			'numberposts' => 1,
		) );

		if ( $coc_page ) {
			$url = get_the_permalink( array_shift( $coc_page ) );
		}

		return $url;
	}
}

camptix_register_addon( __NAMESPACE__ . '\Code_Of_Conduct_Field' );
