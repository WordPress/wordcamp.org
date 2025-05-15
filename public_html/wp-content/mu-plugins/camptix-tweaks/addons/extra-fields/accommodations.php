<?php
namespace WordCamp\CampTix_Tweaks;

use CampTix_Plugin;
use WP_Post;
use PHPMailer;

defined( 'WPINC' ) || die();

/**
 * Class Accommodations_Field.
 *
 * Add a non-optional attendee field indicating if they require special accommodations.
 *
 * Note that the user-facing wording has been changed to "accessibility needs" to avoid confusion for attendees and translators.
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Accommodations_Field extends Extra_Fields {
	const SLUG = 'accommodations';

	public $label          = '';
	public $question       = '';
	public $options        = array();
	public $question_order = 30;

	/**
	 * Hook into WordPress and Camptix.
	 */
	public function init() {
		$this->label    = __( 'Accessibility needs', 'wordcamporg' );
		$this->question = __( 'Do you have any accessibility needs, such as a sign language interpreter or wheelchair access, to participate in WordCamp?', 'wordcamporg' );
		$this->options  = array(
			'yes' => _x( 'Yes (we will contact you)', 'ticket registration option', 'wordcamporg' ),
			'no'  => _x( 'No', 'ticket registration option', 'wordcamporg' ),
		);

		// Notifications.
		add_action( 'camptix_ticket_emailed', array( $this, 'after_email_receipt' ) );

		// Reporting
		add_filter( 'camptix_summary_fields', array( $this, 'add_summary_field' ) );
		add_action( 'camptix_summarize_by_' . self::SLUG, array( $this, 'summarize' ), 10, 2 );
		add_filter( 'camptix_attendee_report_extra_columns', array( $this, 'add_export_column' ) );
		add_filter( 'camptix_attendee_report_column_value_' . self::SLUG, array( $this, 'add_export_column_value' ), 10, 2 );

		// Privacy
		add_filter( 'camptix_privacy_attendee_props_to_export', array( $this, 'attendee_props_to_export' ) );
		add_filter( 'camptix_privacy_export_attendee_prop', array( $this, 'export_attendee_prop' ), 10, 4 );
		add_filter( 'camptix_privacy_attendee_props_to_erase', array( $this, 'attendee_props_to_erase' ) );
		add_action( 'camptix_privacy_erase_attendee_prop', array( $this, 'erase_attendee_prop' ), 10, 3 );
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
		$handbook_link    = 'https://make.wordpress.org/community/handbook/wordcamp-organizer/first-steps/inclusive-and-welcoming-events/#requests-for-special-accommodations';
		$recipients       = array(
			$current_wordcamp->meta['Email Address'][0] ?? '', // Lead organizer
			$current_wordcamp->meta['E-mail Address'][0] ?? '', // City address
		);

		$recipients = array_filter( array_unique( $recipients ) );

		foreach ( $recipients as $recipient ) {
			$subject = sprintf(
				/* translators: Email subject line. The %s placeholder is the name of a WordCamp. */
				wp_strip_all_tags( __( 'An attendee who requires special accommodations has registered for %s', 'wordcamporg' ) ),
				$wordcamp_name
			);

			$message_line_1 = wp_strip_all_tags( __( 'The following attendee has indicated that they require special accommodations. Please note that this information is confidential.', 'wordcamporg' ) );

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
		 * Action: Fires when a notification is sent about a WordCamp attendee who requires special accommodations.
		 *
		 * @param array $details Contains information about the WordCamp and the attendee.
		 */
		do_action(
			'camptix_tweaks_accommodations_notification',
			array(
				'wordcamp' => $current_wordcamp,
				'attendee' => $attendee,
			)
		);

		update_post_meta( $attendee->ID, '_tix_notify_' . self::SLUG, true );
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
		$props[ 'tix_' . self::SLUG ] = 'camptix_yesno';

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

camptix_register_addon( __NAMESPACE__ . '\Accommodations_Field' );
