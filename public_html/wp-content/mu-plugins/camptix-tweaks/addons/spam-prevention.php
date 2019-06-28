<?php
namespace WordCamp\CampTix_Tweaks;

use CampTix_Plugin, CampTix_Addon;
use WordCamp\Utilities\Form_Spam_Prevention;

defined( 'WPINC' ) or die();

/**
 * Class Spam_Prevention
 *
 * This adds basic rate limiting to the CampTix attendee info form during the ticket purchase flow.
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Spam_Prevention extends CampTix_Addon {
	/**
	 * @var Form_Spam_Prevention $attendee_info
	 */
	protected $attendee_info;

	/**
	 * Hook into WordPress and CampTix.
	 */
	public function camptix_init() {
		$this->attendee_info = new Form_Spam_Prevention( [
			'prefix'            => 'camptix-fsp-attendee-info-',
			'individual_styles' => true,
		] );

		// Attendee info form, before checkout.
		add_action( 'camptix_form_attendee_after_registration_information', [ $this->attendee_info, 'render_form_fields' ] );
		add_action( 'camptix_checkout_start', [ $this, 'validate_form' ] );

		// General.
		add_action( 'camptix_form_attendee_info_errors', [ $this, 'validation_error' ] );
	}

	/**
	 * Check the form submission and add a CampTix error if it doesn't pass validation.
	 */
	public function validate_form() {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		switch ( current_action() ) {
			case 'camptix_checkout_start':
				$pass = $this->attendee_info->validate_form_submission();
				break;
		}

		if ( ! $pass ) {
			$camptix->error_flag( 'form_spam_prevention' );
		}
	}

	/**
	 * Add the error notice text for our custom error flag.
	 *
	 * @param array $error_flags
	 */
	public function validation_error( $error_flags ) {
		/* @var CampTix_Plugin $camptix */
		global $camptix;

		if ( isset( $error_flags[ 'form_spam_prevention' ] ) ) {
			$camptix->error( __( 'Your form submission could not be processed. Please try again.', 'wordcamporg' ) );
		}
	}
}

camptix_register_addon( __NAMESPACE__ . '\Spam_Prevention' );
