<?php
namespace WordCamp\CampTix_Tweaks;
use CampTix_Addon;

defined( 'WPINC' ) || die();

/**
 * Abstraction class for extra fields.
 */
abstract class Extra_Fields extends CampTix_Addon {
	/**
	 * The slug for the field.
	 *
	 * This is used to store the value in post meta, and to identify the field in the summary table.
	 *
	 * @var string
	 */
	const SLUG = '';

	/**
	 * The slug for the field, used in filter names.
	 *
	 * If not specified, will default to the value of `static::SLUG`.
	 *
	 * @var string
	 */
	protected $filter_slug = '';

	/**
	 * The label for the column in the summary table.
	 *
	 * @var string
	 */
	public $column_label = '';

	/**
	 * The label for the field in the ticket form.
	 *
	 * Only required if the question contains complex HTML.
	 *
	 * @var string
	 */
	public $a11y_label = null;

	/**
	 * The question to ask.
	 *
	 * @var string
	 */
	public $question = '';

	/**
	 * The type of field to display.
	 *
	 * @var string
	 */
	public $type = 'radio';

	/**
	 * The options for the field, if limited choice.
	 *
	 * @var array
	 */
	public $options = array();

	/**
	 * Whether the field is required.
	 *
	 * @var bool
	 */
	public $required = true;

	/**
	 * The order in which the field should be displayed.
	 *
	 * Uses WordPress Hook priority system, lower number = higher priority.
	 *
	 * @var int
	 */
	public $question_order = 10;

	/**
	 * Whether to enable the field to be summarised by.
	 *
	 * @var bool
	 */
	public $enable_summary = true;

	/**
	 * Whether to enable the field to be exported & erased.
	 *
	 * @var bool
	 */
	public $enable_export_erase = true;

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

		// If not overriden, use the slug in the filters.
		$this->filter_slug ??= static::SLUG;

		// Ask the question.
		add_filter( 'camptix_ticket_questions', array( $this, 'add_question' ), 10, 2 );
		add_filter( 'camptix_ticket_questions_order', array( $this, 'add_question_order' ), $this->question_order );
		add_filter( 'camptix_get_attendee_answers', array( $this, 'populate_attendee_answer' ), 10, 2 );

		// Save the answer as post meta.
		add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_registration_field' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_update_post_meta', array( $this, 'edit_attendee_data' ), 10, 3 );

		// Reporting.
		if ( $this->enable_summary && $this->column_label ) {
			add_filter( 'camptix_summary_fields', array( $this, 'add_summary_field' ) );
			add_action( 'camptix_summarize_by_' . static::SLUG, array( $this, 'summarize' ), 10, 2 );
			add_filter( 'camptix_attendee_report_extra_columns', array( $this, 'add_export_column' ) );
			add_filter( 'camptix_attendee_report_column_value_' . static::SLUG, array( $this, 'add_export_column_value' ), 10, 2 );
		}

		// Privacy - Erase / Export features.
		if ( $this->enable_export_erase ) {
			add_filter( 'camptix_privacy_attendee_props_to_export', array( $this, 'attendee_props_to_export' ) );
			add_filter( 'camptix_privacy_export_attendee_prop', array( $this, 'export_attendee_prop' ), 10, 4 );
			add_filter( 'camptix_privacy_attendee_props_to_erase', array( $this, 'attendee_props_to_erase' ) );
			add_action( 'camptix_privacy_erase_attendee_prop', array( $this, 'erase_attendee_prop' ), 10, 3 );
		}
	}

	/**
	 * Add the question to the list of questions.
	 *
	 * @param array $questions
	 *
	 * @return array
	 */
	public function add_question( $questions, $ticket_id ) {
		if ( apply_filters( "camptix_{$this->filter_slug}_should_skip", false ) ) {
			return $questions;
		}

		$questions[ static::SLUG ] = (object) array(
			// Immitate a WP_Post with metadata..
			'ID'           => static::SLUG,
			'post_title'   => apply_filters( "camptix_{$this->filter_slug}_question_text", $this->question, $ticket_id ),
			'a11y_label'   => $this->a11y_label,
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
	public function add_question_order( $order ) {
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
		if ( ! isset( $attendee->answers[ static::SLUG ] ) ) {
			return false;
		}

		return $this->save_field( $post_id, $attendee->answers[ static::SLUG ] );
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
		return $this->save_field( $attendee->ID, $answers[ static::SLUG ] );
	}

	/**
	 * Save the value of the field to the attendee postmeta for back-compat.
	 *
	 * @param int    $post_id
	 * @param string $answer
	 */
	public function save_field( $post_id, $answer ) {
		$key = array_search( $answer, $this->options, true );

		// For back-compat, we store the option key rather than the option value.
		if ( $key ) {
			$answer = $key;
		}

		return update_post_meta( $post_id, 'tix_' . static::SLUG, $answer );
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

		$ticket_info[ static::SLUG ] ??= $this->options[ $value ] ?? $value;

		return $ticket_info;
	}

	/**
	 * Add an option to the `Summarize by` dropdown.
	 *
	 * @param array $fields
	 *
	 * @return array
	 */
	public function add_summary_field( $fields ) {
		$fields[ static::SLUG ] = $this->column_label;

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

		$answer = get_post_meta( $attendee->ID, 'tix_' . static::SLUG, true );

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
		$columns[ static::SLUG ] = $this->column_label;

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
		$value = get_post_meta( $attendee->ID, 'tix_' . static::SLUG, true );

		return $this->options[ $value ] ?? '';
	}

	/**
	 * Include the new field in the personal data exporter.
	 *
	 * @param array $props
	 *
	 * @return array
	 */
	public function attendee_props_to_export( $props ) {
		$props[ 'tix_' . static::SLUG ] = $this->question;

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
		if ( 'tix_' . static::SLUG === $key ) {
			$value = get_post_meta( $post->ID, 'tix_' . static::SLUG, true );

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
		$props[ 'tix_' . static::SLUG ] = 'camptix_yesno';

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
		if ( 'tix_' . static::SLUG === $key ) {
			$anonymized_value = wp_privacy_anonymize_data( $type );
			update_post_meta( $post->ID, $key, $anonymized_value );
		}
	}
}
