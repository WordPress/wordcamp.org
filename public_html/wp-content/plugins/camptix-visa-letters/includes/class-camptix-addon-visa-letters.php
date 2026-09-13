<?php
/**
 * Addon class that extends Camptix with visa letter functionalities.
 *
 * @package    Camptix_Visa_Letters
 * @subpackage Camptix_Visa_Letters/includes
 */

/**
 * This class defines all code necessary to include visa letters into Camptix.
 *
 * @package    Camptix_Visa_Letters
 * @subpackage Camptix_Visa_Letters/includes
 */
class CampTix_Addon_Visa_Letters extends \CampTix_Addon {

	/**
	 * Init visa letter addon
	 */
	public function camptix_init() {
		global $camptix;
		global $camptix_visa_letter_custom_error;

		$camptix_visa_letter_custom_error = false;

		add_filter( 'camptix_setup_sections', array( __CLASS__, 'visa_letter_settings_tab' ) );
		add_action( 'camptix_menu_setup_controls', array( __CLASS__, 'visa_letter_settings' ) );
		add_filter( 'camptix_validate_options', array( __CLASS__, 'validate_options' ), 10, 2 );
		add_action( 'camptix_payment_result', array( __CLASS__, 'maybe_create_visa_letter' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_assets' ) );
		add_filter( 'camptix_checkout_attendee_info', array( __CLASS__, 'attendee_info' ) );
		add_action( 'camptix_notices', array( __CLASS__, 'error_flag' ), 0 );
		add_filter( 'camptix_form_register_complete_attendee_object', array( __CLASS__, 'attendee_object' ), 10, 2 );
		add_action( 'camptix_checkout_update_post_meta', array( __CLASS__, 'add_meta_visa_letter_on_attendee' ), 10, 2 );
		add_filter( 'camptix_metabox_attendee_info_additional_rows', array( __CLASS__, 'add_visa_letter_meta_on_attendee_metabox' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_after_questions', array( __CLASS__, 'edit_attendee_form' ) );
		add_action( 'camptix_form_edit_attendee_update_post_meta', array( __CLASS__, 'edit_attendee_save' ), 10, 2 );
	}

	/**
	 * Add a new tab in camptix settings.
	 *
	 * @param array $sections Sections of the Camptix settings.
	 */
	public static function visa_letter_settings_tab( $sections ) {
		$sections['visa-letter'] = __( 'Visa Letters', 'wordcamporg' );
		return $sections;
	}

	/**
	 * Tab content.
	 *
	 * @param string $section Section.
	 */
	public static function visa_letter_settings( $section ) {
		if ( 'visa-letter' !== $section ) {
			return false;
		}//end if

		$opt = get_option( 'camptix_options' );
		add_settings_section( 'visa-letter', __( 'Visa Letter settings', 'wordcamporg' ), '__return_false', 'camptix_options' );
		global $camptix;

		$camptix->add_settings_field_helper(
			'visa-letter-active',
			__( 'Activate visa letter requests', 'wordcamporg' ),
			'field_yesno',
			'visa-letter',
			__( 'Allow ticket buyers to request a visa invitation letter when purchasing their tickets.', 'wordcamporg' )
		);

		add_settings_field(
			'visa-letter-date-format',
			__( 'Date format', 'wordcamporg' ),
			array( __CLASS__, 'date_format_callback' ),
			'camptix_options',
			'visa-letter',
			array(
				'id'    => 'visa-letter-date-format',
				'value' => ! empty( $opt['visa-letter-date-format'] ) ? $opt['visa-letter-date-format'] : 'F j, Y',
			)
		);

		add_settings_field(
			'visa-letter-logo',
			__( 'Logo', 'wordcamporg' ),
			array( __CLASS__, 'type_file_callback' ),
			'camptix_options',
			'visa-letter',
			array(
				'id'    => 'visa-letter-logo',
				'value' => ! empty( $opt['visa-letter-logo'] ) ? $opt['visa-letter-logo'] : '',
			)
		);

		$camptix->add_settings_field_helper( 'visa-letter-organizer-name', __( 'Organizer name', 'wordcamporg' ), 'field_text', 'visa-letter' );
		$camptix->add_settings_field_helper( 'visa-letter-organizer-title', __( 'Organizer title', 'wordcamporg' ), 'field_text', 'visa-letter' );
		$camptix->add_settings_field_helper( 'visa-letter-organizer-email', __( 'Organizer email', 'wordcamporg' ), 'field_text', 'visa-letter' );
		$camptix->add_settings_field_helper( 'visa-letter-organizer-phone', __( 'Organizer phone', 'wordcamporg' ), 'field_text', 'visa-letter' );

		add_settings_field(
			'visa-letter-signature',
			__( 'Signature image', 'wordcamporg' ),
			array( __CLASS__, 'type_file_callback' ),
			'camptix_options',
			'visa-letter',
			array(
				'id'    => 'visa-letter-signature',
				'value' => ! empty( $opt['visa-letter-signature'] ) ? $opt['visa-letter-signature'] : '',
			)
		);

		$camptix->add_settings_field_helper( 'visa-letter-event-venue', __( 'Event venue (name and full address)', 'wordcamporg' ), 'field_textarea', 'visa-letter' );
		$camptix->add_settings_field_helper( 'visa-letter-custom-note', __( 'Custom note (optional paragraph in letter body)', 'wordcamporg' ), 'field_textarea', 'visa-letter' );

		$camptix->add_settings_field_helper(
			'visa-letter-retention',
			__( 'Auto-erase personal data after the event', 'wordcamporg' ),
			'field_yesno',
			'visa-letter',
			__( 'Once the event has been over for the number of days below, automatically erase passport data, delete letter PDFs, and anonymize letters. Letter numbers are kept for record-keeping.', 'wordcamporg' )
		);
		$camptix->add_settings_field_helper(
			'visa-letter-retention-days',
			__( 'Days after event end before erasing', 'wordcamporg' ),
			'field_text',
			'visa-letter'
		);

		$camptix->add_settings_field_helper(
			'visa-letter-canadian',
			__( 'Canadian compliance mode', 'wordcamporg' ),
			'field_yesno',
			'visa-letter',
			__( 'Follow Canadian federal (IRCC) guidelines: adds a registration confirmation number to the letter and collects entry/exit dates (required) and accommodation details (optional) from the attendee.', 'wordcamporg' )
		);
	}

	/**
	 * Date format setting callback.
	 *
	 * @param array $args Arguments.
	 */
	public static function date_format_callback( $args ) {

		$id          = $args['id'];
		$value       = $args['value'];
		$description = sprintf(
			// translators: %s is a date.
			__( 'Date format to use on the visa letter, as a PHP Date formatting string (default \'F j, Y\' formats dates as %s)', 'wordcamporg' ),
			date_i18n( 'F j, Y' )
		);

		include CTX_VL_DIR . '/includes/views/date-format-field.php';
	}

	/**
	 * Input type file.
	 *
	 * @param object $args Arguments.
	 */
	public static function type_file_callback( $args ) {
		wp_enqueue_media();
		wp_enqueue_script( 'admin-camptix-visa-letters' );
		wp_localize_script(
			'admin-camptix-visa-letters',
			'camptixVisaLetterBackVars',
			array(
				'selectText'  => __( 'Pick a logo to upload', 'wordcamporg' ),
				'selectImage' => __( 'Pick this logo', 'wordcamporg' ),
			)
		);

		$id    = $args['id'];
		$value = $args['value'];

		include CTX_VL_DIR . '/includes/views/logo-field.php';
	}

	/**
	 * Validate our custom options.
	 *
	 * @param object $output Output options.
	 * @param object $input  Input options.
	 */
	public static function validate_options( $output, $input ) {
		if ( isset( $input['visa-letter-active'] ) ) {
			$output['visa-letter-active'] = (int) $input['visa-letter-active'];
		}//end if
		if ( isset( $input['visa-letter-date-format'] ) ) {
			$output['visa-letter-date-format'] = $input['visa-letter-date-format'];
		}//end if
		if ( isset( $input['visa-letter-logo'] ) ) {
			$output['visa-letter-logo'] = (int) $input['visa-letter-logo'];
		}//end if
		if ( isset( $input['visa-letter-signature'] ) ) {
			$output['visa-letter-signature'] = (int) $input['visa-letter-signature'];
		}//end if
		if ( isset( $input['visa-letter-organizer-name'] ) ) {
			$output['visa-letter-organizer-name'] = sanitize_text_field( $input['visa-letter-organizer-name'] );
		}//end if
		if ( isset( $input['visa-letter-organizer-title'] ) ) {
			$output['visa-letter-organizer-title'] = sanitize_text_field( $input['visa-letter-organizer-title'] );
		}//end if
		if ( isset( $input['visa-letter-organizer-email'] ) ) {
			$output['visa-letter-organizer-email'] = sanitize_email( $input['visa-letter-organizer-email'] );
		}//end if
		if ( isset( $input['visa-letter-organizer-phone'] ) ) {
			$output['visa-letter-organizer-phone'] = sanitize_text_field( $input['visa-letter-organizer-phone'] );
		}//end if
		if ( isset( $input['visa-letter-event-venue'] ) ) {
			$output['visa-letter-event-venue'] = sanitize_textarea_field( $input['visa-letter-event-venue'] );
		}//end if
		if ( isset( $input['visa-letter-custom-note'] ) ) {
			$output['visa-letter-custom-note'] = sanitize_textarea_field( $input['visa-letter-custom-note'] );
		}//end if
		if ( isset( $input['visa-letter-retention'] ) ) {
			$output['visa-letter-retention'] = (int) $input['visa-letter-retention'];
		}//end if
		if ( isset( $input['visa-letter-retention-days'] ) ) {
			$output['visa-letter-retention-days'] = absint( $input['visa-letter-retention-days'] );
		}//end if
		if ( isset( $input['visa-letter-canadian'] ) ) {
			$output['visa-letter-canadian'] = (int) $input['visa-letter-canadian'];
		}//end if
		return $output;
	}

	/**
	 * Listen payment result to create visa letter.
	 *
	 * @param string $payment_token The payment token.
	 * @param int    $result        The result.
	 */
	public static function maybe_create_visa_letter( $payment_token, $result ) {
		if ( 2 !== $result ) {
			return;
		}//end if

		$attendees = get_posts(
			array(
				'posts_per_page' => -1,
				'post_type'      => 'tix_attendee',
				'post_status'    => 'any',
				'meta_query'     => array( // @codingStandardsIgnoreLine
					array(
						'key'     => 'tix_payment_token',
						'compare' => ' = ',
						'value'   => $payment_token,
						'type'    => 'CHAR',
					),
				),
			)
		);
		if ( ! $attendees ) {
			return;
		}//end if

		// Payment gateways can report a completed result more than once
		// (return + webhook, refresh); never issue a second letter.
		$existing_letter = get_post_meta( $attendees[0]->ID, 'tix_visa_letter_id', true );
		if ( $existing_letter && get_post( $existing_letter ) ) {
			return;
		}//end if

		$metas = get_post_meta( $attendees[0]->ID, 'visa_letter_metas', true );
		if ( $metas ) {
			$letter_id = self::create_visa_letter( $attendees[0], $metas );
			if ( ! is_wp_error( $letter_id ) && ! empty( $letter_id ) ) {
				self::send_visa_letter( $letter_id );
			}//end if
		}//end if
	}

	/**
	 * Get, increment and return visa letter number.
	 */
	public static function create_letter_number() {
		$current = get_option( 'visa_letter_current_number', 1 );
		$year    = wp_date( 'Y' );

		/**
		 * Sets the current visa letter number.
		 *
		 * @param int $current current visa letter number.
		 */
		$current = apply_filters( 'tix_visa_letter_current_number', $current );
		update_option( 'visa_letter_current_number', $current + 1 );

		return sprintf( '%s-VL-%s-%s', get_current_blog_id(), $year, $current );
	}

	/**
	 * Create visa letter.
	 *
	 * @param object $attendee The attendee.
	 * @param array  $metas    The metas.
	 */
	public static function create_visa_letter( $attendee, $metas ) {

		$letter = array(
			'post_type'   => 'tix_visa_letter',
			'post_status' => 'draft',
		);

		$letter_id = wp_insert_post( $letter );
		if ( ! $letter_id || is_wp_error( $letter_id ) ) {
			return;
		}//end if

		$number         = get_post_meta( $letter_id, 'visa_letter_number', true );
		$attendee_email = get_post_meta( $attendee->ID, 'tix_email', true );
		$txn_id         = get_post_meta( $attendee->ID, 'tix_transaction_id', true );

		$first_name = ! empty( $metas['first_name'] ) ? $metas['first_name'] : '';
		$last_name  = ! empty( $metas['last_name'] ) ? $metas['last_name'] : '';

		$letter_title = sprintf(
			// translators: 1: letter number, 2: first name, 3: last name, 4: email, 5: date.
			__( 'Visa Letter #%1$s for %2$s %3$s (%4$s) on %5$s', 'wordcamporg' ),
			$number,
			$first_name,
			$last_name,
			$attendee_email,
			get_the_time( 'd/m/Y', $attendee )
		);

		// Store transaction ID in metas for reference.
		$metas['transaction_id'] = $txn_id;

		update_post_meta( $letter_id, 'visa_letter_metas', ctx_vl_seal_metas( $metas ) );
		update_post_meta( $letter_id, 'attendee_id', $attendee->ID );
		update_post_meta( $attendee->ID, 'tix_visa_letter_id', $letter_id );

		wp_update_post(
			array(
				'ID'          => $letter_id,
				'post_status' => 'publish',
				'post_title'  => $letter_title,
				'post_name'   => sprintf( 'visa-letter-%s', $number ),
			)
		);

		return $letter_id;
	}

	/**
	 * Send visa letter by mail.
	 *
	 * @param int $letter_id The visa letter ID.
	 * @return bool Whether the letter was handed to wp_mail().
	 */
	public static function send_visa_letter( $letter_id ) {
		global $camptix;

		$letter_metas = get_post_meta( $letter_id, 'visa_letter_metas', true );
		if ( empty( $letter_metas['email'] ) || ! is_email( $letter_metas['email'] ) ) {
			return false;
		}//end if

		/*
		 * Delivery runs from the payment-completion path, so a missing PDF must not end
		 * the request. An email announcing an attached letter with nothing attached is
		 * also worse than no email: the letter record survives, and an organizer can
		 * regenerate the document and re-issue it.
		 */
		$letter_pdf = ctx_vl_locate_letter( $letter_id );
		if ( ! $letter_pdf ) {
			$camptix->log( __( 'Visa letter email not sent: the letter has no PDF document.', 'wordcamporg' ), $letter_id );

			return false;
		}

		$attachments = array( $letter_pdf );
		$opt         = get_option( 'camptix_options' );

		/* translators: The name of the event */
		$subject = apply_filters( 'camptix_visa_letter_mail_subject', sprintf( __( 'Your Visa Invitation Letter for %s', 'wordcamporg' ), $opt['event_name'] ), $opt['event_name'] );
		$from    = apply_filters( 'camptix_visa_letter_mail_from', get_option( 'admin_email' ) );
		$headers = apply_filters(
			'camptix_visa_letter_mail_headers',
			array(
				"From: {$opt['event_name']} <{$from}>",
				'Content-type: text/html; charset=UTF-8',
			)
		);

		$first_name = ! empty( $letter_metas['first_name'] ) ? $letter_metas['first_name'] : '';

		$message = array(
			// translators: attendee first name.
			sprintf( __( 'Dear %s,', 'wordcamporg' ), sanitize_text_field( $first_name ) ),
			'',
			// translators: event name.
			sprintf( __( 'As requested during your ticket purchase, please find attached your visa invitation letter for "%s".', 'wordcamporg' ), sanitize_text_field( $opt['event_name'] ) ),
			'',
			__( 'Please present this letter to the embassy or consulate as part of your visa application. If you require any changes to the letter, please contact the event organizers.', 'wordcamporg' ),
			'',
			// translators: email.
			sprintf( __( 'If you have any questions, please contact us at %s.', 'wordcamporg' ), $from ),
			'',
			__( 'Kind regards', 'wordcamporg' ),
			'',
			// translators: event name.
			sprintf( __( 'The %s team', 'wordcamporg' ), sanitize_text_field( $opt['event_name'] ) ),
		);
		$message = implode( PHP_EOL, $message );
		$message = '<p>' . nl2br( $message ) . '</p>';

		return wp_mail( $letter_metas['email'], $subject, $message, $headers, $attachments );
	}

	/**
	 * Render the letter HTML.
	 *
	 * @param int $letter_id The visa letter ID.
	 * @return string
	 */
	public static function render_letter_html( $letter_id ) {

		$camptix_opts  = get_option( 'camptix_options' );
		$letter_number = get_post_meta( $letter_id, 'visa_letter_number', true );
		$date_format   = ! empty( $camptix_opts['visa-letter-date-format'] ) ? $camptix_opts['visa-letter-date-format'] : 'F j, Y';
		$letter_date   = get_the_date( $date_format, $letter_id );
		$letter_metas  = ctx_vl_open_metas( get_post_meta( $letter_id, 'visa_letter_metas', true ) );

		$logo = CTX_VL_DIR . '/admin/images/wp-community-support.png';
		if ( ! empty( $camptix_opts['visa-letter-logo'] ) ) {
			$logo = get_attached_file( $camptix_opts['visa-letter-logo'] );
		}

		$template = locate_template( 'visa-letter-template.php' ) ? locate_template( 'visa-letter-template.php' ) : CTX_VL_DIR . '/includes/views/visa-letter-template.php';

		ob_start();
		include $template;
		$letter_content = ob_get_clean();

		return $letter_content;
	}

	/**
	 * Create a PDF document for the given visa letter.
	 *
	 * @param int $letter_id The visa letter ID.
	 * @return bool Whether a document was created and recorded.
	 */
	public static function create_letter_document( $letter_id ) {
		global $camptix;

		if ( ! ctx_vl_get_letters_dir() ) {
			return false;
		}

		/*
		 * `wordcamp-docs` can be inactive on a site, and this runs from `save_post` --
		 * which the payment-completion path triggers -- so failing here must not take the
		 * request down with it. The letter simply keeps no document, and can be
		 * regenerated once the dependency is available again.
		 */
		if ( ! class_exists( 'WordCamp_Docs_PDF_Generator' ) ) {
			$camptix->log( __( 'Visa letter PDF generation failed: the WordCamp Docs PDF generator is missing.', 'wordcamporg' ), $letter_id );

			return false;
		}

		$letter_number  = get_post_meta( $letter_id, 'visa_letter_number', true );
		$letter_content = self::render_letter_html( $letter_id );

		$filename = get_post_meta( $letter_id, 'visa_letter_document', true );
		if ( empty( $filename ) ) {
			$filename = $letter_number . '-' . wp_generate_password( 12, false, false ) . '.pdf';
		}

		$pdf_generator = new WordCamp_Docs_PDF_Generator();
		$tmp_path      = $pdf_generator->generate_pdf_from_string( $letter_content, $filename );

		return self::record_letter_document( $letter_id, $tmp_path, $filename );
	}

	/**
	 * Move a generated PDF into the letters directory and record it on the letter.
	 *
	 * Only records the document once the PDF verifiably exists:
	 * `WordCamp_Docs_PDF_Generator::generate_pdf_from_file()` returns its intended output
	 * path unconditionally, right after `exec()`, without checking that anything was
	 * written. Recording the filename regardless would leave the letter advertising a
	 * download that 404s -- the same defect as #1760.
	 *
	 * Split out from `create_letter_document()` so these branches are assertable without
	 * invoking wkhtmltopdf.
	 *
	 * @param int    $letter_id The visa letter ID.
	 * @param string $tmp_path  Path the generator reports having written the PDF to.
	 * @param string $filename  Filename to store the document under.
	 * @return bool Whether the document was recorded.
	 */
	public static function record_letter_document( $letter_id, $tmp_path, $filename ) {
		global $camptix;

		$letters_dirname = ctx_vl_get_letters_dir();
		if ( ! $letters_dirname ) {
			return false;
		}

		if ( ! $tmp_path || ! file_exists( $tmp_path ) || ! filesize( $tmp_path ) ) {
			$camptix->log(
				__( 'Visa letter PDF generation failed: wkhtmltopdf produced no output.', 'wordcamporg' ),
				$letter_id,
				array( 'filename' => $filename )
			);

			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- private directory outside WP_Filesystem's scope.
		if ( ! rename( $tmp_path, $letters_dirname . '/' . $filename ) ) {
			$camptix->log(
				__( 'Visa letter PDF generation failed: could not move the PDF into the letters directory.', 'wordcamporg' ),
				$letter_id,
				array( 'filename' => $filename )
			);

			return false;
		}

		update_post_meta( $letter_id, 'visa_letter_document', $filename );

		return true;
	}

	/**
	 * Check whether the visa letter has the required fields or not.
	 *
	 * @param int $letter_id The visa letter ID.
	 */
	public static function is_letter_incomplete( $letter_id ) {
		$letter_metas = get_post_meta( $letter_id, 'visa_letter_metas', true );

		if ( empty( $letter_metas['first_name'] ) ) {
			return true;
		}

		if ( empty( $letter_metas['last_name'] ) ) {
			return true;
		}

		if ( empty( $letter_metas['passport_country'] ) ) {
			return true;
		}

		if ( empty( $letter_metas['passport_number'] ) ) {
			return true;
		}

		if ( empty( $letter_metas['nationality'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Delete the visa letter document of a given visa letter.
	 *
	 * @param int $letter_id The visa letter ID.
	 */
	public static function delete_letter_document( $letter_id ) {
		$filename = get_post_meta( $letter_id, 'visa_letter_document', true );
		if ( empty( $filename ) ) {
			return;
		}

		delete_post_meta( $letter_id, 'visa_letter_document' );

		$letters_dirname = ctx_vl_get_letters_dir();
		if ( $letters_dirname ) {
			$path = $letters_dirname . '/' . basename( $filename );
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		// Also clean up copies generated before 1.1.0 that still sit in the
		// web-served uploads dir and were never migrated.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$legacy_path = $upload_dir['basedir'] . '/camptix-visa-letters/' . basename( $filename );
			if ( file_exists( $legacy_path ) ) {
				wp_delete_file( $legacy_path );
			}
		}
	}

	/**
	 * Enqueue assets
	 */
	public static function enqueue_assets() {

		$opt = get_option( 'camptix_options' );
		if ( ! empty( $opt['visa-letter-active'] ) ) {

			wp_register_script( 'camptix-visa-letters', CTX_VL_ADMIN_URL . '/js/camptix-visa-letters.js', array( 'jquery' ), CTX_VL_VER, true );
			wp_enqueue_script( 'camptix-visa-letters' );

		}//end if

		wp_register_style( 'camptix-visa-letters-css', CTX_VL_ADMIN_URL . '/css/camptix-visa-letters.css', array(), CTX_VL_VER );
		wp_enqueue_style( 'camptix-visa-letters-css' );
	}

	/**
	 * Register assets on admin side
	 */
	public static function admin_enqueue_assets() {
		wp_register_script( 'admin-camptix-visa-letters', CTX_VL_ADMIN_URL . '/js/camptix-visa-letters-back.js', array( 'jquery' ), CTX_VL_VER, true );
	}

	/**
	 * Attendee visa letter information
	 * (also check for missing visa letter infos).
	 *
	 * @param array $attendee_info The attendee info.
	 */
	public static function attendee_info( $attendee_info ) {

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		global $camptix;
		if ( empty( $_POST['camptix-need-visa-letter'] ) ) {
			return $attendee_info;
		}//end if

		if ( empty( $_POST['visa-letter-email'] )
			|| empty( $_POST['visa-letter-first-name'] )
			|| empty( $_POST['visa-letter-last-name'] )
			|| empty( $_POST['visa-letter-passport-country'] )
			|| empty( $_POST['visa-letter-passport-number'] )
			|| empty( $_POST['visa-letter-date-of-birth'] )
			|| empty( $_POST['visa-letter-nationality'] )
			|| empty( $_POST['visa-letter-mailing-address'] )
			|| ! is_email( wp_unslash( $_POST['visa-letter-email'] ) ) ) {

			$camptix->error_flag( 'visa_letter_nope' );

		} else {

			$attendee_info['visa-letter-email']            = sanitize_email( wp_unslash( $_POST['visa-letter-email'] ) );
			$attendee_info['visa-letter-first-name']       = sanitize_text_field( wp_unslash( $_POST['visa-letter-first-name'] ) );
			$attendee_info['visa-letter-last-name']        = sanitize_text_field( wp_unslash( $_POST['visa-letter-last-name'] ) );
			$attendee_info['visa-letter-passport-country'] = sanitize_text_field( wp_unslash( $_POST['visa-letter-passport-country'] ) );
			$attendee_info['visa-letter-passport-number']  = sanitize_text_field( wp_unslash( $_POST['visa-letter-passport-number'] ) );
			$attendee_info['visa-letter-date-of-birth']    = sanitize_text_field( wp_unslash( $_POST['visa-letter-date-of-birth'] ) );
			$attendee_info['visa-letter-nationality']      = sanitize_text_field( wp_unslash( $_POST['visa-letter-nationality'] ) );
			$attendee_info['visa-letter-mailing-address']  = sanitize_textarea_field( wp_unslash( $_POST['visa-letter-mailing-address'] ) );

		}//end if

		$canadian = self::validate_canadian_fields();
		if ( is_wp_error( $canadian ) ) {
			$camptix->error_flag( 'visa_letter_nope' );
		} elseif ( is_array( $canadian ) ) {
			$attendee_info = array_merge( $attendee_info, $canadian );
		}//end if

		// phpcs:enable
		return $attendee_info;
	}

	/**
	 * Validate and sanitize the Canadian-compliance fields from $_POST.
	 *
	 * @return array|WP_Error|false Sanitized visa-letter-* keys, WP_Error when
	 *                              required fields are missing/invalid, false
	 *                              when Canadian mode is off.
	 */
	public static function validate_canadian_fields() {
		$opt = get_option( 'camptix_options' );
		if ( empty( $opt['visa-letter-canadian'] ) ) {
			return false;
		}//end if

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- validated upstream by the calling flow.
		$entry = sanitize_text_field( wp_unslash( $_POST['visa-letter-entry-date'] ?? '' ) );
		$exit  = sanitize_text_field( wp_unslash( $_POST['visa-letter-exit-date'] ?? '' ) );

		$entry_ts = $entry ? strtotime( $entry ) : false;
		$exit_ts  = $exit ? strtotime( $exit ) : false;

		if ( ! $entry_ts || ! $exit_ts || $exit_ts < $entry_ts ) {
			return new WP_Error( 'visa_letter_canadian_dates', __( 'Please provide valid entry and exit dates for Canada.', 'wordcamporg' ) );
		}//end if

		$fields = array(
			'visa-letter-entry-date' => $entry,
			'visa-letter-exit-date'  => $exit,
		);

		if ( ! empty( $_POST['visa-letter-accommodation'] ) ) {
			$fields['visa-letter-accommodation'] = sanitize_textarea_field( wp_unslash( $_POST['visa-letter-accommodation'] ) );
		}//end if
		// phpcs:enable

		return $fields;
	}

	/**
	 * Define custom attributes for an attendee object.
	 *
	 * @param object $attendee      The attendee.
	 * @param array  $attendee_info The attendee info.
	 */
	public static function attendee_object( $attendee, $attendee_info ) {
		if ( ! empty( $attendee_info['visa-letter-email'] ) ) {
			$attendee->visa_letter = array(
				'email'            => $attendee_info['visa-letter-email'],
				'first_name'       => $attendee_info['visa-letter-first-name'],
				'last_name'        => $attendee_info['visa-letter-last-name'],
				'passport_country' => $attendee_info['visa-letter-passport-country'],
				'passport_number'  => $attendee_info['visa-letter-passport-number'],
				'date_of_birth'    => $attendee_info['visa-letter-date-of-birth'],
				'nationality'      => $attendee_info['visa-letter-nationality'],
				'mailing_address'  => $attendee_info['visa-letter-mailing-address'],
			);

			if ( ! empty( $attendee_info['visa-letter-entry-date'] ) ) {
				$attendee->visa_letter['entry_date'] = $attendee_info['visa-letter-entry-date'];
			}//end if
			if ( ! empty( $attendee_info['visa-letter-exit-date'] ) ) {
				$attendee->visa_letter['exit_date'] = $attendee_info['visa-letter-exit-date'];
			}//end if
			if ( ! empty( $attendee_info['visa-letter-accommodation'] ) ) {
				$attendee->visa_letter['accommodation'] = $attendee_info['visa-letter-accommodation'];
			}//end if
		}//end if
		return $attendee;
	}

	/**
	 * Add visa letter meta on an attendee post.
	 *
	 * @param int    $post_id  The post ID.
	 * @param object $attendee The attendee.
	 */
	public static function add_meta_visa_letter_on_attendee( $post_id, $attendee ) {

		if ( ! empty( $attendee->visa_letter ) ) {
			$sealed = ctx_vl_seal_metas( $attendee->visa_letter );
			update_post_meta( $post_id, 'visa_letter_metas', $sealed );
			global $camptix;
			$camptix->log( __( 'This attendee requested a visa letter.', 'wordcamporg' ), $post_id, $sealed );
		}//end if
	}

	/**
	 * Render the visa letter request section on the frontend attendee edit page.
	 *
	 * Runs on camptix_form_edit_attendee_after_questions, which only fires after
	 * CampTix core has validated the tix_edit_token for the requested attendee.
	 *
	 * @param array $ticket_info The ticket info (no attendee ID; read from the validated request).
	 */
	public static function edit_attendee_form( $ticket_info ) {
		$opt = get_option( 'camptix_options' );
		if ( empty( $opt['visa-letter-active'] ) ) {
			return;
		}//end if

		$attendee_id = isset( $_REQUEST['tix_attendee_id'] ) ? absint( $_REQUEST['tix_attendee_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token validated by CampTix core.
		if ( ! $attendee_id || 'tix_attendee' !== get_post_type( $attendee_id ) ) {
			return;
		}//end if

		echo '<tr><td colspan="2">';

		$letter_id = get_post_meta( $attendee_id, 'tix_visa_letter_id', true );
		if ( $letter_id && get_post( $letter_id ) ) {
			$letter_number = get_post_meta( $letter_id, 'visa_letter_number', true );
			include CTX_VL_DIR . '/includes/views/visa-letter-issued.php';
		} else {
			$visa_prefill = ctx_vl_open_metas( get_post_meta( $attendee_id, 'visa_letter_metas', true ) );
			include CTX_VL_DIR . '/includes/views/visa-letter-form.php';
		}//end if

		echo '</td></tr>';
	}

	/**
	 * Handle a visa letter request submitted from the attendee edit page.
	 *
	 * Runs on camptix_form_edit_attendee_update_post_meta, i.e. only after
	 * CampTix core validated the edit token and its own fields.
	 *
	 * @param array  $new_ticket_info The core ticket info being saved.
	 * @param object $attendee        The attendee post.
	 */
	public static function edit_attendee_save( $new_ticket_info, $attendee ) {
		$opt = get_option( 'camptix_options' );
		if ( empty( $opt['visa-letter-active'] ) ) {
			return;
		}//end if

		// An issued letter can only be changed by organizers.
		$letter_id = get_post_meta( $attendee->ID, 'tix_visa_letter_id', true );
		if ( $letter_id && get_post( $letter_id ) ) {
			return;
		}//end if

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- frontend flow authenticated by CampTix edit token.
		if ( empty( $_POST['camptix-need-visa-letter'] ) ) {
			return;
		}//end if

		global $camptix;

		if ( empty( $_POST['visa-letter-email'] )
			|| empty( $_POST['visa-letter-first-name'] )
			|| empty( $_POST['visa-letter-last-name'] )
			|| empty( $_POST['visa-letter-passport-country'] )
			|| empty( $_POST['visa-letter-passport-number'] )
			|| empty( $_POST['visa-letter-date-of-birth'] )
			|| empty( $_POST['visa-letter-nationality'] )
			|| empty( $_POST['visa-letter-mailing-address'] )
			|| ! is_email( wp_unslash( $_POST['visa-letter-email'] ) ) ) {

			$camptix->error( __( 'Your visa letter request was not saved. Please fill in all visa letter fields and try again.', 'wordcamporg' ) );
			return;
		}//end if

		$metas = array(
			'email'            => sanitize_email( wp_unslash( $_POST['visa-letter-email'] ) ),
			'first_name'       => sanitize_text_field( wp_unslash( $_POST['visa-letter-first-name'] ) ),
			'last_name'        => sanitize_text_field( wp_unslash( $_POST['visa-letter-last-name'] ) ),
			'passport_country' => sanitize_text_field( wp_unslash( $_POST['visa-letter-passport-country'] ) ),
			'passport_number'  => sanitize_text_field( wp_unslash( $_POST['visa-letter-passport-number'] ) ),
			'date_of_birth'    => sanitize_text_field( wp_unslash( $_POST['visa-letter-date-of-birth'] ) ),
			'nationality'      => sanitize_text_field( wp_unslash( $_POST['visa-letter-nationality'] ) ),
			'mailing_address'  => sanitize_textarea_field( wp_unslash( $_POST['visa-letter-mailing-address'] ) ),
		);
		// phpcs:enable

		$canadian = self::validate_canadian_fields();
		if ( is_wp_error( $canadian ) ) {
			$camptix->error( $canadian->get_error_message() );
			return;
		}//end if
		if ( is_array( $canadian ) ) {
			$metas['entry_date'] = $canadian['visa-letter-entry-date'];
			$metas['exit_date']  = $canadian['visa-letter-exit-date'];
			if ( isset( $canadian['visa-letter-accommodation'] ) ) {
				$metas['accommodation'] = $canadian['visa-letter-accommodation'];
			}//end if
		}//end if

		$sealed = ctx_vl_seal_metas( $metas );
		update_post_meta( $attendee->ID, 'visa_letter_metas', $sealed );
		$camptix->log( __( 'Attendee requested a visa letter from the ticket edit page.', 'wordcamporg' ), $attendee->ID, $sealed );

		if ( 'publish' !== $attendee->post_status ) {
			$camptix->info( __( 'Your visa invitation letter will be emailed to you once your payment is confirmed.', 'wordcamporg' ) );
			return;
		}//end if

		$letter_id = self::create_visa_letter( $attendee, $metas );
		if ( ! is_wp_error( $letter_id ) && ! empty( $letter_id ) ) {
			self::send_visa_letter( $letter_id );
			$camptix->info( __( 'Your visa invitation letter has been emailed to you.', 'wordcamporg' ) );
		}//end if
	}

	/**
	 * My custom errors flags.
	 */
	public static function error_flag() {

		global $camptix;
		if ( ! empty( $camptix->error_flags['visa_letter_nope'] ) ) {
			$camptix->error( __( 'As you have requested a visa letter, please fill in all required fields.', 'wordcamporg' ) );
		}//end if
	}

	/**
	 * Display visa letter meta on attendee admin page.
	 *
	 * @param array  $rows The rows.
	 * @param object $post The post.
	 */
	public static function add_visa_letter_meta_on_attendee_metabox( $rows, $post ) {
		$visa_letter_meta = get_post_meta( $post->ID, 'visa_letter_metas', true );
		if ( ! empty( $visa_letter_meta ) ) {
			$rows[] = array( __( 'Requested a visa letter', 'wordcamporg' ), __( 'Yes', 'wordcamporg' ) );
			$rows[] = array( __( 'Visa letter name', 'wordcamporg' ), $visa_letter_meta['first_name'] . ' ' . $visa_letter_meta['last_name'] );
			$rows[] = array( __( 'Visa letter email', 'wordcamporg' ), $visa_letter_meta['email'] );
			$rows[] = array( __( 'Passport country', 'wordcamporg' ), $visa_letter_meta['passport_country'] );
			$rows[] = array( __( 'Nationality', 'wordcamporg' ), $visa_letter_meta['nationality'] );
		} else {
			$rows[] = array( __( 'Requested a visa letter', 'wordcamporg' ), __( 'No', 'wordcamporg' ) );
		}//end if
		return $rows;
	}
}
