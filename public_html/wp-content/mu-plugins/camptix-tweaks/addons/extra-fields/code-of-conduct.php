<?php
namespace WordCamp\CampTix_Tweaks;

use CampTix_Plugin, CampTix_Addon;
use WP_Post;

defined( 'WPINC' ) or die();

/**
 * Class Code_Of_Conduct_Field.
 *
 * Add a non-optional attendee field confirming that they agree to follow the event code of conduct.
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Code_Of_Conduct_Field extends Extra_Field {
	const SLUG = 'coc';

	/**
	 * Hook into WordPress and Camptix.
	 */
	public function camptix_init() {

		// Ask the question.
		add_filter( 'camptix_ticket_questions', array( $this, 'add_question' ), 10, 2 );
		add_filter( 'camptix_ticket_questions_order', array( $this, 'add_question_order' ), 50 );
		add_filter( 'camptix_get_attendee_answers', array( $this, 'populate_attendee_answer' ), 10, 2 );

		// Save the answer as post meta.
		add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_registration_field' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_update_post_meta', array( $this, 'edit_attendee_data' ), 10, 3 );

		// Registration field
		add_filter( 'camptix_checkout_attendee_info', array( $this, 'validate_registration_field' ) );
		add_action( 'camptix_form_attendee_info_errors', array( $this, 'add_registration_field_validation_error' ) );
	}

	/**
	 * Add the question to the list of questions.
	 *
	 * @param array $questions
	 *
	 * @return array
	 */
	function add_question( $questions, $ticket_id ) {
		if ( apply_filters( 'camptix_coc_should_skip', false ) ) {
			return $questions;
		}

		$coc_url = $this->maybe_get_coc_url();
		$question = __( 'Do you agree to follow the event Code of Conduct?', 'wordcamporg' );
		if ( $coc_url ) {
			$question = sprintf(
				/* translators: %s placeholder is a URL */
				__( 'Do you agree to follow the event <a href="%s" target="_blank">Code of Conduct</a>?', 'wordcamporg' ),
				esc_url( $coc_url )
			);
		}

		$questions[ self::SLUG ] = (object) array(
			// Immitate a WP_Post with metadata..
			'ID' 	       => self::SLUG,
			'post_title'   => apply_filters( 'camptix_coc_question_text', $question, $ticket_id ),
			'tix_type'     => 'checkbox',
			'tix_required' => true,
			'tix_values'   => [
				'yes' => _x( 'Yes', 'ticket registration option', 'wordcamporg' ),
			],
		);

		return $questions;
	}

	/**
	 * Add the new field to the questions order.
	 *
	 * @param array $order
	 *
	 * @return array
	 */
	function add_question_order( $order ) {
		$order[] = self::SLUG;

		return $order;
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
	 * Update the stored value of the new field if it was changed in the Edit Info form.
	 *
	 * @param array   $ticket_info
	 * @param WP_Post $attendee
	 * @param array   $answers
	 *
	 * @return bool|int
	 */
	public function edit_attendee_data( $ticket_info, $attendee, $answers ) {
		return $this->save_registration_field( $attendee->ID, (object) compact( 'answers' ) );
	}

	/**
	 * Retrieve the stored value of the new field for use when displaying the attendee info.
	 *
	 * Back-compat only, for where the field was stored outside of the question answers.
	 *
	 * @param array   $ticket_info
	 * @param WP_Post $attendee
	 *
	 * @return array
	 */
	public function populate_attendee_answer( $ticket_info, $attendee ) {
		$attendee = get_post( $attendee );
		$value    = get_post_meta( $attendee->ID, 'tix_' . self::SLUG, true );

		$ticket_info[ self::SLUG ] ??= $this->options[ $value ] ?? '';

		return $ticket_info;
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
