<?php

namespace CampTix\Badge_Generator\InDesign;
use Exception;
use CampTix_Plugin;
use CampTix\Badge_Generator;
use CampTix\Badge_Generator\HTML;
use WordCamp\Logger;
use WordPressdotorg\MU_Plugins\Utilities;

defined( 'WPINC' ) || die();

add_action( 'camptix_menu_tools_indesign_badges', __NAMESPACE__ . '\render_indesign_page' );

/**
 * Render the Indesign Badges page
 */
function render_indesign_page() {
	if ( ! current_user_can( Badge_Generator\REQUIRED_CAPABILITY ) ) {
		return;
	}

	$html_customizer_url = HTML\get_customizer_section_url();

	require_once( dirname( __DIR__ ) . '/views/indesign-badges/page-indesign-badges.php' );
}

/**
 * Build the badge assets for InDesign
 *
 * @param array $options
 *
 * @throws Exception
 */
function build_assets( $options ) {
	$options = shortcode_atts(
		array(
			'ticket_ids'       => 'all',
			'registered_after' => '',
			'admin_flag'       => '',
		),
		$options
	);

	try {
		// Security: assets are intentionally saved to a folder outside the web root. See serve_zip_file() for details.
		$assets_folder    = sprintf( '%scamptix-badges-%d-%d', get_temp_dir(), get_current_blog_id(), time() );
		$gravatar_folder  = $assets_folder . '/gravatars';
		$csv_filename     = $assets_folder . '/attendees.csv';
		$zip_filename     = get_zip_filename( $assets_folder );
		$zip_local_folder = pathinfo( $zip_filename, PATHINFO_FILENAME );
		$attendees        = Badge_Generator\get_attendees( $options['ticket_ids'], $options['registered_after'], $options['admin_flag'] );

		wp_mkdir_p( $gravatar_folder );
		download_gravatars( $attendees, $gravatar_folder );
		generate_csv( $csv_filename, $zip_local_folder, $attendees, $gravatar_folder );
		create_zip_file( $zip_filename, $zip_local_folder, $csv_filename, $gravatar_folder );
	} finally {
		// todo Delete contents of $assets_folder, then rmdir( $assets_folder );.
	}
}

/**
 * Download each attendee's Gravatar
 *
 * @todo Remove set_time_limit() if end up running via a cron job
 *
 * @param array  $attendees
 * @param string $gravatar_folder
 *
 * @throws Exception
 */
function download_gravatars( $attendees, $gravatar_folder ) {
	set_time_limit( 0 );

	foreach ( $attendees as $attendee ) {
		if ( ! is_email( $attendee->tix_email ) ) {
			continue;
		}

		$request_url    = str_replace( '=blank', '=404', $attendee->avatar_url );
		$gravatar_image = download_single_gravatar( $request_url );

		if ( ! $gravatar_image ) {
			continue;
		}

		$filename      = get_gravatar_filename( $attendee );
		$gravatar_file = fopen( $gravatar_folder . '/' . $filename, 'w' );

		if ( ! $gravatar_file ) {
			Logger\log( 'gravatar_open_failed', compact( 'attendee', 'gravatar_folder', 'filename' ) );
			throw new Exception( __( "Couldn't save all Gravatars.", 'wordcamporg' ) );
		}

		fwrite( $gravatar_file, $gravatar_image );
		fclose( $gravatar_file );
	}
}

/**
 * Download a Gravatar
 *
 * Sometimes the HTTP request times out, or Varnish returns a `503` error, but the batch will be ruined if even a
 * single existing Gravatar cannot be downloaded successfully. In order to mitigate that, we retry the download
 * multiple times.
 *
 * @param string $request_url
 *
 * @return bool|string `false` when the user does not have a Gravatar, `string` of image binary data when the
 *                      image was successfully retrieved.
 *
 * @todo If have any problems with downloads failing permenantly, can try doing `str_replace()` on `$request_url`
 *       in order to change the `size` parameter to `512`.
 *
 * @todo Update this to use wcorg_redundant_remote_get() instead, for DRYness
 *
 * @throws Exception when the HTTP request fails
 */
function download_single_gravatar( $request_url ) {
	$attempt_count = 1;

	while ( true ) {
		$response    = wp_remote_get( $request_url );
		$status_code = wp_remote_retrieve_response_code( $response );
		$image       = wp_remote_retrieve_body( $response );
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' ) ?: 5;
		$retry_after = min( $retry_after * $attempt_count, 30 );

		// A 404 is expected when the attendee doesn't have a Gravatar setup, so don't retry them.
		if ( 404 == $status_code ) {
			return false;
		}

		if ( ! is_wp_error( $response ) && $image ) {
			return $image;
		}

		if ( is_array( $response ) && isset( $response['body'] ) ) {
			$response['body'] = '[redacted]'; // Avoid cluttering the logs with a ton of binary data.
		}

		if ( $attempt_count < 3 ) {
			Logger\log( 'request_failed_temporarily', compact( 'request_url', 'response', 'attempt_count', 'retry_after' ) );
			sleep( $retry_after );
		} else {
			Logger\log( 'request_failed_permenantly', compact( 'request_url', 'response' ) );
			throw new Exception( __( "Couldn't download all Gravatars.", 'wordcamporg' ) );
		}

		$attempt_count++;
	}
}

/**
 * Get the filename of the saved Gravatar for the given attendee
 *
 * @todo Returned value is false for input symbols like ♥, and maybe also for emoji
 *
 * @param \WP_Post $attendee
 *
 * @return string
 */
function get_gravatar_filename( $attendee ) {
	return sanitize_file_name( strtolower( sprintf(
		'%d-%s-%s.jpg',
		$attendee->ID,
		remove_accents( $attendee->tix_first_name ),
		remove_accents( $attendee->tix_last_name )
	) ) );
}

/**
 * Get the filename for the Zip file
 *
 * @param string $assets_folder
 *
 * @return string
 */
function get_zip_filename( $assets_folder ) {
	return $zip_filename = sprintf(
		'%s/%s-badges.zip',
		$assets_folder,
		sanitize_file_name( sanitize_title( get_wordcamp_name() ) )
	);
}

/**
 * Generate the CSV that InDesign will merge
 *
 * @todo Accept $destination_directory, $empty_twitter, and arbitrary tix_question fields from form input
 *
 * @todo Twitter username and Gravatar get prefixed with ' by wcorg_esc_csv. Spreadsheet programs will ignore
 * that, but InDesign might not. If it doesn't, need to do something else to prevent the user having to manually
 * remove them. Maybe just don't escape because the user isn't going to manually review the data for anything
 * malicious, so there's no point in relying on that.
 *
 * @param string $csv_filename
 * @param string $zip_local_folder
 * @param array  $attendees
 * @param string $gravatar_folder
 *
 * @throws Exception
 */
function generate_csv( $csv_filename, $zip_local_folder, $attendees, $gravatar_folder ) {
	/** @var CampTix_Plugin $camptix */
	global $camptix;

	$csv_handle            = fopen( $csv_filename, 'w' );
	$destination_directory = "Macintosh HD:Users:your_username:Desktop:$zip_local_folder:gravatars:";
	$empty_twitter         = '';
	$admin_flags           = get_admin_flags();
	$questions             = $camptix->get_all_questions();

	if ( ! $csv_handle ) {
		Logger\log( 'open_csv_failed' );
		throw new Exception( __( "Couldn't open CSV file.", 'wordcamporg' ) );
	}

	fputcsv( $csv_handle, Utilities\Export_CSV::esc_csv( get_header_row( $admin_flags, $questions ) ) );

	foreach ( $attendees as $attendee ) {
		$row = get_attendee_csv_row( $attendee, $gravatar_folder, $destination_directory, $empty_twitter, $admin_flags, $questions );

		if ( empty( $row ) ) {
			continue;
		}

		fputcsv( $csv_handle, Utilities\Export_CSV::esc_csv( $row ) );
	}

	fclose( $csv_handle );
}

/**
 * Get the admin flags
 *
 * @return array
 */
function get_admin_flags() {
	/** @var CampTix_Plugin $camptix */
	global $camptix;

	$flags           = array();
	$camptix_options = $camptix->get_options();

	if ( ! empty( $camptix_options['camptix-admin-flags-data-parsed'] ) ) {
		$flags = $camptix_options['camptix-admin-flags-data-parsed'];
	}

	return $flags;
}

/**
 * Get the header row for the CSV
 *
 * @param array $admin_flags
 * @param array $questions
 *
 * @return array
 */
function get_header_row( $admin_flags, $questions ) {
	$header_row   = array( 'First Name', 'Last Name', 'Email Address', 'Ticket', 'Coupon', 'Twitter' );
	$header_row   = array_merge( $header_row, array_values( $admin_flags ) );
	$header_row   = array_merge( $header_row, wp_list_pluck( $questions, 'post_title' ) );
	$header_row[] = '@Gravatar'; // Prefixed with an @ to let InDesign know that it contains an image. Last because InDesign complains if it's not.

	return $header_row;
}

/**
 * Get the CSV row for the given attendee
 *
 * @param \WP_Post $attendee
 * @param string   $gravatar_folder
 * @param string   $destination_directory
 * @param string   $empty_twitter
 * @param array    $admin_flags
 * @param array    $questions
 *
 * @return array
 */
function get_attendee_csv_row( $attendee, $gravatar_folder, $destination_directory, $empty_twitter, $admin_flags, $questions ) {
	$row = array();

	if ( 'unknown.attendee@example.org' === $attendee->tix_email ) {
		return $row;
	}

	$gravatar_path     = '';
	$first_name        = ucwords( $attendee->tix_first_name );
	$gravatar_filename = get_gravatar_filename( $attendee );
	$attendee_flags    = (array) get_post_meta( $attendee->ID, 'camptix-admin-flag' );
	$answers           = (array) $attendee->tix_questions;

	if ( file_exists( $gravatar_folder .'/'. $gravatar_filename ) ) {
		$gravatar_path = $destination_directory . $gravatar_filename;
	}

	$row = array(
		'first-name'       => $first_name,
		'last-name'        => ucwords( $attendee->tix_last_name ),
		'email-address'    => $attendee->tix_email,
		'ticket-name'      => $attendee->ticket,
		'coupon-name'      => $attendee->coupon,
		'twitter-username' => format_twitter_username( get_twitter_username( $attendee ), $first_name, $empty_twitter ),
	);

	foreach ( $admin_flags as $key => $label ) {
		$row[ $key ] = in_array( $key, $attendee_flags, true ) ? 'yes' : 'no';
	}

	foreach ( $questions as $question ) {
		$row[ "question-{$question->ID}" ] = get_answer( $question, $answers );
	}

	$row['gravatar-path'] = $gravatar_path;

	return $row;
}

/**
 * Get an attendee's Twitter username
 *
 * @todo For DRY-ness, make this a public static method in CampTix_Addon_Twitter_Field and refactor
 * attendees_shortcode_item() to use it.
 *
 * @param \WP_Post $attendee
 *
 * @return string
 */
function get_twitter_username( $attendee ) {
	/** @global CampTix_Plugin $camptix */
	global $camptix;

	$username = '';

	foreach ( $camptix->get_all_questions() as $question ) {
		if ( 'twitter' !== $question->tix_type ) {
			continue;
		}

		if ( ! isset( $attendee->tix_questions[ $question->ID ] ) ) {
			continue;
		}

		$username = trim( $attendee->tix_questions[ $question->ID ] );
		break;
	}

	return $username;
}

/**
 * Format a Twitter username
 *
 * @param string $username
 * @param string $first_name
 * @param string $empty_mode 'replace' to replace empty usernames with first names.
 *
 * @return string
 */
function format_twitter_username( $username, $first_name, $empty_mode = '' ) {
	if ( empty( $username ) ) {
		if ( 'replace' === $empty_mode ) {
			$username = $first_name;
		}
	} else {
		// Strip out everything but the username, and prefix a `@` character.
		$username = '@' . preg_replace(
			'/
				(https?:\/\/)?
				(twitter\.com\/)?
				(@)?
			/ix',
			'',
			$username
		);
	}

	return $username;
}

/**
 * Get an attendee's answer to a question
 *
 * @param \WP_Post $question
 * @param array    $answers
 *
 * @return string
 */
function get_answer( $question, $answers ) {
	if ( ! isset( $answers[ $question->ID ] ) ) {
		return '';
	}

	$answer = $answers[ $question->ID ];

	if ( is_array( $answer ) ) {
		$answer = implode( ', ', $answer );
	}

	return $answer;
}

/**
 * Create a Zip file with all of the assets
 *
 * @param string $zip_filename
 * @param string $zip_local_folder
 * @param string $csv_filename
 * @param string $gravatar_folder
 *
 * @throws Exception
 */
function create_zip_file( $zip_filename, $zip_local_folder, $csv_filename, $gravatar_folder ) {
	if ( ! class_exists( 'ZipArchive') ) {
		Logger\log( 'zip_ext_not_installed' );
		throw new Exception( __( 'The Zip extension for PHP is not installed.', 'wordcamporg' ) );
	}

	$zip_file    = new \ZipArchive();
	$open_status = $zip_file->open( $zip_filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

	if ( true !== $open_status ) {
		Logger\log( 'zip_open_failed', compact( 'zip_filename', 'open_status' ) );
		throw new Exception( __( 'The Zip file could not be created.', 'wordcamporg' ) );
	}

	$zip_file->addFile(
		$csv_filename,
		trailingslashit( $zip_local_folder ) . basename( $csv_filename )
	);

	$zip_file->addGlob(
		$gravatar_folder . '/*',
		0,
		array(
			'add_path'        => $zip_local_folder . '/gravatars/',
			'remove_all_path' => true,
		)
	);

	$zip_file->close();
}

/**
 * Serve the Zip file for downloading
 *
 * Security: This is intentionally served through PHP instead of making it accessible directly through wp-content,
 * because the CSV file contains email addresses that we don't want to risk exposing to anyone scraping public
 * folders.
 *
 * @todo If run into problems, maybe look into disabling gzip and/or adding support for range requests.
 * See http://www.media-division.com/the-right-way-to-handle-file-downloads-in-php/
 *
 * @param string $filename
 *
 * @throws Exception
 */
function serve_zip_file( $filename ) {
	if ( ! current_user_can( Badge_Generator\REQUIRED_CAPABILITY ) ) {
		Logger\log( 'access_denied' );
		throw new Exception( __( "You don't have authorization to perform this action.", 'wordcamporg' ) );
	}

	set_time_limit( 0 );

	$headers = array(
		'Content-Type'        => 'application/octet-stream',
		'Content-Length'      => filesize( $filename ),
		'Content-Disposition' => sprintf( 'attachment; filename="%s"', basename( $filename ) ),
	);

	foreach ( $headers as $header => $value ) {
		header( sprintf( '%s: %s', $header, $value ) );
	}

	ob_clean();
	flush();
	readfile( $filename );
	die();
}
