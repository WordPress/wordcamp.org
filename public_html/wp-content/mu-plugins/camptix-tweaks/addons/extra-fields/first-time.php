<?php
namespace WordCamp\CampTix_Tweaks;

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

	public $question_order = 20;

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
}

camptix_register_addon( __NAMESPACE__ . '\First_Time_Field' );
