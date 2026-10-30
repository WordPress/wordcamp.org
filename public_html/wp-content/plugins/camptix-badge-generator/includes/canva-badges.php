<?php

namespace CampTix\Badge_Generator\Canva;
use Exception;
use CampTix\Badge_Generator;
use CampTix\Badge_Generator\HTML;
use CampTix\Badge_Generator\QR_Code;
use CampTix\Badge_Generator\XLSX_Writer;
use WordCamp\Logger;

defined( 'WPINC' ) || die();

const ACTION = 'cbg_canva_badges';

add_action( 'admin_post_' . ACTION, __NAMESPACE__ . '\download_spreadsheet' );

/**
 * Build the Canva spreadsheet and send it to the browser
 */
function download_spreadsheet() {
	if ( ! current_user_can( Badge_Generator\REQUIRED_CAPABILITY ) ) {
		wp_die( esc_html__( "You don't have authorization to perform this action.", 'wordcamporg' ), 403 );
	}

	check_admin_referer( ACTION );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
	$qr_code_type = HTML\sanitize_qr_code_type( sanitize_text_field( wp_unslash( $_POST['cbg_qr_code_type'] ?? 'none' ) ) );

	set_time_limit( 0 );

	// Security: files are saved outside the web root, because they include attendees' photos.
	$folder = sprintf( '%scamptix-canva-badges-%d-%s', get_temp_dir(), get_current_blog_id(), wp_generate_password( 12, false ) );

	try {
		wp_mkdir_p( $folder );

		$filename = build_spreadsheet( Badge_Generator\get_attendees( 'all' ), $qr_code_type, $folder );

		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Length: ' . filesize( $filename ) );
		header( sprintf( 'Content-Disposition: attachment; filename="%s"', basename( $filename ) ) );
		nocache_headers();

		if ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $filename );
	} catch ( Exception $exception ) {
		Logger\log( 'canva_spreadsheet_failed', array( 'error' => $exception->getMessage() ) );
		$error = $exception->getMessage();
	} finally {
		array_map( 'unlink', glob( "$folder/*" ) ?: array() );
		@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	if ( isset( $error ) ) {
		wp_die( esc_html( $error ) );
	}

	exit;
}

/**
 * Build the spreadsheet that Canva's Bulk Create imports
 *
 * @param array  $attendees
 * @param string $qr_code_type `none`, `gravatar`, or `wporg`.
 * @param string $folder       An empty folder for the images and spreadsheet.
 *
 * @throws Exception When the spreadsheet can't be written.
 *
 * @return string The spreadsheet's path.
 */
function build_spreadsheet( $attendees, $qr_code_type, $folder ) {
	Badge_Generator\prefetch_badge_data( $attendees );

	$emails = array();

	foreach ( $attendees as $attendee ) {
		$emails[ $attendee->ID ] = $attendee->tix_email;
	}

	$photos = Badge_Generator\download_gravatar_images( $emails );
	$writer = new XLSX_Writer();
	$header = get_header_row( $qr_code_type );

	$writer->add_row( $header, true );

	foreach ( array_keys( $header ) as $column ) {
		$writer->set_column_width( $column, 20 );
	}

	foreach ( $attendees as $attendee ) {
		$photo_path = '';
		$qr_path    = '';

		if ( isset( $photos[ $attendee->ID ] ) ) {
			$photo_path = "$folder/{$attendee->ID}-photo";
			file_put_contents( $photo_path, $photos[ $attendee->ID ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$target_url = Badge_Generator\get_qr_code_target_url( $attendee, $qr_code_type );
		$qr_png     = $target_url ? QR_Code::get_png( $target_url ) : '';

		if ( $qr_png ) {
			$qr_path = "$folder/{$attendee->ID}-qr.png";
			file_put_contents( $qr_path, $qr_png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$writer->add_row( get_attendee_row( $attendee, $photo_path, $qr_path, $qr_code_type ) );
	}

	$filename = sprintf( '%s/%s-canva-badges.xlsx', $folder, sanitize_file_name( sanitize_title( get_wordcamp_name() ) ) );

	$writer->save( $filename );

	return $filename;
}

/**
 * Get the spreadsheet's header row
 *
 * Canva names each Bulk Create data field after its column, so these are what organizers will see there.
 *
 * @param string $qr_code_type
 *
 * @return array
 */
function get_header_row( $qr_code_type ) {
	$header = array(
		__( 'First Name', 'wordcamporg' ),
		__( 'Last Name', 'wordcamporg' ),
		__( 'WordPress.org Username', 'wordcamporg' ),
		__( 'Hometown', 'wordcamporg' ),
		__( 'Website', 'wordcamporg' ),
		__( 'Company', 'wordcamporg' ),
		__( 'Ticket', 'wordcamporg' ),
		__( 'Photo', 'wordcamporg' ),
	);

	if ( 'none' !== $qr_code_type ) {
		$header[] = __( 'QR Code', 'wordcamporg' );
	}

	return $header;
}

/**
 * Get an attendee's row in the spreadsheet
 *
 * @param \WP_Post $attendee
 * @param string   $photo_path Their Gravatar image, if they have one.
 * @param string   $qr_path    Their QR code image, if they have one.
 * @param string   $qr_code_type
 *
 * @return array
 */
function get_attendee_row( $attendee, $photo_path, $qr_path, $qr_code_type ) {
	$ticket = get_post( $attendee->tix_ticket_id );

	$row = array(
		trim( (string) $attendee->tix_first_name ),
		trim( (string) $attendee->tix_last_name ),
		$attendee->wporg_username ? '@' . $attendee->wporg_username : '',
		$attendee->hometown,
		$attendee->website_label,
		$attendee->company,
		$ticket ? $ticket->post_title : '',
		$photo_path ? array( 'image' => $photo_path ) : '',
	);

	if ( 'none' !== $qr_code_type ) {
		$row[] = $qr_path ? array( 'image' => $qr_path ) : '';
	}

	return $row;
}
