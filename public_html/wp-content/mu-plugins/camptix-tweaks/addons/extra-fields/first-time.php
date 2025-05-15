<?php
namespace WordCamp\CampTix_Tweaks;

use WP_Post;

defined( 'WPINC' ) || die();

/**
 * Add an required attendee field asking if they've attended a WordCamp before.
 */
class First_Time_Field extends Extra_Fields {
	const SLUG = 'first_time_attending_wp_event';

	protected $filter_slug = 'first_time';

	public $label    = '';
	public $question = '';
	public $options  = array();

	/**
	 * Hook into WordPress and CampTix.
	 */
	public function init() {
		$this->label    = __( 'First Time Attending', 'wordcamporg' );
		$this->question = __( 'Will this be your first time attending a WordPress event?', 'wordcamporg' );
		$this->options  = array(
			'yes' => _x( 'Yes', 'answer to question during ticket registration', 'wordcamporg' ),
			'no'  => _x( 'No', 'answer to question during ticket registration', 'wordcamporg' ),

			// Sometimes people buy tickets for others, and they may not know.
			'unsure'  => _x( "I don't know", 'answer to question during ticket registration', 'wordcamporg' ),
		);

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
