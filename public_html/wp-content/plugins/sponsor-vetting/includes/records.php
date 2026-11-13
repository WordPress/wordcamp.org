<?php
/**
 * How companies and sponsorships are described to vetters: a company's flags, next event and sponsorship count, an
 * event's date, an approval, and the wording of the notes and history.
 *
 * Loaded on Central with the REST fields that use it.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

/**
 * Lists what a reviewer should know about a company beyond its status.
 *
 * A sponsor's reply links to the conversation the reply check read.
 *
 * @param int $company_id The company.
 * @return array[] Each flag's `type` and `text`, and where it has them the `url` it links to, the `date` it's
 *                 about, and whether it's `overdue`.
 */
function get_company_flag_items( int $company_id ): array {
	$state = get_vetting_state( $company_id );
	$items = array();

	if ( $state['expired'] ) {
		$items[] = array(
			'type' => 'lapsed',
			'text' => __( 'Vetting has lapsed', 'wordcamporg' ),
		);
	}

	if ( $state['not_eligible_active'] ) {
		$until = (string) get_post_meta( $company_id, '_sv_not_eligible_until', true );

		$items[] = array(
			'type' => 'not_eligible',
			/* translators: %s: Date. */
			'text' => '' === $until ? __( 'Indefinitely', 'wordcamporg' ) : sprintf( __( 'Until %s', 'wordcamporg' ), $until ),
		);
	} elseif ( 'sv-not-eligible' === $state['status'] ) {
		$items[] = array(
			'type' => 'not_eligible_ended',
			'text' => __( 'Not Eligible period has ended', 'wordcamporg' ),
		);
	}

	$due = (string) get_post_meta( $company_id, '_sv_good_faith_due', true );

	if ( $state['good_faith_overdue'] ) {
		$days = (int) ( ( strtotime( gmdate( 'Y-m-d' ) ) - strtotime( $due ) ) / DAY_IN_SECONDS );

		$items[] = array(
			'type'    => 'good_faith',
			/* translators: %d: Number of days. */
			'text'    => sprintf( _n( 'Good Faith overdue for %d day', 'Good Faith overdue for %d days', $days, 'wordcamporg' ), $days ),
			'date'    => $due,
			'overdue' => true,
		);
	} elseif ( '' !== $due ) {
		$days = (int) ( ( strtotime( $due ) - strtotime( gmdate( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );

		$items[] = array(
			'type' => 'good_faith',
			/* translators: %d: Number of days. */
			'text' => $days ? sprintf( _n( 'Good Faith due in %d day', 'Good Faith due in %d days', $days, 'wordcamporg' ), $days ) : __( 'Good Faith due today', 'wordcamporg' ),
			'date' => $due,
		);
	}

	if ( 'sv-compliance-req' === $state['status'] && get_post_meta( $company_id, '_sv_sponsor_replied', true ) ) {
		$items[] = array(
			'type' => 'replied',
			'text' => __( 'Sponsor replied', 'wordcamporg' ),
			'url'  => (string) ( get_latest_conversation( $company_id, 'compliance' )['url'] ?? '' ),
		);
	}

	return $items;
}

/**
 * Gets the soonest event still to come for companies, among the events their pending sponsorships are for.
 *
 * @param int[]|null $company_ids The companies, or null for all of them.
 * @return array[] The event's start date, as get_event_dates() returns it, and the sponsorship it's for, keyed by
 *                 company, soonest first; only companies with one.
 */
function get_next_events( ?array $company_ids = null ): array {
	$next = array();

	if ( array() === $company_ids ) {
		return $next;
	}

	$sponsorships = get_posts(
		array(
			'post_type'      => SPONSORSHIP_POST_TYPE,
			'post_status'    => 'svs-pending',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		) + ( null === $company_ids ? array( 'post_parent__not_in' => array( 0 ) ) : array( 'post_parent__in' => $company_ids ) )
	);
	$blog_ids     = get_sponsorship_blog_ids( wp_list_pluck( $sponsorships, 'ID' ) );
	$dates        = get_event_dates( $blog_ids );
	$today        = strtotime( gmdate( 'Y-m-d' ) . ' UTC' );

	foreach ( $sponsorships as $sponsorship ) {
		$start = $dates[ $blog_ids[ $sponsorship->ID ] ] ?? 0;

		if ( $start >= $today && $start < ( $next[ $sponsorship->post_parent ]['start'] ?? PHP_INT_MAX ) ) {
			$next[ $sponsorship->post_parent ] = array(
				'start'          => $start,
				'sponsorship_id' => $sponsorship->ID,
			);
		}
	}

	uasort( $next, static fn( array $a, array $b ): int => $a['start'] <=> $b['start'] );

	return $next;
}

/**
 * Counts the sponsorships of companies, in one query for them all.
 *
 * @param int[]|null $company_ids The companies, or null for all of them.
 * @return int[] Counts, keyed by company ID; only companies with one.
 */
function get_sponsorship_counts( ?array $company_ids = null ): array {
	global $wpdb;

	$counts   = array();
	$statuses = array_keys( get_sponsorship_statuses() );

	if ( array() === $company_ids ) {
		return $counts;
	}

	$parents = null === $company_ids ? 'post_parent <> 0' : sprintf( 'post_parent IN (%s)', implode( ',', array_fill( 0, count( $company_ids ), '%d' ) ) );

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One grouped count per screen; nothing to cache across requests.
		$wpdb->prepare(
			sprintf(
				"SELECT post_parent, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = %%s AND %s AND post_status IN (%s) GROUP BY post_parent",
				$parents, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders only.
				implode( ',', array_fill( 0, count( $statuses ), '%s' ) )
			),
			array_merge( array( SPONSORSHIP_POST_TYPE ), (array) $company_ids, $statuses )
		)
	);

	foreach ( $rows as $row ) {
		$counts[ (int) $row->post_parent ] = (int) $row->total;
	}

	return $counts;
}

/**
 * Gets when the events of camp sites start, with one query for them all.
 *
 * A site with more than one `wordcamp` post gets the newest one's, as get_wordcamp_post() does, even when only an
 * older one has a date.
 *
 * @param int[] $blog_ids The camp sites.
 * @return int[] Start dates, as timestamps of midnight UTC, keyed by camp site; only sites whose event has one.
 */
function get_event_dates( array $blog_ids ): array {
	$blog_ids = array_values( array_unique( array_filter( array_map( 'intval', $blog_ids ) ) ) );
	$dates    = array();

	if ( ! $blog_ids ) {
		return $dates;
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	$events = get_posts(
		array(
			'post_type'      => 'wordcamp',
			'post_status'    => 'any',
			'meta_key'       => '_site_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- An exact match on a page of sites.
			'meta_value'     => array_map( 'strval', $blog_ids ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- An exact match on a page of sites.
			'meta_compare'   => 'IN',
			'orderby'        => array(
				'date' => 'ASC',
				'ID'   => 'ASC',
			),
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);

	// Oldest first, so the newest overwrites it.
	foreach ( $events as $event ) {
		$dates[ (int) get_post_meta( $event->ID, '_site_id', true ) ] = (int) get_post_meta( $event->ID, 'Start Date (YYYY-mm-dd)', true );
	}

	restore_current_blog();

	return array_filter( $dates );
}

/**
 * Gets the camp sites of sponsorships.
 *
 * @param int[] $sponsorship_ids The sponsorships.
 * @return int[] Camp sites, keyed by sponsorship.
 */
function get_sponsorship_blog_ids( array $sponsorship_ids ): array {
	$blog_ids = array();

	foreach ( $sponsorship_ids as $sponsorship_id ) {
		$blog_ids[ (int) $sponsorship_id ] = (int) get_post_meta( (int) $sponsorship_id, '_sv_blog_id', true );
	}

	return $blog_ids;
}

/**
 * Describes who approved a sponsorship and when.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return string
 */
function get_approval_summary( int $sponsorship_id ): string {
	$approved_at = (int) get_post_meta( $sponsorship_id, '_sv_approved_at', true );

	if ( ! $approved_at ) {
		return '—';
	}

	$approver = get_userdata( (int) get_post_meta( $sponsorship_id, '_sv_approved_by', true ) );
	$summary  = sprintf(
		/* translators: 1: Date. 2: Username. */
		__( '%1$s by %2$s', 'wordcamporg' ),
		wp_date( 'Y-m-d', $approved_at ),
		$approver ? $approver->user_login : __( 'System', 'wordcamporg' )
	);

	if ( get_post_meta( $sponsorship_id, '_sv_good_faith', true ) ) {
		$summary .= ' ' . __( '(in good faith)', 'wordcamporg' );
	}

	return $summary;
}

/**
 * Links a company to its Multi-Event Sponsor, replacing any earlier link. Each Multi-Event Sponsor has one company.
 *
 * @param int $company_id The company.
 * @param int $mes_id     The `mes` post, or 0 to unlink.
 * @return true|\WP_Error
 */
function link_mes( int $company_id, int $mes_id ): true|\WP_Error {
	if ( $mes_id && 'mes' !== get_post_type( $mes_id ) ) {
		return new \WP_Error( 'sv_no_mes', __( 'That Multi-Event Sponsor doesn’t exist.', 'wordcamporg' ) );
	}

	$linked = get_mes_company( $mes_id );

	if ( $linked && $linked !== $company_id ) {
		return new \WP_Error( 'sv_mes_taken', __( 'That Multi-Event Sponsor is linked to another company.', 'wordcamporg' ) );
	}

	$current = get_posts(
		array(
			'post_type'      => 'mes',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_sv_company_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One company's link.
			'meta_value'     => $company_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One company's link.
		)
	);

	foreach ( array_diff( array_map( 'intval', $current ), array( $mes_id ) ) as $old_mes_id ) {
		delete_post_meta( $old_mes_id, '_sv_company_id' );
	}

	if ( $mes_id ) {
		update_post_meta( $mes_id, '_sv_company_id', $company_id );
	}

	return true;
}

/**
 * Builds what a log entry says.
 *
 * Notes the Airtable import added are shown by what they hold: a comment under its commenter's name, and the
 * imported fields as a table.
 *
 * @param array  $entry     The entry, as get_log() returns it.
 * @param string $post_type The record's post type.
 * @return string HTML.
 */
function get_log_entry_html( array $entry, string $post_type ): string {
	$author     = get_log_author_html( (int) $entry['user_id'] );
	$is_company = COMPANY_POST_TYPE === $post_type;

	if ( ! isset( $entry['text'] ) ) {
		if ( 'new' === $entry['from'] ) {
			return sprintf(
				/* translators: 1: Who created it. 2: Status. */
				esc_html( $is_company ? __( '%1$s created the company as %2$s', 'wordcamporg' ) : __( '%1$s created the sponsorship as %2$s', 'wordcamporg' ) ),
				$author,
				get_log_status_html( $post_type, (string) $entry['to'] )
			);
		}

		return sprintf(
			/* translators: 1: Who changed it. 2: Previous status. 3: New status. */
			esc_html__( '%1$s changed the status from %2$s to %3$s', 'wordcamporg' ),
			$author,
			get_log_status_html( $post_type, (string) $entry['from'] ),
			get_log_status_html( $post_type, (string) $entry['to'] )
		);
	}

	$text = (string) $entry['text'];

	if ( preg_match( '/^Airtable comment by (.+?):\R(.*)$/s', $text, $comment ) ) {
		return '<p class="sv-log-head"><strong>' . esc_html( $comment[1] ) . '</strong></p>' . get_log_text_html( $comment[2] );
	}

	if ( preg_match( '/^Imported from Airtable\.(?:\R(.*))?$/s', $text, $import ) ) {
		$html = '<p class="sv-log-head">' . sprintf(
			/* translators: %s: Who imported it. */
			esc_html( $is_company ? __( '%s imported the company from Airtable', 'wordcamporg' ) : __( '%s imported the sponsorship from Airtable', 'wordcamporg' ) ),
			$author
		) . '</p>';

		$fields = get_imported_fields( $import[1] ?? '' );

		if ( $fields ) {
			$html .= '<details><summary>' . esc_html__( 'Imported details', 'wordcamporg' ) . '</summary><table class="sv-log-fields">';

			foreach ( $fields as $label => $value ) {
				$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . get_clickable_html( $value ) . '</td></tr>';
			}

			$html .= '</table></details>';
		}

		return $html;
	}

	return '<p class="sv-log-head">' . sprintf(
		/* translators: %s: Who added the note. */
		esc_html__( '%s added a note', 'wordcamporg' ),
		$author
	) . '</p>' . get_log_text_html( $text );
}

/**
 * Splits the fields the Airtable import listed, one `Label: value` per line, into a table's rows.
 *
 * Only the export's own columns start a row, so a line inside a value that happens to look like `Label: value` stays
 * part of it. Any other line continues the value before it, and nothing is dropped.
 *
 * @param string $text The lines after the import's first line.
 * @return string[] Values, keyed by label.
 */
function get_imported_fields( string $text ): array {
	$columns = array( 'current event name', 'current event date', 'current event email', 'current event sponsorship amount in usd', 'past sponsorship total amount (qbo since 2016)', 'list of past events sponsored', '5ftf', 'type of business', 'private notes', 'contract', 'batch', 'approval date', 'previously approved dates (if any)', 'email conversation link' );
	$fields  = array();
	$label   = '';

	if ( '' === trim( $text ) ) {
		return $fields;
	}

	foreach ( preg_split( '/\R/', trim( $text ) ) as $line ) {
		if ( preg_match( '/^([^:]{1,60}): (.*)$/', $line, $match ) && in_array( strtolower( $match[1] ), $columns, true ) ) {
			$label            = $match[1];
			$fields[ $label ] = $match[2];
		} elseif ( isset( $fields[ $label ] ) ) {
			$fields[ $label ] .= "\n" . $line;
		} else {
			$fields[ $label ] = $line;
		}
	}

	return $fields;
}

/**
 * Builds who made a log entry: their name, linked to their WordPress.org profile, or System.
 *
 * @param int $user_id The user, or 0 for the system.
 * @return string HTML.
 */
function get_log_author_html( int $user_id ): string {
	$user = get_userdata( $user_id );

	if ( ! $user ) {
		return '<strong class="sv-log-system">' . esc_html__( 'System', 'wordcamporg' ) . '</strong>';
	}

	return sprintf(
		'<a class="sv-log-author" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
		esc_url( 'https://profiles.wordpress.org/' . $user->user_nicename . '/' ),
		esc_html( $user->display_name )
	);
}

/**
 * Builds a status label, colored by status.
 *
 * @param string $post_type The record's post type.
 * @param string $status    The status.
 * @return string HTML.
 */
function get_log_status_html( string $post_type, string $status ): string {
	return sprintf(
		'<span class="sv-log-status sv-log-status-%s">%s</span>',
		esc_attr( $status ),
		esc_html( get_statuses( $post_type )[ $status ] ?? $status )
	);
}

/**
 * Builds a note's text, with its line breaks kept and its links made clickable.
 *
 * @param string $text The note.
 * @return string HTML.
 */
function get_log_text_html( string $text ): string {
	return '<div class="sv-log-text">' . get_clickable_html( trim( $text ) ) . '</div>';
}

/**
 * Escapes text and makes its links clickable, opening in a new tab so a half-written note isn't lost.
 *
 * @param string $text The text.
 * @return string HTML.
 */
function get_clickable_html( string $text ): string {
	return links_add_target( make_clickable( esc_html( $text ) ) );
}
