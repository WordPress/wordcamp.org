<?php

namespace WordPressdotorg\Events_2023;

use WP_Error;
use WordPressdotorg\MU_Plugins\Utilities\Meetup_Client;

defined( 'WPINC' ) || die();

/*
 * The homepage stats.
 *
 * The three figures on the front cover were hardcoded for the launch and bumped by hand since. They now
 * come from the chapter program's Meetup groups, through the same client the WordCamp Reports plugin uses,
 * plus the WordCamps on central, and are refreshed hourly from cron. The page only ever reads the stored
 * figures, so the Meetup API is never on a visitor's request, and until the first refresh has run it
 * shows the copy it launched with.
 */

const STATS_OPTION    = 'wporg_events_stats';
const STATS_CRON_HOOK = 'wporg_events_refresh_stats';

/**
 * The figures the page launched with, shown until the first refresh has stored real ones.
 *
 * These are the all-time figures set by hand on 2026-09-30.
 */
const STATS_FALLBACK = array(
	'events'    => 56000,
	'groups'    => 711,
	'countries' => 100,
	'members'   => 500000,
);

add_action( 'init', __NAMESPACE__ . '\schedule_stats_cron' );
add_action( STATS_CRON_HOOK, __NAMESPACE__ . '\refresh_stats' );

/**
 * Refresh the stats every hour.
 */
function schedule_stats_cron(): void {
	if ( ! wp_next_scheduled( STATS_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'hourly', STATS_CRON_HOOK );
	}
}

/**
 * Fetch fresh figures and store them.
 *
 * When the groups can't be fetched, the figures already stored stay, so a Meetup outage never puts zeros
 * on the homepage.
 */
function refresh_stats(): void {
	$groups = fetch_meetup_groups();

	if ( is_wp_error( $groups ) || ! is_array( $groups ) || ! $groups ) {
		return;
	}

	$stats            = compute_stats( $groups, count_wordcamps() );
	$stats['updated'] = time();

	update_option( STATS_OPTION, $stats, false );
}

/**
 * The chapter program's groups, as `Meetup_Client::get_groups()` returns them.
 *
 * The `wporg_events_stats_groups` filter supplies them instead when it returns anything but null,
 * which is how the tests feed data in without the API.
 *
 * @return array|WP_Error
 */
function fetch_meetup_groups() {
	$groups = apply_filters( 'wporg_events_stats_groups', null );

	if ( null !== $groups ) {
		return $groups;
	}

	if ( ! class_exists( Meetup_Client::class ) ) {
		return new WP_Error( 'no_meetup_client', 'The Meetup client is not available.' );
	}

	$client = new Meetup_Client();

	return $client->get_groups();
}

/**
 * How many WordCamps have been held or are on the schedule, from the posts on central.
 *
 * The `wporg_events_stats_wordcamp_count` filter supplies the number instead when it returns anything
 * but null.
 *
 * @return int
 */
function count_wordcamps(): int {
	$count = apply_filters( 'wporg_events_stats_wordcamp_count', null );

	if ( null !== $count ) {
		return (int) $count;
	}

	if ( ! defined( 'WORDCAMP_ROOT_BLOG_ID' ) || ! function_exists( 'get_wordcamps' ) ) {
		return 0;
	}

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	$wordcamps = get_wordcamps( array(
		'post_status' => array( 'wcpt-scheduled', 'wcpt-closed' ),
		'fields'      => 'ids',
	) );

	restore_current_blog();

	return count( $wordcamps );
}

/**
 * Turn the groups and the WordCamp count into the four figures the page shows.
 *
 * @param array[] $groups    Groups with `member_count`, `country` and `past_event_count`.
 * @param int     $wordcamps How many WordCamps there have been.
 *
 * @return array { events, groups, countries, members }
 */
function compute_stats( array $groups, int $wordcamps ): array {
	$events    = $wordcamps;
	$members   = 0;
	$countries = array();

	foreach ( $groups as $group ) {
		$events  += absint( $group['past_event_count'] ?? 0 );
		$members += absint( $group['member_count'] ?? 0 );

		$country = strtoupper( trim( (string) ( $group['country'] ?? '' ) ) );

		if ( '' !== $country ) {
			$countries[ $country ] = true;
		}
	}

	return array(
		'events'    => $events,
		'groups'    => count( $groups ),
		'countries' => count( $countries ),
		'members'   => $members,
	);
}

/**
 * The stored figures, or the launch copy's until a refresh has stored some.
 *
 * @return array { events, groups, countries, members, updated, fallback }
 */
function get_stats(): array {
	$stored = get_option( STATS_OPTION );

	if ( is_array( $stored ) && isset( $stored['events'], $stored['groups'], $stored['countries'], $stored['members'] ) ) {
		return array_merge( array( 'updated' => 0 ), $stored, array( 'fallback' => false ) );
	}

	$fallback             = STATS_FALLBACK;
	$fallback['updated']  = 0;
	$fallback['fallback'] = true;

	return $fallback;
}

/**
 * One stat as the sentence the front cover shows.
 *
 * Events and members are rounded down to a clean figure with a plus, since the exact number changes
 * by the hour and a few hundred isn't the point; groups and countries are shown exactly.
 *
 * @param string $stat  `events`, `groups` or `members`.
 * @param array  $stats From `get_stats()`.
 *
 * @return string Empty for a stat that doesn't exist.
 */
function format_stat( string $stat, array $stats ): string {
	switch ( $stat ) {
		case 'events':
			return sprintf(
				/* translators: %s: number of events, e.g. "56,000+". */
				__( '%s WordPress events since 2006', 'wporg' ),
				round_down_with_plus( (int) $stats['events'], 1000 )
			);

		case 'groups':
			return sprintf(
				/* translators: 1: number of local groups, 2: number of countries. */
				__( '%1$s local groups in %2$s countries', 'wporg' ),
				number_format_i18n( (int) $stats['groups'] ),
				number_format_i18n( (int) $stats['countries'] )
			);

		case 'members':
			return sprintf(
				/* translators: %s: number of meetup members, e.g. "500,000+". */
				__( '%s meetup members', 'wporg' ),
				round_down_with_plus( (int) $stats['members'], 10000 )
			);
	}

	return '';
}

/**
 * Round a figure down to a step and mark it as "at least that": 56,789 → "56,000+".
 *
 * A figure below the step is shown as it is.
 *
 * @param int $number
 * @param int $step
 *
 * @return string
 */
function round_down_with_plus( int $number, int $step ): string {
	if ( $number < $step ) {
		return number_format_i18n( $number );
	}

	return number_format_i18n( (int) floor( $number / $step ) * $step ) . '+';
}
