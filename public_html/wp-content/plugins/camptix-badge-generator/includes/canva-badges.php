<?php

namespace CampTix\Badge_Generator\Canva;
use DateTimeImmutable;
use Exception;
use CampTix\Badge_Generator;
use CampTix\Badge_Generator\HTML;
use CampTix\Badge_Generator\InDesign;
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

	$options = get_export_options( wp_unslash( $_POST ) );

	if ( empty( $options['ticket_ids'] ) ) {
		wp_die(
			esc_html__( 'Choose at least one ticket.', 'wordcamporg' ),
			'',
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}

	set_time_limit( 0 );

	// Security: files are saved outside the web root, because they include attendees' photos.
	$folder = sprintf( '%scamptix-canva-badges-%d-%s', get_temp_dir(), get_current_blog_id(), wp_generate_password( 12, false ) );

	try {
		wp_mkdir_p( $folder );

		$attendees = Badge_Generator\get_attendees( $options['ticket_ids'], $options['registered_after'], $options['admin_flag'] );
		$filename  = build_spreadsheet( $attendees, $options['qr_code_type'], $folder );

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
		wp_die( esc_html( $error ), '', array( 'back_link' => true ) );
	}

	exit;
}

/**
 * Get the export options from the submitted form
 *
 * The filters let organizers print most badges early, and then a second batch of late registrations, or only the
 * badges for speakers, sponsors, etc.
 *
 * @param array $request Unslashed form data.
 *
 * @return array `qr_code_type`; `ticket_ids` as the string `all` or an array of IDs, which is empty if none of
 *               them are valid; `registered_after` as an ISO 8601 date in the site's timezone, or an empty
 *               string; and `admin_flag`, which is an empty string for all attendees.
 */
function get_export_options( $request ) {
	$all_tickets = get_ticket_choices();
	$ticket_ids  = array_filter( (array) ( $request['cbg_ticket_ids'] ?? array() ), 'is_scalar' );
	$ticket_ids  = array_intersect( array_map( 'absint', $ticket_ids ), array_keys( $all_tickets ) );
	$admin_flag  = get_text_field( $request, 'cbg_admin_flag' );
	$after       = get_text_field( $request, 'cbg_registered_after' );

	try {
		// The date field has no timezone, and organizers mean their own.
		$after = '' === $after ? '' : ( new DateTimeImmutable( $after, wp_timezone() ) )->format( 'c' );
	} catch ( Exception $exception ) {
		$after = '';
	}

	return array(
		'qr_code_type'     => HTML\sanitize_qr_code_type( get_text_field( $request, 'cbg_qr_code_type', 'none' ) ),
		'ticket_ids'       => count( $ticket_ids ) === count( $all_tickets ) ? 'all' : array_values( $ticket_ids ),
		'registered_after' => $after,
		'admin_flag'       => array_key_exists( $admin_flag, InDesign\get_admin_flags() ) ? $admin_flag : '',
	);
}

/**
 * Get a text field from the submitted form
 *
 * @param array  $request Unslashed form data.
 * @param string $key
 * @param string $default Used when the field is missing, or isn't a single value.
 *
 * @return string
 */
function get_text_field( $request, $key, $default = '' ) {
	if ( ! isset( $request[ $key ] ) || ! is_scalar( $request[ $key ] ) ) {
		return $default;
	}

	return sanitize_text_field( (string) $request[ $key ] );
}

/**
 * Get the tickets that organizers can filter the export by
 *
 * @return array Ticket titles keyed by ID.
 */
function get_ticket_choices() {
	$tickets = get_posts( array(
		'post_type'   => 'tix_ticket',
		'post_status' => 'any',
		'numberposts' => -1,
		'orderby'     => 'menu_order',
		'order'       => 'ASC',
	) );

	return wp_list_pluck( $tickets, 'post_title', 'ID' );
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

	$photos = Badge_Generator\download_gravatar_images( $emails, $folder );
	$writer = new XLSX_Writer();
	$header = get_header_row( $qr_code_type );

	$writer->add_row( $header, true );

	foreach ( array_keys( $header ) as $column ) {
		$writer->set_column_width( $column, 20 );
	}

	foreach ( $attendees as $attendee ) {
		$photo_path = $photos[ $attendee->ID ] ?? '';
		$qr_path    = '';

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
 * They aren't translated, so a Canva template keeps working across sites in any language. The first two columns
 * and the Gravatar ones match https://github.com/nilovelez/camptix-to-canva, so templates built for that tool
 * work as-is.
 *
 * @param string $qr_code_type
 *
 * @return array
 */
function get_header_row( $qr_code_type ) {
	$header = array( 'First Name', 'Last Name', 'WordPress.org Username', 'Hometown', 'Website', 'Company', 'Ticket', 'Gravatar' );

	if ( 'none' !== $qr_code_type ) {
		$header[] = 'wporg' === $qr_code_type ? 'QR WordPress.org' : 'QR Gravatar';
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

	$text = array(
		trim( (string) $attendee->tix_first_name ),
		trim( (string) $attendee->tix_last_name ),
		$attendee->wporg_username ? '@' . $attendee->wporg_username : '',
		$attendee->hometown,
		$attendee->website_label,
		$attendee->company,
		$ticket ? $ticket->post_title : '',
	);

	/*
	 * Canva's Bulk Create drops a column that's empty in every row, which disconnects the template's text box, so
	 * it prints its placeholder text. That's likely in a small batch, e.g. late registrations who skipped a
	 * question. A space keeps the column, and prints as nothing.
	 */
	$row = array_map(
		function ( $value ) {
			return '' === trim( (string) $value ) ? ' ' : $value;
		},
		$text
	);

	$row[] = $photo_path ? array( 'image' => $photo_path ) : '';

	if ( 'none' !== $qr_code_type ) {
		$row[] = $qr_path ? array( 'image' => $qr_path ) : '';
	}

	return $row;
}
