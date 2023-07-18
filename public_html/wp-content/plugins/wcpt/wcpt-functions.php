<?php

/**
 * Specific method of formatting numeric values.
 *
 * @param string $number   Number to format.
 * @param string $decimals optional Display decimals.
 *
 * @return string Formatted string
 */
function wcpt_number_format( $number, $decimals = false ) {
	if ( empty( $number ) || ! is_numeric( $number ) ) {
		$number = '0';
	}

	return apply_filters( 'wcpt_number_format', number_format( $number, $decimals ), $number, $decimals );
}

/**
 * Turn meta key into lower case string and transform spaces into underscores
 *
 * @param string $key
 *
 * @return string
 */
function wcpt_key_to_str( $key, $prefix = '' ) {
	return $prefix . str_replace( array( ' ', '.' ), '_', strtolower( $key ) );
}

/**
 * Render the Log metabox
 *
 * @param WP_Post $post
 */
function wcpt_log_metabox( $post ) {
	$entries = wcpt_get_log_entries( $post->ID );

	require __DIR__ . '/views/common/metabox-log.php';
}

/**
 * Get all the the entries
 *
 * @param int $event_id
 *
 * @return array
 */
function wcpt_get_log_entries( $event_id ) {
	/*
	 * @todo Rename `_note` meta fields to `_private_note` to make it obvious to devs that the value should be
	 * treated as private. The `get_post_metadata` filter can be used to support back-compat w/out having to
	 * rename old entries in database.
	 */
	$entries        = array();
	$private_notes  = get_post_meta( $event_id, '_note'          );
	$status_changes = get_post_meta( $event_id, '_status_change' );
	$tag_changes    = get_post_meta( $event_id, '_tags_log' );

	foreach ( array(
		'note' => $private_notes,
		'status_change' => $status_changes,
		'tag_change' => $tag_changes,
	) as $entry_type => $raw_entries ) {
		$entries = array_merge( process_raw_entries( $raw_entries, $entry_type ), $entries );
	}

	usort( $entries, 'wcpt_sort_log_entries' );

	return $entries;
}

/**
 * Add displayable data to raw entries.
 *
 * @param array  $raw_entries
 * @param string $entry_type Type of entry
 *
 * @return array
 */
function process_raw_entries( $raw_entries, $entry_type ) {
	$entries = array();
	foreach ( $raw_entries as $entry ) {
		$user = get_user_by( 'id', $entry['user_id'] );

		if ( $user ) {
			$entry['user_display_name'] = $user->display_name;
		} else {
			// Assume that the action was performed during a cron job.
			$entry['user_display_name'] = 'WordCamp Bot';
		}

		$entry['type'] = $entry_type;

		$entries[] = $entry;
	}

	return $entries;
}

/**
 * Sort the log entries in reverse-chronological order.
 *
 * @param array $a
 * @param array $b
 *
 * @return int
 */
function wcpt_sort_log_entries( $a, $b ) {
	// If a status change and a note occur at the same time, show the change before the note.
	if ( $a['timestamp'] == $b['timestamp'] ) {
		return ( 'status_change' == $a['type'] ) ? 1 : -1;
	}

	return ( $a['timestamp'] > $b['timestamp'] ) ? -1 : 1;
}

/**
 * Render the Notes metabox
 *
 * @param WP_Post $post
 */
function wcpt_add_note_metabox( $post ) {
	wp_nonce_field( 'wcpt_notes', 'wcpt_notes_nonce' );

	require_once __DIR__ . '/views/common/metabox-notes.php';
}
