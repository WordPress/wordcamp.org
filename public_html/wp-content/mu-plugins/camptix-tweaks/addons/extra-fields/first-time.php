<?php
namespace WordCamp\CampTix_Tweaks;

defined( 'WPINC' ) || die();

/**
 * Add an required attendee field asking if they've attended a WordCamp before.
 */
class First_Time_Field extends Extra_Fields {
	const SLUG = 'first_time_attending_wp_event';

	protected $filter_slug = 'first_time';
	public $question_order = 40;

	/**
	 * Setup the question & options.
	 */
	public function init() {
		$this->column_label = __( 'First Time Attending', 'wordcamporg' );
		$this->question     = __( 'Will this be your first time attending a WordPress event?', 'wordcamporg' );
		$this->options      = array(
			'yes' => _x( 'Yes', 'answer to question during ticket registration', 'wordcamporg' ),
			'no'  => _x( 'No', 'answer to question during ticket registration', 'wordcamporg' ),

			// Sometimes people buy tickets for others, and they may not know.
			'unsure'  => _x( "I don't know", 'answer to question during ticket registration', 'wordcamporg' ),
		);
	}
}

camptix_register_addon( __NAMESPACE__ . '\First_Time_Field' );
