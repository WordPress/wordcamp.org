<?php
namespace WordCamp\CampTix_Tweaks;
use CampTix_Addon;

defined( 'WPINC' ) || die();

/**
 * Abstraction class for extra fields.
 */
abstract class Extra_Fields extends CampTix_Addon {
	const SLUG             = '';
	protected $filter_slug = '';

	public $label          = '';
	public $a11y_label     = null;
	public $question       = '';
	public $type           = 'radio';
	public $options        = array();
	public $required       = true;
	public $question_order = 10;

	/**
	 * Hook into WordPress and Camptix.
	 */
	public function camptix_init() {
		if ( ! static::SLUG ) {
			return;
		}

		if ( is_callable( array( $this, 'init' ) ) ) {
			$this->init();
		}

		if ( ! $this->question ) {
			return;
		}

		// If not overriden, use the slug as the filter.
		$this->filter_slug ??= static::SLUG;

		// Ask the question.
		add_filter( 'camptix_ticket_questions', array( $this, 'add_question' ), 10, 2 );
		add_filter( 'camptix_ticket_questions_order', array( $this, 'add_question_order' ), $this->question_order );
		add_filter( 'camptix_get_attendee_answers', array( $this, 'populate_attendee_answer' ), 10, 2 );

		// Save the answer as post meta.
		add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_registration_field' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_update_post_meta', array( $this, 'edit_attendee_data' ), 10, 3 );
	}

	/**
	 * Add the question to the list of questions.
	 *
	 * @param array $questions
	 *
	 * @return array
	 */
	function add_question( $questions, $ticket_id ) {
		if ( apply_filters( "camptix_{$this->filter_slug}_should_skip", false ) ) {
			return $questions;
		}

		$questions[ static::SLUG ] = (object) array(
			// Immitate a WP_Post with metadata..
			'ID' 	       => static::SLUG,
			'post_title'   => apply_filters( "camptix_{$this->filter_slug}_question_text", $this->question, $ticket_id ),
			'tix_type'     => $this->type,
			'tix_required' => $this->required,
			'tix_values'   => $this->options,
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
		$order[] = static::SLUG;

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
		return update_post_meta( $post_id, 'tix_' . static::SLUG, $attendee->{ static::SLUG } );
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
		$value    = get_post_meta( $attendee->ID, 'tix_' . static::SLUG, true );

		$ticket_info[ static::SLUG ] ??= $this->options[ $value ] ?? '';

		return $ticket_info;
	}


}