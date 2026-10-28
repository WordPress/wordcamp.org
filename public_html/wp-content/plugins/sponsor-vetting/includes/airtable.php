<?php
/**
 * A one-time migration from the Airtable vetting base, to be deleted once it has run at switchover.
 *
 * Reads the base's CSV export: one row per company and its current events. Each domain becomes a company with its
 * status, findings and dates; each event that matches a camp with that company as a sponsor gets a sponsorship,
 * approved or canceled as the row says. Columns with no field of their own go into a note on the company.
 *
 * Re-running it leaves the companies it already imported, matched by domain, as they are, and adds the sponsorships
 * of events that match since. It sends no email.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * The company status each Airtable status becomes.
 *
 * @return string[] Company statuses, keyed by Airtable status in lower case.
 */
function get_airtable_statuses(): array {
	return array(
		''                          => 'sv-needs-vetting',
		'needs vetting'             => 'sv-needs-vetting',
		'canceled'                  => 'sv-needs-vetting',
		'needs compliance'          => 'sv-in-progress',
		'needs follow up'           => 'sv-in-progress',
		'double checking'           => 'sv-in-progress',
		'compliance requested'      => 'sv-compliance-req',
		'compliance in progress'    => 'sv-compliance-req',
		'good faith'                => 'sv-compliance-req',
		'compliant'                 => 'sv-compliant',
		'ready to move to wpf'      => 'sv-compliant',
		'not eligible (6-12months)' => 'sv-not-eligible',
	);
}

/**
 * The columns stored in a field of their own; every other column goes into the company's note.
 *
 * @return string[] Column names in lower case.
 */
function get_airtable_field_columns(): array {
	return array( 'sponsor name', 'company link', 'sponsor email address', 'gpl', 'trademark', 'status', 'good faith', 'approval status', "'not eligible' until", 'trigger infringement email', 'trigger approval email', 'last modified' );
}

/**
 * Groups the export's rows by the company's domain.
 *
 * @param array[] $rows Rows, as parse_csv() returns them.
 * @return array {
 *     @type array[] $companies Rows, grouped by domain, most recently modified first.
 *     @type array[] $skipped   Rows without a usable company link.
 * }
 */
function group_airtable_rows( array $rows ): array {
	$companies = array();
	$skipped   = array();

	foreach ( $rows as $row ) {
		$domain = normalize_domain( (string) ( $row['company link'] ?? '' ) );

		if ( '' === $domain ) {
			$skipped[] = $row;
		} else {
			$companies[ $domain ][] = $row;
		}
	}

	foreach ( $companies as $domain => $company_rows ) {
		usort( $company_rows, static fn( array $a, array $b ): int => (int) strtotime( (string) ( $b['last modified'] ?? '' ) ) <=> (int) strtotime( (string) ( $a['last modified'] ?? '' ) ) );
		$companies[ $domain ] = $company_rows;
	}

	return compact( 'companies', 'skipped' );
}

/**
 * Maps the camps' names to their sites. Runs on Central.
 *
 * @return int[] Site IDs, keyed by normalized name; 0 where two camps share a name.
 */
function get_camps_by_name(): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A one-time read of every camp, which get_posts() would load whole.
	$camps = $wpdb->get_results(
		"SELECT p.post_title, m.meta_value AS site_id
		FROM {$wpdb->posts} AS p
		INNER JOIN {$wpdb->postmeta} AS m ON m.post_id = p.ID AND m.meta_key = '_site_id'
		WHERE p.post_type = 'wordcamp' AND p.post_status NOT IN ( 'trash', 'auto-draft' )"
	);

	$sites = array();
	foreach ( $camps as $camp ) {
		$name           = normalize_event_name( (string) $camp->post_title );
		$sites[ $name ] = isset( $sites[ $name ] ) ? 0 : (int) $camp->site_id;
	}

	return $sites;
}

/**
 * Normalizes an event's name for matching: case, spacing and entities don't count.
 *
 * @param string $name The name.
 * @return string
 */
function normalize_event_name( string $name ): string {
	return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $name, ENT_QUOTES ) ) ) );
}

/**
 * Splits a row's events into names: one per line, or comma-separated where a line isn't one camp's name.
 *
 * @param string $cell  The Current Event Name cell.
 * @param int[]  $camps Sites, as get_camps_by_name() returns them.
 * @return string[]
 */
function get_airtable_events( string $cell, array $camps ): array {
	$events = array();

	foreach ( preg_split( '/\R/', $cell ) as $line ) {
		$parts = isset( $camps[ normalize_event_name( $line ) ] ) ? array( $line ) : explode( ',', $line );

		foreach ( $parts as $part ) {
			if ( '' !== trim( $part ) ) {
				$events[] = trim( $part );
			}
		}
	}

	return array_values( array_unique( $events ) );
}

/**
 * Reads the dates written like `August 18, 2025` out of a cell.
 *
 * @param string $cell The cell.
 * @return int[] Timestamps, at midnight UTC.
 */
function get_airtable_dates( string $cell ): array {
	preg_match_all( '/[A-Z][a-z]+ \d{1,2}, \d{4}/', $cell, $matches );

	return array_values( array_filter( array_map( static fn( string $date ): int => (int) strtotime( $date . ' UTC' ), $matches[0] ) ) );
}

/**
 * Imports one company and its sponsorships. Runs on Central.
 *
 * A company imported before keeps everything about it, but its rows' events still get sponsorships, so a re-run
 * links the events fixed since. Sponsorships are approved while the company counts as freshly vetted, and its
 * dates are written after, so a company vetted more than a year ago keeps the approvals it had then.
 *
 * @param string  $domain  The company's domain.
 * @param array[] $rows    Its rows, most recently modified first.
 * @param int[]   $camps   Sites, as get_camps_by_name() returns them.
 * @param bool    $dry_run Whether to only work out what would happen.
 * @return array {
 *     @type string   $result       `new`, `existing`, or `error`.
 *     @type string   $message      What went wrong, for an error.
 *     @type int      $company_id   The company, unless this is a dry run for a new one.
 *     @type string   $status       The company's status.
 *     @type string[] $sponsorships What happened to each event, keyed by its name.
 * }
 */
function import_airtable_company( string $domain, array $rows, array $camps, bool $dry_run ): array {
	$row      = $rows[0];
	$existing = find_companies_by_domain( $domain );
	$result   = array(
		'result'       => $existing ? 'existing' : 'new',
		'message'      => '',
		'company_id'   => $existing[0] ?? 0,
		'status'       => $existing ? (string) get_post_status( $existing[0] ) : ( get_airtable_statuses()[ strtolower( (string) ( $row['status'] ?? '' ) ) ] ?? '' ),
		'sponsorships' => array(),
	);

	if ( '' === $result['status'] ) {
		$result['result']  = 'error';
		$result['message'] = sprintf( 'Unknown status "%s".', $row['status'] );
		return $result;
	}

	if ( ! $existing && ! $dry_run ) {
		$emails     = preg_split( '/[\s,;]+/', (string) ( $row['sponsor email address'] ?? '' ) );
		$company_id = wp_insert_post(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => get_initial_status( COMPANY_POST_TYPE ),
				'post_title'  => sanitize_text_field( (string) $row['sponsor name'] ),
				'meta_input'  => array(
					'_sv_contact_email'      => strtolower( sanitize_email( (string) $emails[0] ) ),
					'_sv_gpl_findings'       => sanitize_textarea_field( (string) ( $row['gpl'] ?? '' ) ),
					'_sv_trademark_findings' => sanitize_textarea_field( (string) ( $row['trademark'] ?? '' ) ),
				),
			),
			true
		);

		if ( is_wp_error( $company_id ) ) {
			$result['result']  = 'error';
			$result['message'] = $company_id->get_error_message();
			return $result;
		}

		$result['company_id'] = $company_id;
		set_company_domains( $company_id, array( $domain ) );
		wp_update_post(
			array(
				'ID'          => $company_id,
				'post_status' => $result['status'],
			)
		);

		$until = get_airtable_dates( (string) ( $row["'not eligible' until"] ?? '' ) );
		if ( $until ) {
			update_post_meta( $company_id, '_sv_not_eligible_until', gmdate( 'Y-m-d', $until[0] ) );
		}

		if ( 'compliance in progress' === strtolower( (string) $row['status'] ) ) {
			update_post_meta( $company_id, '_sv_sponsor_replied', time() );
		}

		if ( 'sv-compliance-req' === $result['status'] ) {
			add_airtable_conversations( $company_id, $rows );
		}
	}

	$company_id     = $result['company_id'];
	$last_vetted    = $existing ? (int) get_post_meta( $company_id, '_sv_last_vetted', true ) : 0;
	$vetted         = array();
	$sponsorships   = array();
	$is_writing_now = $company_id && ! $dry_run;

	if ( $is_writing_now ) {
		update_post_meta( $company_id, '_sv_last_vetted', time() );
	}

	foreach ( $rows as $event_row ) {
		$approved_at = get_airtable_dates( (string) ( $event_row['approval date'] ?? '' ) );
		$vetted      = array_merge( $vetted, $approved_at, get_airtable_dates( (string) ( $event_row['previously approved dates (if any)'] ?? '' ) ) );

		foreach ( get_airtable_events( (string) ( $event_row['current event name'] ?? '' ), $camps ) as $event ) {
			$imported = import_airtable_sponsorship( $event_row, $event, $domain, $camps, $company_id, $approved_at[0] ?? 0, $dry_run );

			$result['sponsorships'][ $event ] = $imported['outcome'];
			$sponsorships[]                   = $imported['sponsorship_id'];
		}

		if ( ! $existing && $is_writing_now ) {
			add_airtable_note( $company_id, $event_row );
		}
	}

	if ( $is_writing_now ) {
		$last_vetted = $existing ? $last_vetted : (int) max( array( 0, ...$vetted ) );

		if ( $last_vetted ) {
			update_post_meta( $company_id, '_sv_last_vetted', $last_vetted );
		} else {
			delete_post_meta( $company_id, '_sv_last_vetted' );
		}

		// Now that the real date is back, a pending sponsorship of a lapsed company queues it as intake would.
		foreach ( array_filter( $sponsorships ) as $sponsorship_id ) {
			queue_company( $sponsorship_id );
		}
	}

	return $result;
}

/**
 * Imports one event's sponsorship of a company, if the camp has the company as a sponsor. Runs on Central.
 *
 * @param array  $row         The row.
 * @param string $event       The event's name.
 * @param string $domain      The company's domain.
 * @param int[]  $camps       Sites, as get_camps_by_name() returns them.
 * @param int    $company_id  The company, or 0 in a dry run for a new one.
 * @param int    $approved_at When Airtable says it was approved, or 0.
 * @param bool   $dry_run     Whether to only work out what would happen.
 * @return array {
 *     @type string $outcome        What happened, or would.
 *     @type int    $sponsorship_id The sponsorship, or 0.
 * }
 */
function import_airtable_sponsorship( array $row, string $event, string $domain, array $camps, int $company_id, int $approved_at, bool $dry_run ): array {
	$result  = array(
		'outcome'        => '',
		'sponsorship_id' => 0,
	);
	$blog_id = $camps[ normalize_event_name( $event ) ] ?? 0;

	if ( ! $blog_id ) {
		$result['outcome'] = 'no camp has this name';
		return $result;
	}

	$sponsor_id = find_camp_sponsor_by_domain( $blog_id, $domain );

	if ( ! $sponsor_id ) {
		$result['outcome'] = 'the camp has no sponsor with this domain';
		return $result;
	}

	$is_canceled = 'canceled' === strtolower( (string) ( $row['status'] ?? '' ) );
	$is_approved = ! $is_canceled && 'yes' === strtolower( (string) ( $row['approval status'] ?? '' ) );
	$current     = find_sponsorship( $blog_id, $sponsor_id );

	if ( $current && 'svs-pending' !== get_post_status( $current ) ) {
		$result['outcome'] = 'already ' . get_sponsorship_statuses()[ get_post_status( $current ) ];
		return $result;
	}

	$result['outcome'] = $is_canceled ? 'canceled' : ( $is_approved ? 'approved' : 'pending' );

	if ( $dry_run ) {
		return $result;
	}

	$sponsorship_id = request_vetting( $blog_id, $sponsor_id );

	if ( is_wp_error( $sponsorship_id ) ) {
		$result['outcome'] = $sponsorship_id->get_error_message();
		return $result;
	}

	$result['sponsorship_id'] = $sponsorship_id;

	if ( (int) get_post_field( 'post_parent', $sponsorship_id ) !== $company_id ) {
		$linked = link_sponsorship( $sponsorship_id, $company_id );

		if ( is_wp_error( $linked ) ) {
			$result['outcome'] = $linked->get_error_message();
			return $result;
		}
	}

	if ( $is_canceled ) {
		wp_update_post(
			array(
				'ID'          => $sponsorship_id,
				'post_status' => 'svs-canceled',
			)
		);
	} elseif ( $is_approved ) {
		$good_faith = 'yes' === strtolower( (string) ( $row['good faith'] ?? '' ) ) && 'sv-compliance-req' === get_post_status( $company_id );
		$approved   = approve_sponsorship( $sponsorship_id, $good_faith );

		if ( is_wp_error( $approved ) ) {
			$result['outcome'] = $approved->get_error_message();
			return $result;
		}

		if ( $approved_at ) {
			update_post_meta( $sponsorship_id, '_sv_approved_at', $approved_at );
		}
	}

	return $result;
}

/**
 * Records a company's FreeScout links, and Help Scout links migrated to FreeScout, as its compliance conversations,
 * so replies to them are tracked.
 *
 * @param int     $company_id The company.
 * @param array[] $rows       Its rows.
 */
function add_airtable_conversations( int $company_id, array $rows ): void {
	$conversations = array();

	foreach ( $rows as $row ) {
		preg_match_all( '#https://freescout\.wordpress\.net/conversation/(\d+)#', convert_helpscout_links( (string) ( $row['email conversation link'] ?? '' ) ), $links, PREG_SET_ORDER );

		foreach ( $links as $link ) {
			$conversations[ (int) $link[1] ] = array(
				'id'             => (int) $link[1],
				'url'            => esc_url_raw( $link[0] ),
				'type'           => 'compliance',
				'sponsorship_id' => 0,
				'user_id'        => 0,
				'time'           => (int) strtotime( (string) ( $row['last modified'] ?? '' ) ),
			);
		}
	}

	foreach ( $conversations as $conversation ) {
		add_post_meta( $company_id, '_sv_conversation', $conversation );
	}
}

/**
 * Points Help Scout links at the FreeScout conversations they were migrated to.
 *
 * A link missing from the map gets FreeScout's host, whose forwarder for old links still finds the conversation.
 *
 * @param string $text Text with links.
 * @return string
 */
function convert_helpscout_links( string $text ): string {
	static $map = null;
	$map      ??= require __DIR__ . '/airtable-helpscout.php';

	return (string) preg_replace_callback(
		'~https://secure\.helpscout\.net/conversation/(\d+)(?:/\d+)?/?(?:#thread-\d+)?~',
		static fn( array $link ): string => isset( $map[ (int) $link[1] ] )
			? 'https://freescout.wordpress.net/conversation/' . $map[ (int) $link[1] ]
			: str_replace( 'secure.helpscout.net', 'freescout.wordpress.net', $link[0] ),
		$text
	);
}

/**
 * Adds a row's other columns to the company as a note.
 *
 * @param int   $company_id The company.
 * @param array $row        The row.
 */
function add_airtable_note( int $company_id, array $row ): void {
	$lines = array();

	foreach ( array_diff_key( $row, array_flip( get_airtable_field_columns() ) ) as $column => $value ) {
		if ( '' !== $value ) {
			$lines[] = ucfirst( $column ) . ': ' . convert_helpscout_links( $value );
		}
	}

	if ( $lines ) {
		add_note( $company_id, "Imported from Airtable.\n" . implode( "\n", $lines ) );
	}
}

if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
	return;
}

\WP_CLI::add_command(
	'sponsor-vetting import-airtable',
	/**
	 * Imports the Airtable vetting base from its CSV export.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The CSV export.
	 *
	 * [--dry-run]
	 * : Report what would be imported, without importing it.
	 *
	 * [--format=<format>]
	 * : How to print the report: table, csv, or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp --url=central.wordcamp.org sponsor-vetting import-airtable vetting.csv --dry-run --format=csv > report.csv
	 *
	 * @param string[] $args       The file.
	 * @param array    $assoc_args The options.
	 */
	static function ( array $args, array $assoc_args ): void {
		if ( ! is_central() ) {
			\WP_CLI::error( 'Run this on Central: pass --url=central.wordcamp.org.' );
		}

		$rows = parse_csv( $args[0], array( 'sponsor name', 'company link', 'status', 'current event name' ) );

		if ( is_wp_error( $rows ) ) {
			\WP_CLI::error( $rows->get_error_message() );
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$grouped = group_airtable_rows( $rows );
		$camps   = get_camps_by_name();
		$report  = array();

		foreach ( $grouped['skipped'] as $row ) {
			$report[] = array(
				'company'      => $row['sponsor name'],
				'domain'       => '',
				'result'       => 'error',
				'status'       => '',
				'sponsorships' => 'No company link.',
			);
		}

		foreach ( $grouped['companies'] as $domain => $company_rows ) {
			$result = import_airtable_company( (string) $domain, $company_rows, $camps, $dry_run );

			$report[] = array(
				'company'      => $company_rows[0]['sponsor name'],
				'domain'       => $domain,
				'result'       => $result['result'],
				'status'       => $result['status'],
				'sponsorships' => '' !== $result['message'] ? $result['message'] : implode( '; ', array_map( static fn( string $event, string $outcome ): string => "$event: $outcome", array_keys( $result['sponsorships'] ), $result['sponsorships'] ) ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $report, array( 'company', 'domain', 'result', 'status', 'sponsorships' ) );

		$counts = array_count_values( array_column( $report, 'result' ) );
		\WP_CLI::success( sprintf( '%s%d new, %d existing, %d errors.', $dry_run ? '[dry-run] ' : '', $counts['new'] ?? 0, $counts['existing'] ?? 0, $counts['error'] ?? 0 ) );
	}
);
