<?php
namespace WordCamp\CampTix_Tweaks;
use CampTix_Addon;

defined( 'WPINC' ) || die();

/**
 * Abstraction class for extra fields.
 */
abstract class Extra_Field extends CampTix_Addon {
	const SLUG = '';

	public $label      = '';
	public $a11y_label = null;
	public $question   = '';
	public $options    = array();

	/**
	 * Hook into WordPress and Camptix.
	 */
	public function camptix_init() {
	}
}