<?php
/**
 * A daily summary of each group, stored as site meta so other networks can read it.
 *
 * The Meetup tracker on WordCamp Central shows it for groups that moved to
 * GatherPress (#2374). Central can't count events itself because GatherPress
 * and the recurring-events plugin only load on this network, so the summary is
 * built here and Central only reads it with `get_site_meta()`.
 *
 * Loaded on the groups network only (sits in the `groups/` mu-plugins folder).
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Group_Stats;

use Throwable;
use WordCamp\Logger;
use WP_Error;

use function WordCamp\Groups\Events_Feed\get_group_site_ids;
use function WordCamp\Groups\Frontend\Group_Location\get_location_label;
use function WordCamp\Groups\Network_Export\collect_site_rows;

use const WordCamp\Groups\Frontend\Capabilities\ORGANIZER_ROLES;
use const WordCamp\Groups\Meetup_Import\RSVP_YES_META;

defined( 'WPINC' ) || die();

/** Site meta holding the summary. Central reads it under the same key. */
const META_KEY = 'wporg_group_stats';

/** Bumped when the summary's shape changes. */
const VERSION = 1;

/** Hourly cron on the groups root that refreshes a 24th of the sites. */
const CRON_HOOK = 'wporg_groups_refresh_stats';

/** One-off refresh of one site. Central schedules it for "Sync now". */
const REFRESH_HOOK = 'wporg_groups_refresh_site_stats';

add_action( 'init', __NAMESPACE__ . '\schedule_refresh' );
add_action( CRON_HOOK, __NAMESPACE__ . '\refresh_due_sites' );
add_action( REFRESH_HOOK, __NAMESPACE__ . '\refresh_site' );

/**
 * Schedule the hourly refresh on the groups root.
 */
function schedule_refresh(): void {
	if ( GROUPS_ROOT_BLOG_ID !== get_current_blog_id() || wp_next_scheduled( CRON_HOOK ) ) {
		return;
	}

	wp_schedule_event( time(), 'hourly', CRON_HOOK );
}

/**
 * Refresh the sites due this hour, so each site is refreshed once a day.
 *
 * @param int|null $now Unix time.
 */
function refresh_due_sites( ?int $now = null ): void {
	$hour = (int) gmdate( 'G', $now ?? time() );

	foreach ( get_group_site_ids() as $site_id ) {
		if ( $site_id % 24 === $hour ) {
			refresh_site( $site_id );
		}
	}
}

/**
 * Build a site's summary and store it. A failure keeps the previous summary.
 *
 * @param int $site_id Group site.
 * @return array|WP_Error The stored summary.
 */
function refresh_site( $site_id ) {
	$site_id = (int) $site_id;

	try {
		$stats = build_summary( $site_id );
	} catch ( Throwable $throwable ) {
		$stats = new WP_Error( 'group_stats_threw', $throwable->getMessage() );
	}

	if ( is_wp_error( $stats ) ) {
		Logger\log(
			'group_stats_failed',
			array(
				'site_id' => $site_id,
				'error'   => $stats->get_error_message(),
			)
		);

		return $stats;
	}

	update_site_meta( $site_id, META_KEY, $stats );

	return $stats;
}

/**
 * Build a site's summary.
 *
 * @param int $site_id Group site.
 * @return array|WP_Error
 */
function build_summary( int $site_id ) {
	$rows = collect_site_rows( $site_id );

	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	try {
		switch_to_blog( $site_id );

		$event_ids = array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'event_id' ) ) ) );
		update_postmeta_cache( $event_ids );

		// Imported Meetup.com events keep their RSVP count as meta, not as RSVPs.
		$imported = array();

		foreach ( $event_ids as $event_id ) {
			$imported[ $event_id ] = (int) get_post_meta( $event_id, RSVP_YES_META, true );
		}

		$users = count_users();

		return array_merge(
			array(
				'version'       => VERSION,
				'synced_at'     => time(),
				'location'      => get_location_label(),
				'members_count' => (int) $users['total_users'],
				'organizers'    => get_users(
					array(
						'role__in' => ORGANIZER_ROLES,
						'fields'   => 'user_login',
						'orderby'  => 'login',
					)
				),
			),
			summarize_events( $rows, $imported, time() )
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Turn the network export's per-instance rows into the event numbers.
 *
 * @param array[] $rows     Rows from `collect_site_rows()`.
 * @param int[]   $imported Meetup.com RSVP counts, keyed by event ID.
 * @param int     $now      Unix time.
 * @return array
 */
function summarize_events( array $rows, array $imported, int $now ): array {
	$now_gmt     = gmdate( 'Y-m-d H:i:s', $now );
	$year_ago    = gmdate( 'Y-m-d H:i:s', $now - YEAR_IN_SECONDS );
	$past_count  = 0;
	$last        = null;
	$next        = '';
	$recent_rsvp = array();

	foreach ( get_instances( $rows, $imported ) as $instance ) {
		if ( $instance['start'] >= $now_gmt ) {
			if ( '' === $next || $instance['start'] < $next ) {
				$next = $instance['start'];
			}
			continue;
		}

		++$past_count;

		if ( ! $last || $instance['start'] > $last['start'] ) {
			$last = $instance;
		}

		if ( $instance['start'] >= $year_ago ) {
			$recent_rsvp[] = $instance['attending'];
		}
	}

	return array(
		'past_events_count'     => $past_count,
		'last_event_at'         => $last ? $last['start'] : '',
		'last_event_rsvps'      => $last ? $last['attending'] : 0,
		'next_event_at'         => $next,
		'events_last_12_months' => count( $recent_rsvp ),
		'average_rsvps'         => $recent_rsvp ? round( array_sum( $recent_rsvp ) / count( $recent_rsvp ), 1 ) : 0,
	);
}

/**
 * Each event instance held or planned, with its start (GMT) and attending count.
 *
 * A series counts its scheduled occurrences only, which leaves out cancelled
 * dates and the export's extra rows for RSVPs that belong to no occurrence.
 *
 * @param array[] $rows     Rows from `collect_site_rows()`.
 * @param int[]   $imported Meetup.com RSVP counts, keyed by event ID.
 * @return array[]
 */
function get_instances( array $rows, array $imported ): array {
	$by_event = array();

	foreach ( $rows as $row ) {
		$by_event[ $row['event_id'] ][] = $row;
	}

	$instances = array();

	foreach ( $by_event as $event_id => $event_rows ) {
		$is_series = (bool) array_filter( array_column( $event_rows, 'occurrence_status' ) );

		if ( $is_series ) {
			foreach ( $event_rows as $row ) {
				if ( 'scheduled' === $row['occurrence_status'] && '' !== $row['event_start_gmt'] ) {
					$instances[] = array(
						'start'     => $row['event_start_gmt'],
						'attending' => $row['attending_count'],
					);
				}
			}

			continue;
		}

		if ( '' === $event_rows[0]['event_start_gmt'] ) {
			continue;
		}

		$instances[] = array(
			'start'     => $event_rows[0]['event_start_gmt'],
			'attending' => max( array_sum( array_column( $event_rows, 'attending_count' ) ), $imported[ $event_id ] ?? 0 ),
		);
	}

	return $instances;
}
