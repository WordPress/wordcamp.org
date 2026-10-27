<?php
/**
 * Importing sponsors from a spreadsheet: reading the CSV, and turning each row into a vetting request.
 *
 * The Airtable importer reuses parse_csv() and the domain matching.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * The columns a sponsor spreadsheet has.
 *
 * @return string[]
 */
function get_import_columns(): array {
	return array( 'event_url', 'company_name', 'website', 'first_name', 'last_name', 'email' );
}

/**
 * Reads a CSV file into rows keyed by its header.
 *
 * Headers are matched without case or surrounding space, and a spreadsheet app's byte-order mark is ignored.
 *
 * @param string   $path     The file.
 * @param string[] $required Columns the header must have.
 * @return array[]|\WP_Error The rows, without blank ones.
 */
function parse_csv( string $path, array $required = array() ): array|\WP_Error {
	$handle = is_readable( $path ) ? fopen( $path, 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading an upload line by line.

	if ( ! $handle ) {
		return new \WP_Error( 'sv_unreadable', __( 'The file couldn’t be read.', 'wordcamporg' ) );
	}

	$header = fgetcsv( $handle, null, ',', '"', '' );
	$rows   = array();

	if ( is_array( $header ) ) {
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$header    = array_map( static fn( ?string $column ): string => strtolower( trim( (string) $column ) ), $header );

		$line = fgetcsv( $handle, null, ',', '"', '' );
		while ( false !== $line ) {
			$values = array_map( static fn( ?string $value ): string => trim( (string) $value ), $line );

			if ( array_filter( $values, 'strlen' ) ) {
				$values = array_slice( array_pad( $values, count( $header ), '' ), 0, count( $header ) );
				$rows[] = array_combine( $header, $values );
			}

			$line = fgetcsv( $handle, null, ',', '"', '' );
		}
	}

	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with fopen() above.

	$missing = array_diff( $required, is_array( $header ) ? $header : array() );

	if ( $missing ) {
		/* translators: %s: Column names. */
		return new \WP_Error( 'sv_missing_columns', sprintf( __( 'The file is missing these columns: %s.', 'wordcamporg' ), implode( ', ', $missing ) ) );
	}

	return $rows;
}

/**
 * Imports one spreadsheet row: finds the camp and its sponsor, adds the sponsor as a draft if the camp doesn't
 * have it, and requests vetting.
 *
 * Re-importing a row matches the sponsor it added the first time, by domain. Runs on Central; callers check
 * capabilities first.
 *
 * @param array $row     A row, keyed by get_import_columns().
 * @param bool  $dry_run Whether to only work out what would happen.
 * @return array {
 *     @type string $result         `existing`, `new`, or `error`.
 *     @type string $message        What happened, or would.
 *     @type int    $blog_id        The camp site.
 *     @type int    $sponsor_id     The camp's sponsor, if it exists.
 *     @type int    $sponsorship_id The sponsorship, unless this is a dry run.
 * }
 */
function import_row( array $row, bool $dry_run ): array {
	$result = array(
		'result'         => 'error',
		'message'        => '',
		'blog_id'        => get_blog_id_from_event_url( (string) ( $row['event_url'] ?? '' ) ),
		'sponsor_id'     => 0,
		'sponsorship_id' => 0,
	);

	$name   = sanitize_text_field( (string) ( $row['company_name'] ?? '' ) );
	$domain = normalize_domain( (string) ( $row['website'] ?? '' ) );

	if ( ! $result['blog_id'] ) {
		$result['message'] = __( 'No event site has that URL.', 'wordcamporg' );
		return $result;
	}

	if ( '' === $name || '' === $domain ) {
		$result['message'] = __( 'A company name and website are needed.', 'wordcamporg' );
		return $result;
	}

	$result['sponsor_id'] = find_camp_sponsor_by_domain( $result['blog_id'], $domain );
	$result['result']     = $result['sponsor_id'] ? 'existing' : 'new';
	$result['message']    = $result['sponsor_id']
		? __( 'Matches a sponsor on the event site.', 'wordcamporg' )
		: __( 'Adds the sponsor to the event site as a draft.', 'wordcamporg' );

	if ( $dry_run ) {
		return $result;
	}

	if ( ! $result['sponsor_id'] ) {
		switch_to_blog( $result['blog_id'] );

		$sponsor_id = wp_insert_post(
			array(
				'post_type'   => 'wcb_sponsor',
				'post_status' => 'draft',
				'post_title'  => $name,
				'meta_input'  => array(
					'_wcpt_sponsor_company_name'  => $name,
					'_wcpt_sponsor_website'       => esc_url_raw( 'https://' . $domain ),
					'_wcpt_sponsor_first_name'    => sanitize_text_field( (string) ( $row['first_name'] ?? '' ) ),
					'_wcpt_sponsor_last_name'     => sanitize_text_field( (string) ( $row['last_name'] ?? '' ) ),
					'_wcpt_sponsor_email_address' => sanitize_email( (string) ( $row['email'] ?? '' ) ),
				),
			),
			true
		);

		restore_current_blog();

		if ( is_wp_error( $sponsor_id ) ) {
			$result['result']  = 'error';
			$result['message'] = $sponsor_id->get_error_message();
			return $result;
		}

		$result['sponsor_id'] = $sponsor_id;
	}

	$sponsorship_id = request_vetting( $result['blog_id'], $result['sponsor_id'] );

	if ( is_wp_error( $sponsorship_id ) ) {
		$result['result']  = 'error';
		$result['message'] = $sponsorship_id->get_error_message();
		return $result;
	}

	$result['sponsorship_id'] = $sponsorship_id;

	return $result;
}

/**
 * Finds the site for an event's URL, like `https://seattle.wordcamp.org/2026/`.
 *
 * @param string $url The URL.
 * @return int The site's ID, or 0.
 */
function get_blog_id_from_event_url( string $url ): int {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );

	if ( '' === $host ) {
		return 0;
	}

	$path = trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	$site = get_site_by_path( strtolower( $host ), $path );

	return $site && ! in_array( (int) $site->blog_id, array( 0, WORDCAMP_ROOT_BLOG_ID ), true ) ? (int) $site->blog_id : 0;
}

/**
 * Finds a camp's sponsor by its website's domain.
 *
 * @param int    $blog_id The camp site.
 * @param string $domain  A normalized domain.
 * @return int The sponsor's ID, or 0.
 */
function find_camp_sponsor_by_domain( int $blog_id, string $domain ): int {
	switch_to_blog( $blog_id );

	// Websites are stored as typed, so they're normalized here rather than matched in the query.
	$sponsor_ids = get_posts(
		array(
			'post_type'      => 'wcb_sponsor',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	$match = 0;
	foreach ( $sponsor_ids as $sponsor_id ) {
		if ( normalize_domain( (string) get_post_meta( $sponsor_id, '_wcpt_sponsor_website', true ) ) === $domain ) {
			$match = (int) $sponsor_id;
			break;
		}
	}

	restore_current_blog();

	return $match;
}
