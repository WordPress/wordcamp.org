<?php
/**
 * A public feed of upcoming events across every group site.
 *
 * WordPress.org's `official-wordpress-events` importer reads this hourly and
 * writes the events into the `wporg_events` table. That table is what the
 * Events Widget in every WordPress dashboard (via api.wordpress.org/events),
 * events.wordpress.org's upcoming-events list and map, and Learn all read, so
 * this feed is how a group's events get found by people who don't already
 * know the group exists (#1786).
 *
 * Served from the Groups network root, because GatherPress (and so the event
 * post type, its tables and its permalinks) only loads on this network:
 *
 *     https://events.wordpress.org/group/wp-json/wporg-groups/v1/events
 *
 * It's public and unauthenticated, so it's built to be cheap and to say
 * nothing a logged-out visitor of the group's own pages couldn't already see:
 *
 * - Only published, non-password-protected events on active group sites.
 * - A password-protected venue contributes nothing; the event is still listed,
 *   with no venue.
 * - Online events are flagged, but the meeting link is never included.
 * - RSVPs only as an aggregate attending count; never names or accounts.
 * - Venue phone numbers and websites are left out; nothing here needs them.
 *
 * Paging is by site ID (`?after=<id>`) rather than by page number, at a fixed
 * number of sites per page. The importer marks an event deleted when a full
 * pass no longer contains it, so a site that slipped between two pages because
 * another site was created or archived mid-pass would have every event
 * wrongly deleted. Keyset paging can't skip a site that way. For the same
 * reason, a page that couldn't read one of its sites says so with
 * `complete: false`, and the importer must not delete anything on that pass.
 *
 * Each page is cached, and `after` must be `0` or the ID of a group site, so
 * the number of distinct cache entries is bounded by the number of groups.
 * An arbitrary `after` can't be used to bypass the cache.
 *
 * Loaded on the groups network only (sits in the `groups/` mu-plugins folder).
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Events_Feed;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Venue\Setup as Venue_Setup;
use Throwable;
use WordCamp\Logger;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Database as Recurring_Events_Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function WordCamp\Groups\Frontend\Group_Location\get_location;

defined( 'WPINC' ) || die();

/** REST namespace. */
const REST_NAMESPACE = 'wporg-groups/v1';

/** REST route, relative to the namespace. */
const REST_ROUTE = '/events';

/**
 * Group sites per page. Fixed, so a client can't ask for an expensive page.
 */
const SITES_PER_PAGE = 10;

/**
 * Upper bound on events listed per site, as a guard against a runaway series
 * or a spam group making one page arbitrarily expensive. Far above what any
 * real group schedules in a year.
 */
const MAX_EVENTS_PER_SITE = 200;

/**
 * How far ahead to list events. Matches the importer's Meetup.com window.
 */
const WINDOW_AHEAD = YEAR_IN_SECONDS;

/**
 * How far back an event's end may be and still be listed. Matches the
 * importer's Meetup.com leeway for timezones.
 */
const WINDOW_BEHIND = DAY_IN_SECONDS;

/** Object cache group for rendered pages. */
const CACHE_GROUP = 'wporg-groups-events-feed';

/**
 * How long a rendered page is cached. The importer reads hourly, and every
 * consumer downstream of it caches for hours more, so a few minutes of
 * staleness here is invisible.
 */
const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

/** `Cache-Control` max-age sent to clients and any CDN in front of us. */
const CLIENT_MAX_AGE = 5 * MINUTE_IN_SECONDS;

/**
 * Longest `source_id` the importer can store; `wporg_events.source_id` is a
 * `varchar(32)`.
 */
const SOURCE_ID_MAX_LENGTH = 32;

/** Words kept in an event's plain-text description. */
const DESCRIPTION_WORDS = 55;

add_action( 'rest_api_init', __NAMESPACE__ . '\register_route' );
wp_cache_add_global_groups( array( CACHE_GROUP ) );

/**
 * Register the feed route, on the Groups network root only.
 *
 * The root is the one URL on the network that isn't a group, and the feed
 * covers every group, so serving it from a group site would make it look
 * like that group's feed. Every other path on the root still redirects; see
 * `is_reachable_root_endpoint()` in `sunrise-groups.php`.
 */
function register_route(): void {
	if ( GROUPS_ROOT_BLOG_ID !== get_current_blog_id() ) {
		return;
	}

	register_rest_route(
		REST_NAMESPACE,
		REST_ROUTE,
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\get_feed',
			// Public by design: it lists events that are already public on each
			// group's own pages, for an unauthenticated importer. What it may
			// contain is restricted in `collect_site_events()`.
			'permission_callback' => '__return_true',
			'args'                => array(
				'after' => array(
					'description'       => 'Return the group sites after this site ID. 0, or the `next` value of the previous page.',
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
				),
			),
		)
	);
}

/**
 * Serve one page of the feed.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function get_feed( WP_REST_Request $request ) {
	$after    = (int) $request->get_param( 'after' );
	$site_ids = get_group_site_ids();

	if ( 0 !== $after && ! in_array( $after, $site_ids, true ) ) {
		return new WP_Error( 'wporg_groups_events_feed_invalid_cursor', 'Invalid cursor.', array( 'status' => 400 ) );
	}

	$cache_key = 'page:' . $after;
	$page      = wp_cache_get( $cache_key, CACHE_GROUP );

	if ( ! is_array( $page ) ) {
		$page = build_page( $site_ids, $after, time() );

		// An incomplete page isn't cached, so the next pass retries the site
		// rather than serving the failure for the whole TTL.
		if ( $page['complete'] ) {
			wp_cache_set( $cache_key, $page, CACHE_GROUP, CACHE_TTL );
		}
	}

	$response = new WP_REST_Response( $page );
	$response->header( 'Cache-Control', 'public, max-age=' . CLIENT_MAX_AGE );

	return $response;
}

/**
 * IDs of the active group sites, in ascending order.
 *
 * Ascending ID is the cursor order: a newly created site always lands after
 * every existing one, so it can't shift the pages of a pass in progress.
 *
 * @return int[]
 */
function get_group_site_ids(): array {
	$site_ids = get_sites(
		array(
			'network_id'   => GROUPS_NETWORK_ID,
			'site__not_in' => array( GROUPS_ROOT_BLOG_ID ),
			'archived'     => 0,
			'deleted'      => 0,
			'spam'         => 0,
			'number'       => 0,
			'orderby'      => 'id',
			'order'        => 'ASC',
			'fields'       => 'ids',
		)
	);

	return array_map( 'intval', $site_ids );
}

/**
 * Build one page of the feed.
 *
 * @param int[] $site_ids Active group site IDs, ascending.
 * @param int   $after    Return sites after this ID.
 * @param int   $now      Current Unix time.
 * @return array{events: array[], next: int|null, complete: bool}
 */
function build_page( array $site_ids, int $after, int $now ): array {
	$remaining = array_values(
		array_filter(
			$site_ids,
			static function ( int $site_id ) use ( $after ): bool {
				return $site_id > $after;
			}
		)
	);

	$page_sites = array_slice( $remaining, 0, SITES_PER_PAGE );
	$events     = array();
	$complete   = true;

	foreach ( $page_sites as $site_id ) {
		$site_events = collect_site_events( $site_id, $now );

		if ( is_wp_error( $site_events ) ) {
			$complete = false;

			Logger\log(
				'groups_events_feed_site_failed',
				array(
					'site_id' => $site_id,
					'error'   => $site_events->get_error_code(),
				)
			);
			continue;
		}

		$events = array_merge( $events, $site_events );
	}

	return array(
		'events'   => $events,
		'next'     => count( $remaining ) > SITES_PER_PAGE ? (int) end( $page_sites ) : null,
		'complete' => $complete,
	);
}

/**
 * The upcoming events of one group site, in feed form.
 *
 * Two indexed range queries — one-off events from GatherPress's dates table,
 * recurring occurrences from the recurring-events table — rather than a
 * `WP_Query` over every event the group has ever held. Venues and RSVP counts
 * are then fetched in one batch each for the whole site.
 *
 * Nothing is written: a site whose recurring-events tables were never
 * installed simply has no occurrences, rather than having them created by an
 * anonymous request.
 *
 * @param int $site_id Group site ID.
 * @param int $now     Current Unix time.
 * @return array[]|WP_Error Events, or an error when the site couldn't be read.
 */
function collect_site_events( int $site_id, int $now ) {
	$site = get_site( $site_id );

	if ( ! $site || $site->deleted || $site->archived || $site->spam || GROUPS_NETWORK_ID !== (int) $site->network_id ) {
		return new WP_Error( 'events_feed_site_unavailable', 'The site is not an active group site.' );
	}

	if ( ! class_exists( Event::class ) ) {
		return new WP_Error( 'events_feed_gatherpress_missing', 'GatherPress is not loaded.' );
	}

	$window_start = gmdate( 'Y-m-d H:i:s', $now - WINDOW_BEHIND );
	$window_end   = gmdate( 'Y-m-d H:i:s', $now + WINDOW_AHEAD );

	// The switch sits inside the try, as in `Network_Export\collect_site_rows()`:
	// a Throwable from a `switch_blog` listener must not leave the request on
	// the wrong blog.
	global $wp_rewrite;

	$had_permastruct = isset( $wp_rewrite->extra_permastructs[ Event::POST_TYPE ] );

	try {
		switch_to_blog( $site_id );

		/*
		 * `switch_to_blog()` doesn't reload the permalink structure, so without
		 * this every event URL would be built with the network root's. The root
		 * uses plain permalinks, which WordPress answers by never registering
		 * the event post type's permastruct at all, so it has to be added here
		 * too, or each URL comes out as `?gatherpress_event=<slug>`.
		 *
		 * `init()` only clears rule-generation state (extra rules, endpoints),
		 * which nothing later in this read-only request regenerates.
		 */
		$wp_rewrite->init();
		add_event_permastruct();

		$has_occurrences = class_exists( Recurring_Events_Database::class )
			&& Recurring_Events_Database::SCHEMA_VERSION === get_option( Recurring_Events_Database::OPTION_NAME );

		$rows = get_event_rows( $window_start, $window_end, $has_occurrences );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		if ( empty( $rows ) ) {
			return array();
		}

		$event_ids = array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'post_id' ) ) ) );

		// Only the post fields used below, primed in one query.
		_prime_post_caches( $event_ids, false, false );

		$counts = get_attending_counts( $event_ids, $has_occurrences );

		if ( is_wp_error( $counts ) ) {
			return $counts;
		}

		$venues = get_event_venues( $event_ids );

		if ( is_wp_error( $venues ) ) {
			return $venues;
		}

		$group = array(
			'name'     => decode( get_bloginfo( 'name' ) ),
			'url'      => home_url( '/' ),
			'location' => get_group_location( $site_id ),
		);

		$events = array();

		foreach ( $rows as $row ) {
			$event = format_event( $site_id, $row, $group, $venues, $counts );

			if ( $event ) {
				$events[] = $event;
			}
		}

		return $events;
	} catch ( Throwable $throwable ) {
		return new WP_Error( 'events_feed_site_threw', 'The site could not be read.' );
	} finally {
		restore_current_blog();
		$wp_rewrite->init();

		if ( ! $had_permastruct ) {
			$wp_rewrite->remove_permastruct( Event::POST_TYPE );
		}
	}
}

/**
 * Register the event post type's permastruct for the current (switched) site.
 *
 * Only the permastruct, which is all `get_permalink()` reads, rather than
 * `WP_Post_Type::add_rewrite_rules()`: on a plain-permalink request the post
 * type's rewrite args were never filled with their defaults, and that method
 * warns on the missing keys.
 */
function add_event_permastruct(): void {
	global $wp_rewrite;

	$post_type = get_post_type_object( Event::POST_TYPE );

	if ( ! $post_type || false === $post_type->rewrite ) {
		return;
	}

	$rewrite = is_array( $post_type->rewrite ) ? $post_type->rewrite : array();
	$slug    = $rewrite['slug'] ?? $post_type->name;

	$wp_rewrite->add_permastruct(
		$post_type->name,
		"{$slug}/%{$post_type->name}%",
		array( 'with_front' => $rewrite['with_front'] ?? true )
	);
}

/**
 * Upcoming event instances on the current site, soonest first.
 *
 * A series with projected occurrences is listed only through them, never
 * through its own GatherPress date (which is just the first occurrence's),
 * the same way the group's own archive shows it.
 *
 * @param string $window_start Earliest end time, GMT.
 * @param string $window_end   Latest start time, GMT.
 * @param bool   $has_occurrences Whether this site has the recurring-events tables.
 * @return object[]|WP_Error Rows with `post_id`, `recurrence_id`, `start_gmt`,
 *                           `end_gmt`, `timezone` and `status`.
 */
function get_event_rows( string $window_start, string $window_end, bool $has_occurrences ) {
	global $wpdb;

	$events_table = sprintf( Event::TABLE_FORMAT, $wpdb->prefix );
	$post_type    = Event::POST_TYPE;
	$not_a_series = '';

	if ( $has_occurrences ) {
		$occurrences_table = Recurring_Events_Database::occurrences_table();
		$not_a_series      = $wpdb->prepare( 'AND NOT EXISTS ( SELECT 1 FROM %i AS occurrences WHERE occurrences.series_post_id = posts.ID )', $occurrences_table );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `$not_a_series` is prepared above.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT dates.post_id, '' AS recurrence_id, dates.datetime_start_gmt AS start_gmt, dates.datetime_end_gmt AS end_gmt, dates.timezone, 'scheduled' AS status
			FROM %i AS dates
			INNER JOIN %i AS posts ON posts.ID = dates.post_id
			WHERE posts.post_type = %s
				AND posts.post_status = 'publish'
				AND posts.post_password = ''
				AND dates.datetime_end_gmt >= %s
				AND dates.datetime_start_gmt <= %s
				{$not_a_series}
			ORDER BY dates.datetime_start_gmt ASC
			LIMIT %d",
			$events_table,
			$wpdb->posts,
			$post_type,
			$window_start,
			$window_end,
			MAX_EVENTS_PER_SITE
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// A failed query must not read as "no events": the importer would then
	// delete every one of this group's events.
	if ( '' !== $wpdb->last_error ) {
		return new WP_Error( 'events_feed_read_failed', 'Could not read events.' );
	}

	$rows = (array) $rows;

	if ( $has_occurrences ) {
		$occurrences = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT occurrences.series_post_id AS post_id, occurrences.recurrence_id, occurrences.datetime_start_gmt AS start_gmt, occurrences.datetime_end_gmt AS end_gmt, occurrences.timezone, occurrences.status
				FROM %i AS occurrences
				INNER JOIN %i AS posts ON posts.ID = occurrences.series_post_id
				WHERE posts.post_type = %s
					AND posts.post_status = 'publish'
					AND posts.post_password = ''
					AND occurrences.status IN ( 'scheduled', 'cancelled' )
					AND occurrences.datetime_end_gmt >= %s
					AND occurrences.datetime_start_gmt <= %s
				ORDER BY occurrences.datetime_start_gmt ASC
				LIMIT %d",
				$occurrences_table,
				$wpdb->posts,
				$post_type,
				$window_start,
				$window_end,
				MAX_EVENTS_PER_SITE
			)
		);

		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'events_feed_occurrence_read_failed', 'Could not read occurrences.' );
		}

		$rows = array_merge( $rows, (array) $occurrences );

		usort(
			$rows,
			static function ( object $a, object $b ): int {
				return strcmp( $a->start_gmt, $b->start_gmt );
			}
		);

		$rows = array_slice( $rows, 0, MAX_EVENTS_PER_SITE );
	}

	return $rows;
}

/**
 * Attending RSVPs per event instance on the current site.
 *
 * One grouped query, as in `Network_Export\get_rsvp_counts()`, but only for
 * the `attending` status, and joining the recurring-events mapping table only
 * when this site has it. That function joins it whenever the plugin is
 * loaded, which fails on a site whose tables were never installed; it can
 * afford to, because the export installs them first, and this feed won't
 * write on an anonymous request.
 *
 * @param int[] $event_ids       Event post IDs.
 * @param bool  $has_occurrences Whether this site has the recurring-events tables.
 * @return array<int, array<string, array{attending: int}>>|WP_Error Counts keyed
 *         by event ID, then recurrence ID (empty for a one-off event).
 */
function get_attending_counts( array $event_ids, bool $has_occurrences ) {
	global $wpdb;

	$recurrence_expression = "''";
	$recurrence_join       = '';

	if ( $has_occurrences ) {
		$recurrence_expression = "COALESCE( occurrences.recurrence_id, '' )";
		$recurrence_join       = $wpdb->prepare( 'LEFT JOIN %i AS occurrences ON occurrences.comment_id = comments.comment_ID', Recurring_Events_Database::comments_table() );
	}

	$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only a prepared join, a fixed expression, and a locally generated `%d` list are interpolated.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT comments.comment_post_ID AS event_id, {$recurrence_expression} AS recurrence_id, COUNT(*) AS total
			FROM {$wpdb->comments} AS comments
			INNER JOIN {$wpdb->term_relationships} AS relationships ON relationships.object_id = comments.comment_ID
			INNER JOIN {$wpdb->term_taxonomy} AS taxonomy ON taxonomy.term_taxonomy_id = relationships.term_taxonomy_id
			INNER JOIN {$wpdb->terms} AS terms ON terms.term_id = taxonomy.term_id
			{$recurrence_join}
			WHERE comments.comment_post_ID IN ( {$placeholders} )
				AND comments.comment_type = 'gatherpress_rsvp'
				AND comments.comment_approved = '1'
				AND taxonomy.taxonomy = '_gatherpress_rsvp_status'
				AND terms.slug = 'attending'
			GROUP BY comments.comment_post_ID, {$recurrence_expression}",
			$event_ids
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	if ( '' !== $wpdb->last_error ) {
		return new WP_Error( 'events_feed_rsvp_count_failed', 'Could not count RSVPs.' );
	}

	$counts = array();

	foreach ( (array) $rows as $row ) {
		$counts[ (int) $row->event_id ][ (string) $row->recurrence_id ]['attending'] = (int) $row->total;
	}

	return $counts;
}

/**
 * Public venue details and the online flag of each event on the current site.
 *
 * Events carry their venue as a shadow term in `_gatherpress_venue`: the slug
 * is the venue post's `post_name` with a leading underscore, plus an
 * `online-event` sentinel with no post behind it. All the terms are read in
 * one query and all the venue posts, with their meta, in one more.
 *
 * @param int[] $event_ids Event post IDs.
 * @return array<int, array{online: bool, venue: array|null}>|WP_Error
 */
function get_event_venues( array $event_ids ) {
	$venue_setup = Venue_Setup::get_instance();
	$terms       = wp_get_object_terms(
		$event_ids,
		$venue_setup->taxonomy_for_event_post_type( Event::POST_TYPE ),
		array( 'fields' => 'all_with_object_id' )
	);

	if ( is_wp_error( $terms ) ) {
		return $terms;
	}

	$post_names = array();

	foreach ( $terms as $term ) {
		if ( $venue_setup->is_venue_term_slug( $term->slug ) ) {
			$post_names[] = ltrim( $term->slug, '_' );
		}
	}

	$venue_posts = array();

	if ( $post_names ) {
		$posts = get_posts(
			array(
				'post_type'        => 'gatherpress_venue',
				'post_status'      => 'publish',
				'post_name__in'    => array_values( array_unique( $post_names ) ),
				'posts_per_page'   => count( $post_names ),
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		foreach ( $posts as $venue_post ) {
			$venue_posts[ $venue_post->post_name ] = $venue_post;
		}
	}

	$venues = array();

	foreach ( $terms as $term ) {
		$event_id = (int) $term->object_id;

		$venues[ $event_id ] = $venues[ $event_id ] ?? array(
			'online' => false,
			'venue'  => null,
		);

		if ( $venue_setup->is_online_event_term_slug( $term->slug ) ) {
			$venues[ $event_id ]['online'] = true;
			continue;
		}

		if ( ! $venue_setup->is_venue_term_slug( $term->slug ) ) {
			continue;
		}

		$venue_post = $venue_posts[ ltrim( $term->slug, '_' ) ] ?? null;

		// A password-protected venue keeps everything about itself private,
		// as `Event::get_public_venue_information()` does for calendars.
		if ( ! $venue_post || '' !== $venue_post->post_password ) {
			continue;
		}

		$venues[ $event_id ]['venue'] = format_venue( $venue_post );
	}

	return $venues;
}

/**
 * The public, map-relevant details of a venue.
 *
 * @param \WP_Post $venue_post Venue post.
 * @return array{name: string, city: string, state: string, country_code: string, latitude: float|null, longitude: float|null}
 */
function format_venue( \WP_Post $venue_post ): array {
	$meta = static function ( string $field ) use ( $venue_post ): string {
		return trim( (string) get_post_meta( $venue_post->ID, 'gatherpress_' . $field, true ) );
	};

	$latitude  = $meta( 'latitude' );
	$longitude = $meta( 'longitude' );
	$has_point = is_numeric( $latitude ) && is_numeric( $longitude )
		&& abs( (float) $latitude ) <= 90 && abs( (float) $longitude ) <= 180
		// GatherPress stores nothing rather than 0,0 for an ungeocoded venue,
		// but a 0,0 from anywhere is never a real venue.
		&& ! ( 0.0 === (float) $latitude && 0.0 === (float) $longitude );

	return array(
		'name'         => decode( $venue_post->post_title ),
		'city'         => $meta( 'city' ),
		'state'        => $meta( 'state' ),
		'country_code' => strtoupper( $meta( 'country_code' ) ),
		'latitude'     => $has_point ? (float) $latitude : null,
		'longitude'    => $has_point ? (float) $longitude : null,
	);
}

/**
 * The group's own city and country, for events with no physical venue.
 *
 * @param int $site_id Group site ID.
 * @return array{city: string, country_code: string}|null
 */
function get_group_location( int $site_id ): ?array {
	if ( ! function_exists( 'WordCamp\Groups\Frontend\Group_Location\get_location' ) ) {
		return null;
	}

	$location = get_location( $site_id );

	if ( ! $location || 'physical' !== $location['type'] ) {
		return null;
	}

	return array(
		'city'         => (string) $location['city'],
		'country_code' => (string) $location['countryCode'],
	);
}

/**
 * Turn one event row into a feed entry.
 *
 * @param int    $site_id Group site ID.
 * @param object $row     Row from `get_event_rows()`.
 * @param array  $group   Group details.
 * @param array  $venues  From `get_event_venues()`.
 * @param array  $counts  From `Network_Export\get_rsvp_counts()`.
 * @return array|null The entry, or null when the row can't be represented.
 */
function format_event( int $site_id, object $row, array $group, array $venues, array $counts ): ?array {
	$event_id      = (int) $row->post_id;
	$recurrence_id = (string) $row->recurrence_id;
	$post          = get_post( $event_id );

	if ( ! $post ) {
		return null;
	}

	$source_id = get_source_id( $site_id, $event_id, $recurrence_id );

	if ( null === $source_id ) {
		Logger\log(
			'groups_events_feed_unrepresentable_event',
			array(
				'site_id'       => $site_id,
				'event_id'      => $event_id,
				'recurrence_id' => $recurrence_id,
			)
		);

		return null;
	}

	$timezone = get_timezone( (string) $row->timezone );
	$url      = '' === $recurrence_id
		? (string) get_permalink( $post )
		: Context::occurrence_url( $event_id, $recurrence_id );

	return array(
		'source_id'   => $source_id,
		'status'      => 'cancelled' === $row->status ? 'cancelled' : 'scheduled',
		'title'       => decode( $post->post_title ),
		'url'         => $url,
		'description' => get_description( $post ),
		'start_gmt'   => (string) $row->start_gmt,
		'end_gmt'     => (string) $row->end_gmt,
		'timezone'    => $timezone->getName(),
		'utc_offset'  => $timezone->getOffset( new DateTimeImmutable( $row->start_gmt, new DateTimeZone( 'UTC' ) ) ),
		'online'      => $venues[ $event_id ]['online'] ?? false,
		'venue'       => $venues[ $event_id ]['venue'] ?? null,
		'attending'   => (int) ( $counts[ $event_id ][ $recurrence_id ]['attending'] ?? 0 ),
		'group'       => $group,
	);
}

/**
 * A stable ID for one event instance, unique across the whole network.
 *
 * `gp-{site}-{post}` for a one-off event, with `-{recurrence_id}` appended for
 * an occurrence. Site and post IDs are base36 so the longest recurrence ID
 * (15 characters) still fits the importer's 32-character column. It doesn't
 * depend on the event's URL, which changes whenever its slug does.
 *
 * @param int    $site_id       Group site ID.
 * @param int    $event_id      Event post ID.
 * @param string $recurrence_id Occurrence, or empty for a one-off event.
 * @return string|null Null when the ID would be malformed or too long.
 */
function get_source_id( int $site_id, int $event_id, string $recurrence_id ): ?string {
	if ( '' !== $recurrence_id && ! preg_match( '/^[0-9]{8}(?:T[0-9]{6})?$/', $recurrence_id ) ) {
		return null;
	}

	$source_id = 'gp-' . base_convert( (string) $site_id, 10, 36 ) . '-' . base_convert( (string) $event_id, 10, 36 );

	if ( '' !== $recurrence_id ) {
		$source_id .= '-' . $recurrence_id;
	}

	return strlen( $source_id ) <= SOURCE_ID_MAX_LENGTH ? $source_id : null;
}

/**
 * Resolve a stored timezone, in whatever spelling GatherPress kept it.
 *
 * @param string $timezone Stored timezone.
 * @return DateTimeZone
 */
function get_timezone( string $timezone ): DateTimeZone {
	if ( class_exists( Occurrences::class ) ) {
		return Occurrences::timezone( $timezone );
	}

	try {
		return new DateTimeZone( '' !== $timezone ? $timezone : wp_timezone_string() );
	} catch ( Exception $exception ) {
		return wp_timezone();
	}
}

/**
 * A short plain-text description of an event.
 *
 * Built from the stored content without rendering any blocks: rendering would
 * run every block's render callback for an anonymous request, and the feed
 * only needs the words.
 *
 * @param \WP_Post $post Event post.
 * @return string
 */
function get_description( \WP_Post $post ): string {
	$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
	$text = preg_replace( '/<!--.*?-->/s', '', $text );
	$text = wp_strip_all_tags( strip_shortcodes( (string) $text ), true );

	return decode( wp_trim_words( $text, DESCRIPTION_WORDS, '…' ) );
}

/**
 * Decode entities in stored text. This is JSON for another program, not HTML.
 *
 * @param string $text Stored text.
 * @return string
 */
function decode( string $text ): string {
	return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}
