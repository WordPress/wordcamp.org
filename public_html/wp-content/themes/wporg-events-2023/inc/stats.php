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
 *
 * Without a Meetup connection the refresh makes no request at all, so a stack without a token (a local or
 * sandbox one, say) doesn't log a failed request every hour.
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
 * When the groups or the member count can't be fetched, or the groups come back without their analytics,
 * the figures already stored stay, so a Meetup outage or a missing token never puts zeros on the homepage.
 */
function refresh_stats(): void {
	$groups = fetch_meetup_groups();

	if ( is_wp_error( $groups ) || ! is_array( $groups ) || ! $groups || ! has_group_analytics( $groups ) ) {
		return;
	}

	$members = fetch_member_count();

	if ( is_wp_error( $members ) ) {
		return;
	}

	$stats            = compute_stats( $groups, $members, count_wordcamps() );
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

	$client = get_meetup_client();

	if ( is_wp_error( $client ) ) {
		return $client;
	}

	return $client->get_groups();
}

/**
 * How many people are in the chapter program's groups, each counted once.
 *
 * Adding up the groups' own counts would count someone in two groups twice; on 2026-11-24 that was 581,350
 * against 515,286 unique.
 *
 * The `wporg_events_stats_member_count` filter supplies the number instead when it returns anything but null.
 *
 * @return int|WP_Error
 */
function fetch_member_count() {
	$count = apply_filters( 'wporg_events_stats_member_count', null );

	if ( null !== $count ) {
		return $count;
	}

	$client = get_meetup_client();

	if ( is_wp_error( $client ) ) {
		return $client;
	}

	return query_member_count( $client );
}

/**
 * Ask the network's analytics for its unique member count.
 *
 * @param Meetup_Client|object $client Anything with the client's `send_paginated_request()`.
 *
 * @return int|WP_Error An error rather than 0, so a refresh never stores "0 meetup members".
 */
function query_member_count( $client ) {
	$result = $client->send_paginated_request( '
		query {
			proNetwork( urlname: "wordpress" ) {
				networkAnalytics { totalMembers }
			}
		}
	' );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$members = absint( $result['proNetwork']['networkAnalytics']['totalMembers'] ?? 0 );

	if ( ! $members ) {
		return new WP_Error( 'no_member_count', 'The Meetup network did not report its member count.' );
	}

	return $members;
}

/**
 * A Meetup client, if there's a connection to use.
 *
 * @return Meetup_Client|WP_Error
 */
function get_meetup_client() {
	if ( ! is_meetup_connected() ) {
		return new WP_Error( 'meetup_not_connected', 'Meetup is not connected.' );
	}

	if ( ! class_exists( Meetup_Client::class ) ) {
		return new WP_Error( 'no_meetup_client', 'The Meetup client is not available.' );
	}

	return new Meetup_Client();
}

/**
 * Whether there are Meetup credentials and a token to make a request with.
 *
 * The same check as `WordCamp\Groups\Meetup_Import\is_meetup_connected()`, which is only loaded on the
 * Groups network. On the Events network the token is read through to Central's, by
 * `groups/meetup-shared-connection.php`.
 *
 * @return bool
 */
function is_meetup_connected(): bool {
	return function_exists( 'WordCamp\Meetup_OAuth\has_credentials' )
		&& \WordCamp\Meetup_OAuth\has_credentials()
		&& ! empty( get_site_option( \WordCamp\Meetup_OAuth\TOKEN_SITE_OPTION ) );
}

/**
 * Whether any of the groups came back with its analytics.
 *
 * The member and past-event counts come from `groupAnalytics`, which Meetup only returns to an authorized
 * request. Without a token it still lists every group but leaves that field null, and the client turns that
 * into `member_count` 0, so a refresh would store "0 meetup members".
 *
 * @param array[] $groups From `fetch_meetup_groups()`.
 *
 * @return bool
 */
function has_group_analytics( array $groups ): bool {
	foreach ( $groups as $group ) {
		if ( ! empty( $group['groupAnalytics'] ) ) {
			return true;
		}
	}

	return false;
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

	if ( ! defined( 'WORDCAMP_ROOT_BLOG_ID' ) ) {
		return 0;
	}

	global $wpdb;

	switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

	/*
	 * A direct count, not `get_posts()` or `get_wordcamps()`: the cron runs on the Events network, where the
	 * `wcpt-*` statuses aren't registered, so `WP_Query` would drop them and count every `wordcamp` post.
	 */
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s AND post_status IN ( %s, %s )",
		'wordcamp',
		'wcpt-scheduled',
		'wcpt-closed'
	) );

	restore_current_blog();

	return $count;
}

/**
 * Turn the groups, the member count and the WordCamp count into the four figures the page shows.
 *
 * @param array[] $groups    Groups with `country` and `past_event_count`.
 * @param int     $members   The network's unique member count, from `fetch_member_count()`.
 * @param int     $wordcamps How many WordCamps there have been.
 *
 * @return array { events, groups, countries, members }
 */
function compute_stats( array $groups, int $members, int $wordcamps ): array {
	$events    = $wordcamps;
	$countries = array();

	foreach ( $groups as $group ) {
		$events += absint( $group['past_event_count'] ?? 0 );

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
				__( '%s WordPress events since 2006', 'wordcamporg' ),
				round_down_with_plus( (int) $stats['events'], 1000 )
			);

		case 'groups':
			return sprintf(
				/* translators: 1: number of local groups, 2: number of countries. */
				__( '%1$s local groups in %2$s countries', 'wordcamporg' ),
				number_format_i18n( (int) $stats['groups'] ),
				number_format_i18n( (int) $stats['countries'] )
			);

		case 'members':
			return sprintf(
				/* translators: %s: number of meetup members, e.g. "500,000+". */
				__( '%s meetup members', 'wordcamporg' ),
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
