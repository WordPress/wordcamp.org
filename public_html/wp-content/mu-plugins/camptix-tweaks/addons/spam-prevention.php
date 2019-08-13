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
			'score_threshold'   => 10,
			'throttle_duration' => 60 * 15, // 15 minutes.
			'prefix'            => 'camptix-fsp-attendee-info-',
			'individual_styles' => true,
		] );

		// Attendee info form, before checkout.
		add_action( 'camptix_form_attendee_after_registration_information', [ $this->attendee_info, 'render_form_fields' ] );
		add_action( 'camptix_checkout_start', [ $this, 'validate_form' ] );
		add_action( 'camptix_payment_result', [ $this, 'maybe_reset_throttle' ], 10, 2 );

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
	 * Check the result of the checkout form and reset the throttle score if successful.
	 *
	 * This allows for multiple successful ticket transactions from a single IP address without
	 * hitting the throttle threshold. This is necessary for situations such as people purchasing
	 * tickets at the registration table the day of the event.
	 *
	 * This assumes that a status of `completed` or `pending` is sufficient to consider a checkout
	 * to be successful.
	 *
	 * @param string $payment_token Unused.
	 * @param int    $result        The result code from the attempted transaction.
	 *
	 * @return void
	 */
	public function maybe_reset_throttle( $payment_token, $result ) {
		/** @var CampTix_Plugin $camptix */
		global $camptix;

		$valid_results = [
			$camptix::PAYMENT_STATUS_PENDING,
			$camptix::PAYMENT_STATUS_COMPLETED,
		];

		if ( in_array( $result, $valid_results, true ) ) {
			$this->attendee_info->reset_score_for_ip_address();
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
