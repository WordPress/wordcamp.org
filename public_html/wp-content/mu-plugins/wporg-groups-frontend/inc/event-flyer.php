<?php
/**
 * A printable flyer for an event (#2133).
 *
 * Organizers asked for something to pin up at a venue, library or coworking
 * space. Every event gets a `…/flyer/` page: one sheet with the title, date,
 * place, group and a QR code back to the event, laid out by the theme's
 * `single-event-flyer` template.
 *
 * It hangs off the event's own URL rather than a query string so a dated
 * occurrence of a recurring series gets its own flyer, `…/{date}/flyer/`,
 * with that date on it and a QR code to that date's page.
 *
 * The flyer prints a short URL rather than the event's permalink (#2222):
 * `…/e/{ID}/`, or `…/e/{ID}/{Ymd}/` for a date of a series, which redirects
 * to the event. A permalink carries the event's slug, which is too long to
 * type off a sheet of paper. A wp.me link can't name an occurrence, so a
 * series' flyer would open on whichever date the series page picks.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Event_Flyer;

use GatherPress\Core\Event\Event;
use WordPressdotorg\GatherPress_Recurring_Events\Context;
use WordPressdotorg\GatherPress_Recurring_Events\Database as Recurring_Events_Database;
use WordPressdotorg\GatherPress_Recurring_Events\Occurrences;
use WP;
use WP_Post;

defined( 'WPINC' ) || die();

/**
 * Query var a flyer request carries, and the URL segment that sets it.
 */
const QUERY_VAR = 'wporg_event_flyer';
const ENDPOINT  = 'flyer';

/**
 * Query vars a short URL carries, and the URL segment it lives under.
 */
const SHORT_QUERY_VAR      = 'wporg_event_short';
const SHORT_DATE_QUERY_VAR = 'wporg_event_short_date';
const SHORT_BASE           = 'e';

/** Registers the flyer route and what the page needs around it. */
function bootstrap(): void {
	// After GatherPress has registered the event post type, whose rewrite
	// slug the rules are built from.
	add_action( 'init', __NAMESPACE__ . '\register_rewrite_rules', 20 );
	add_filter( 'query_vars', __NAMESPACE__ . '\add_query_var' );
	add_action( 'parse_request', __NAMESPACE__ . '\redirect_short_url' );
	// Ahead of core's `_wp_admin_bar_init` (priority 0), which queues the
	// toolbar's 32px page offset if the toolbar is still on at that point.
	add_action( 'template_redirect', __NAMESPACE__ . '\prepare_request', -1 );

	// After the recurring-events extension's own filter, which would send an
	// occurrence's flyer back to the occurrence's event page.
	add_filter( 'redirect_canonical', __NAMESPACE__ . '\keep_flyer_url', 20 );
}

/**
 * The rewrite rules for a flyer, keyed by pattern.
 *
 * Built from the event post type's own rewrite slug, which GatherPress
 * localizes, so a site whose events live under `/evento/` gets
 * `/evento/{slug}/flyer/`. The dated rule matches the occurrence URLs the
 * recurring-events extension routes, `…/{slug}/{Ymd}/` with an optional
 * `T{His}`.
 *
 * Explicit rules rather than `add_rewrite_endpoint()`: an endpoint would also
 * attach `/flyer/` to every news post, and it can't express the dated form.
 *
 * @return array<string, string>
 */
function get_rewrite_rules(): array {
	$post_type = get_post_type_object( Event::POST_TYPE );

	if ( ! $post_type || empty( $post_type->rewrite['slug'] ) ) {
		return array();
	}

	$base = preg_quote( $post_type->rewrite['slug'], '/' );

	return array(
		'^' . $base . '/([^/]+)/([0-9]{8}(?:T[0-9]{6})?)/' . ENDPOINT . '/?$' =>
			'index.php?' . Event::POST_TYPE . '=$matches[1]&gpre_occurrence=$matches[2]&' . QUERY_VAR . '=1',
		'^' . $base . '/([^/]+)/' . ENDPOINT . '/?$'                           =>
			'index.php?' . Event::POST_TYPE . '=$matches[1]&' . QUERY_VAR . '=1',
	);
}

/**
 * The rewrite rules for a short URL, keyed by pattern.
 *
 * The date takes the same form as an occurrence URL's, though the flyer only
 * ever prints the `Ymd` part: a series repeats weekly at most, so a day
 * names one date.
 *
 * @return array<string, string>
 */
function get_short_url_rewrite_rules(): array {
	return array(
		'^' . SHORT_BASE . '/([0-9]+)/([0-9]{8}(?:T[0-9]{6})?)/?$' =>
			'index.php?' . SHORT_QUERY_VAR . '=$matches[1]&' . SHORT_DATE_QUERY_VAR . '=$matches[2]',
		'^' . SHORT_BASE . '/([0-9]+)/?$'                          =>
			'index.php?' . SHORT_QUERY_VAR . '=$matches[1]',
	);
}

/**
 * Adds the flyer rewrite rules, flushing the stored rules when they're missing.
 *
 * Follows GatherPress's calendar endpoints (`Calendar\Endpoint`): dropping the
 * `rewrite_rules` option makes WordPress rebuild it on the next request, so a
 * deploy needs no manual flush on each group's site and a site that already
 * has the rules pays for nothing more than one option read.
 */
function register_rewrite_rules(): void {
	$stored = get_option( 'rewrite_rules' );

	foreach ( array_merge( get_rewrite_rules(), get_short_url_rewrite_rules() ) as $pattern => $query ) {
		add_rewrite_rule( $pattern, $query, 'top' );

		if ( is_array( $stored ) && ( $stored[ $pattern ] ?? null ) !== $query ) {
			delete_option( 'rewrite_rules' );
			$stored = null;
		}
	}
}

/**
 * Makes the flyer query var public.
 *
 * @param string[] $query_vars Public query variables.
 * @return string[]
 */
function add_query_var( array $query_vars ): array {
	$query_vars[] = QUERY_VAR;
	$query_vars[] = SHORT_QUERY_VAR;
	$query_vars[] = SHORT_DATE_QUERY_VAR;

	return $query_vars;
}

/**
 * Whether the current request is for an event flyer.
 */
function is_flyer_request(): bool {
	return '' !== (string) get_query_var( QUERY_VAR );
}

/**
 * The flyer URL for an event.
 *
 * On a dated occurrence's page `get_permalink()` already returns that
 * occurrence's URL (the recurring-events extension filters it), so the flyer
 * link there points at the same date.
 *
 * @param int $event_id Event post ID.
 */
function get_flyer_url( int $event_id ): string {
	return trailingslashit( (string) get_permalink( $event_id ) ) . ENDPOINT . '/';
}

/**
 * The short URL for an event, dated when its permalink is.
 *
 * Follows `get_permalink()` rather than the request: where the permalink is
 * an occurrence's URL the short URL names that date too, and a series' own
 * undated flyer gets an undated short URL, like its permalink.
 *
 * @param int $event_id Event post ID.
 */
function get_short_url( int $event_id ): string {
	$url = home_url( SHORT_BASE . '/' . $event_id . '/' );

	if ( ! class_exists( Context::class ) || '' === Context::recurrence_id() ) {
		return $url;
	}

	$recurrence_id = Context::recurrence_id();

	if ( trailingslashit( (string) get_permalink( $event_id ) ) !== Context::occurrence_url( $event_id, $recurrence_id ) ) {
		return $url;
	}

	return $url . substr( $recurrence_id, 0, 8 ) . '/';
}

/**
 * Where a short URL leads: the event's permalink, or a date's page.
 *
 * Empty for anything that isn't an event the visitor can read, so an ID
 * can't be walked to find the slugs of drafts, and for a date the series
 * doesn't have.
 *
 * @param int    $event_id Event post ID.
 * @param string $date     `Ymd`, a full recurrence ID, or empty.
 */
function get_short_url_target( int $event_id, string $date = '' ): string {
	$post = get_post( $event_id );

	if ( ! $post instanceof WP_Post || Event::POST_TYPE !== $post->post_type ) {
		return '';
	}

	if ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'read_post', $post->ID ) ) {
		return '';
	}

	if ( '' === $date ) {
		return (string) get_permalink( $post );
	}

	if ( ! class_exists( Occurrences::class ) ) {
		return '';
	}

	$occurrence = Occurrences::get( $post->ID, $date ) ?? find_occurrence_on( $post->ID, $date );

	return $occurrence ? Context::occurrence_url( $post->ID, (string) $occurrence->recurrence_id ) : '';
}

/**
 * The occurrence of a series on a given day.
 *
 * An occurrence's ID carries its start time (`Ymd\THis`) unless it's all
 * day, and the flyer prints only the day.
 *
 * @param int    $event_id Series post ID.
 * @param string $date     `Ymd`.
 */
function find_occurrence_on( int $event_id, string $date ): ?object {
	global $wpdb;

	if ( ! preg_match( '/^[0-9]{8}$/', $date ) ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM %i WHERE series_post_id = %d AND recurrence_id LIKE %s ORDER BY recurrence_id LIMIT 1',
			Recurring_Events_Database::occurrences_table(),
			$event_id,
			$wpdb->esc_like( $date . 'T' ) . '%'
		)
	);

	return is_object( $row ) ? $row : null;
}

/**
 * Sends a short URL on to the event, or 404s it.
 *
 * Runs once the request is parsed, before the main query, which a short URL
 * has no use for.
 *
 * @param WP $wp Current WordPress environment.
 */
function redirect_short_url( WP $wp ): void {
	if ( empty( $wp->query_vars[ SHORT_QUERY_VAR ] ) ) {
		return;
	}

	$target = get_short_url_target(
		absint( $wp->query_vars[ SHORT_QUERY_VAR ] ),
		(string) ( $wp->query_vars[ SHORT_DATE_QUERY_VAR ] ?? '' )
	);

	if ( '' === $target ) {
		$wp->query_vars = array( 'error' => '404' );
		return;
	}

	wp_safe_redirect( $target, 301 );
	exit;
}

/**
 * Sets up a flyer request, or turns it into a 404 when it isn't one.
 *
 * The rewrite rules only ever pair the query var with an event, but it's a
 * public query var, so `?wporg_event_flyer=1` can ride on any URL. Anything
 * that isn't a single event 404s rather than quietly rendering as itself.
 *
 * Drafts and private events need nothing here: WordPress already 404s a
 * singular request for a post the visitor can't read, and the flyer is that
 * same request.
 */
function prepare_request(): void {
	if ( ! is_flyer_request() ) {
		return;
	}

	if ( ! is_singular( Event::POST_TYPE ) ) {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		return;
	}

	// A copy of the event page made for paper. The event page is what
	// should be found.
	add_filter( 'wp_robots', 'wp_robots_no_robots' );

	// It prints as it's shown, so keep the toolbar off it.
	add_filter( 'show_admin_bar', '__return_false' );

	// The recurring-events extension puts a series' date picker at the top
	// of its content, and the excerpt is built from that content, so the
	// flyer would print every date in the series as its description. The
	// flyer is for one date, already shown, and has no use for the picker.
	remove_filter( 'the_content', array( 'WordPressdotorg\\GatherPress_Recurring_Events\\Context', 'prepend_selector' ), 3 );
}

/**
 * Keeps a flyer on its own URL.
 *
 * @param string|false $redirect_url Proposed canonical URL.
 * @return string|false
 */
function keep_flyer_url( $redirect_url ) {
	return is_flyer_request() ? false : $redirect_url;
}
