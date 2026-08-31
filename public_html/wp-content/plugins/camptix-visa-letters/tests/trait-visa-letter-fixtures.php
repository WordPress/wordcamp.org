<?php
/**
 * Shared fixtures for the CampTix Visa Letters suite.
 *
 * @package Camptix_Visa_Letters
 */

defined( 'WPINC' ) || die();

/**
 * Builds attendees, letters and stub PDFs, and captures the CampTix log.
 */
trait Visa_Letter_Fixtures {

	/**
	 * Entries captured from the CampTix log during a test.
	 *
	 * @var array
	 */
	protected $logged = array();

	/**
	 * Files created by a test, removed on teardown.
	 *
	 * @var array
	 */
	protected $temp_files = array();

	/**
	 * The camptix_options value to restore after a test.
	 *
	 * @var mixed
	 */
	protected $options_backup;

	/**
	 * Start capturing the CampTix log and remember the options.
	 */
	protected function set_up_visa_fixtures() {
		$this->logged         = array();
		$this->temp_files     = array();
		$this->options_backup = get_option( 'camptix_options' );

		add_action(
			'camptix_log_raw',
			function ( $message, $post_id = 0, $data = null, $module = 'general' ) {
				$this->logged[] = array(
					'message' => $message,
					'post_id' => $post_id,
					'data'    => $data,
					'module'  => $module,
				);
			},
			10,
			4
		);
	}

	/**
	 * Remove files a test created and restore the options.
	 */
	protected function tear_down_visa_fixtures() {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		$letters_dir = ctx_vl_get_letters_dir();
		if ( $letters_dir ) {
			foreach ( glob( $letters_dir . '/*.pdf' ) as $pdf ) {
				wp_delete_file( $pdf );
			}
		}

		update_option( 'camptix_options', $this->options_backup );
		$_POST = array();
	}

	/**
	 * Messages captured from the CampTix log.
	 *
	 * @return array
	 */
	protected function logged_messages() {
		return wp_list_pluck( $this->logged, 'message' );
	}

	/**
	 * Set visa-letter options on top of the current ones, with the feature active.
	 *
	 * @param array $overrides Option keys to set.
	 * @return array The stored options.
	 */
	protected function set_visa_options( $overrides = array() ) {
		update_option(
			'camptix_options',
			array_merge(
				(array) $this->options_backup,
				array( 'visa-letter-active' => 1 ),
				$overrides
			)
		);

		return get_option( 'camptix_options' );
	}

	/**
	 * The realistic personal details used across the suite.
	 *
	 * @param array $extra Extra meta keys to merge in.
	 * @return array
	 */
	protected function letter_details( $extra = array() ) {
		return array_merge(
			array(
				'email'            => 'eva@example.org',
				'first_name'       => 'Eva',
				'last_name'        => 'Horvat',
				'passport_country' => 'Croatia',
				'passport_number'  => 'AB1234567',
				'date_of_birth'    => '1990-04-17',
				'nationality'      => 'Croatian',
				'mailing_address'  => '1 Example Street, Zagreb, Croatia',
			),
			$extra
		);
	}

	/**
	 * The same details in the `$_POST`/checkout field shape.
	 *
	 * @param array $overrides Fields to override; a null value unsets the field.
	 * @return array
	 */
	protected function posted_fields( $overrides = array() ) {
		$fields = array_merge(
			array(
				'camptix-need-visa-letter'     => '1',
				'visa-letter-email'            => 'eva@example.org',
				'visa-letter-first-name'       => 'Eva',
				'visa-letter-last-name'        => 'Horvat',
				'visa-letter-passport-country' => 'Croatia',
				'visa-letter-passport-number'  => 'AB1234567',
				'visa-letter-date-of-birth'    => '1990-04-17',
				'visa-letter-nationality'      => 'Croatian',
				'visa-letter-mailing-address'  => '1 Example Street, Zagreb, Croatia',
			),
			$overrides
		);

		return array_filter( $fields, static fn( $value ) => null !== $value );
	}

	/**
	 * Create an attendee.
	 *
	 * @param string $slug   Unique-ish slug for the payment token and email.
	 * @param string $status Post status.
	 * @param array  $metas  Optional visa letter details to stage (sealed) on the attendee.
	 * @return int Attendee post ID.
	 */
	protected function make_attendee( $slug, $status = 'publish', $metas = null ) {
		$attendee_id = wp_insert_post(
			array(
				'post_type'   => 'tix_attendee',
				'post_status' => $status,
				'post_title'  => "Attendee $slug",
			),
			true
		);

		update_post_meta( $attendee_id, 'tix_email', "$slug@example.org" );
		update_post_meta( $attendee_id, 'tix_payment_token', "token-$slug" );
		update_post_meta( $attendee_id, 'tix_edit_token', md5( "token-$slug" ) );
		update_post_meta( $attendee_id, 'tix_transaction_id', "txn-$slug" );

		if ( null !== $metas ) {
			update_post_meta( $attendee_id, 'visa_letter_metas', ctx_vl_seal_metas( $metas ) );
		}

		return $attendee_id;
	}

	/**
	 * Letters linked to an attendee, oldest first.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return WP_Post[]
	 */
	protected function letters_for( $attendee_id ) {
		return get_posts(
			array(
				'post_type'      => 'tix_visa_letter',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => 'attendee_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $attendee_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Run an attendee through the real payment-completion path and return its letter.
	 *
	 * @param string $slug  Unique-ish slug.
	 * @param array  $extra Extra letter details to stage.
	 * @return array [ attendee_id, letter_id ]
	 */
	protected function make_paid_letter( $slug, $extra = array() ) {
		$this->set_visa_options();

		$attendee_id = $this->make_attendee( $slug, 'publish', $this->letter_details( $extra ) );

		do_action( 'camptix_payment_result', "token-$slug", 2, array() );

		$letters = $this->letters_for( $attendee_id );

		return array( $attendee_id, $letters ? $letters[0]->ID : 0 );
	}

	/**
	 * Attach a stub PDF to a letter through the real recording path.
	 *
	 * The wkhtmltopdf binary is not available in the test environment, and
	 * `wordcamp-docs` is deliberately not loaded, so a generated PDF is simulated with a
	 * real file handed to `record_letter_document()`. Everything downstream of
	 * generation -- storage location, download URL, deletion, erasure -- is then
	 * exercised for real.
	 *
	 * @param int $letter_id Letter post ID.
	 * @return string The recorded filename.
	 */
	protected function attach_stub_pdf( $letter_id ) {
		$filename = 'stub-' . $letter_id . '-' . wp_generate_password( 8, false, false ) . '.pdf';
		$tmp_path = $this->create_temp_file( '%PDF-1.4 stub' );

		$recorded = CampTix_Addon_Visa_Letters::record_letter_document( $letter_id, $tmp_path, $filename );
		$this->assertTrue( $recorded, 'The stub PDF should have been recorded.' );

		return $filename;
	}

	/**
	 * Create a file in the temp dir, tracked for teardown.
	 *
	 * @param string $contents Contents to write; an empty string produces a zero-byte file.
	 * @return string Full path.
	 */
	protected function create_temp_file( $contents ) {
		$path               = get_temp_dir() . 'ctx-vl-test-' . wp_generate_password( 8, false, false ) . '.pdf';
		$this->temp_files[] = $path;

		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $path;
	}

	/**
	 * Create the central WordCamp post the retention cutoff reads its dates from.
	 *
	 * Looks for a `wordcamp` post on the root blog whose `_site_id` matches this site,
	 * which is what `get_wordcamp_post()` resolves.
	 *
	 * @param string $start Start date, `Y-m-d`.
	 * @param string $end   End date, `Y-m-d`. Empty leaves the meta unset, as single-day
	 *                      camps do.
	 * @return int The wordcamp post ID.
	 */
	protected function make_wordcamp_post( $start, $end = '' ) {
		$site_id = get_current_blog_id();

		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$wordcamp_id = wp_insert_post(
			array(
				'post_type'   => 'wordcamp',
				'post_status' => 'publish',
				'post_title'  => 'Test WordCamp',
			),
			true
		);

		update_post_meta( $wordcamp_id, '_site_id', $site_id );
		update_post_meta( $wordcamp_id, 'Start Date (YYYY-mm-dd)', strtotime( $start ) );

		if ( $end ) {
			update_post_meta( $wordcamp_id, 'End Date (YYYY-mm-dd)', strtotime( $end ) );
		}

		restore_current_blog();

		return $wordcamp_id;
	}

	/**
	 * How many errors CampTix currently has queued.
	 *
	 * @return int
	 */
	protected function camptix_error_count() {
		global $camptix;

		$errors = new ReflectionProperty( 'CampTix_Plugin', 'errors' );
		$errors->setAccessible( true );

		return count( (array) $errors->getValue( $camptix ) );
	}
}
