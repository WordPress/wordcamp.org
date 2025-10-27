<?php
namespace WordCamp\CampTix_Tweaks;

use WP_Post;
use PHPMailer;

defined( 'WPINC' ) || die();

/**
 * Class Allergy_Field.
 *
 * Add a non-optional attendee field indicating if they have a severe allergy.
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Allergy_Field extends Extra_Fields {
	const SLUG = 'allergy';

	public $question_order = 30;

	/**
	 * Setup the question & options.
	 */
	public function init() {
		$this->column_label = __( 'Severe allergy', 'wordcamporg' );
		$this->question     = __( 'Do you have a severe allergy that would affect your experience at WordCamp?', 'wordcamporg' );
		$this->options      = array(
			'yes' => _x( 'Yes (we will contact you)', 'ticket registration option', 'wordcamporg' ),
			'no'  => _x( 'No', 'ticket registration option', 'wordcamporg' ),
		);

		// Notifications - During registration.
		add_action( 'camptix_ticket_emailed', array( $this, 'after_email_receipt' ) );

		// Notifications - During edit.
		add_action( 'camptix_form_edit_attendee_update_post_meta', array( $this, 'after_attendee_edit' ), 20, 2 );
	}

	/**
	 * Initialize email notifications after the ticket receipt email has been sent.
	 *
	 * @param int $attendee_id
	 */
	public function after_email_receipt( $attendee_id ) {
		$attendee = get_post( $attendee_id );
		$value    = get_post_meta( $attendee_id, 'tix_' . self::SLUG, true );

		if ( $attendee instanceof WP_Post && 'tix_attendee' === $attendee->post_type ) {
			$this->maybe_send_notification_email( $value, $attendee );
		}
	}

	/**
	 * Detect if the attendee has later flagged an accessibility need.
	 */
	public function after_attendee_edit( $ticket_info, $attendee ) {
		$this->after_email_receipt( $attendee->ID );
	}

	/**
	 * Send a notification if it hasn't been sent already.
	 *
	 * @param string  $value
	 * @param WP_Post $attendee
	 */
	protected function maybe_send_notification_email( $value, $attendee ) {
		// Only send notifications for 'yes' answers.
		if ( 'yes' !== $value ) {
			return;
		}

		$already_sent = get_post_meta( $attendee->ID, '_tix_notify_' . self::SLUG, true );

		// Only send the notification once.
		if ( $already_sent ) {
			return;
		}

		global $phpmailer;
		if ( $phpmailer instanceof PHPMailer ) {
			// Clear out any lingering content from a previously sent message.
			$phpmailer = new PHPMailer( true ); // phpcs:disable WordPress.WP.GlobalVariablesOverride
		}

		$current_wordcamp = get_wordcamp_post();
		$wordcamp_name    = get_wordcamp_name();
		$post_type_object = get_post_type_object( $attendee->post_type );
		$attendee_link    = add_query_arg( 'action', 'edit', admin_url( sprintf( $post_type_object->_edit_link, $attendee->ID ) ) );
		$handbook_link    = 'https://make.wordpress.org/community/handbook/wordcamp-organizer/planning-details/selling-tickets/life-threatening-allergies/';
		$recipients       = array(
			$current_wordcamp->meta['Email Address'][0] ?? '', // Lead organizer.
			$current_wordcamp->meta['E-mail Address'][0] ?? '', // City address.
		);

		$recipients = array_unique( array_filter( $recipients ) );

		foreach ( $recipients as $recipient ) {
			$subject = sprintf(
				/* translators: Email subject line. The %s placeholder is the name of a WordCamp. */
				wp_strip_all_tags( __( 'An attendee who has a severe allergy has registered for %s', 'wordcamporg' ) ),
				$wordcamp_name
			);

			$message_line_1 = wp_strip_all_tags( __( 'The following attendee has indicated that they have a severe allergy. Please note that this information is confidential.', 'wordcamporg' ) );

			$message_line_2 = wp_strip_all_tags( __( 'Please follow the procedure outlined in the WordCamp Organizer Handbook to ensure the health and safety of this event\'s attendees.', 'wordcamporg' ) );

			$message = sprintf(
				"%s\n\n%s\n\n%s\n\n%s",
				$message_line_1,
				esc_url_raw( $attendee_link ), // Link to attendee post's Edit screen.
				$message_line_2,
				$handbook_link // Link to page in WordCamp Organizer Handbook.
			);

			wp_mail( $recipient, $subject, $message );
		}

		/**
		 * Action: Fires when a notification is sent about a WordCamp attendee with a severe allergy.
		 *
		 * @param array $details Contains information about the WordCamp and the attendee.
		 */
		do_action(
			'camptix_tweaks_allergy_notification',
			array(
				'wordcamp' => $current_wordcamp,
				'attendee' => $attendee,
			)
		);

		update_post_meta( $attendee->ID, '_tix_notify_' . self::SLUG, true );
	}
}

camptix_register_addon( __NAMESPACE__ . '\Allergy_Field' );
