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
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Event_Flyer;

use GatherPress\Core\Event\Event;

defined( 'WPINC' ) || die();

/**
 * Query var a flyer request carries, and the URL segment that sets it.
 */
const QUERY_VAR = 'wporg_event_flyer';
const ENDPOINT  = 'flyer';

/** Registers the flyer route and what the page needs around it. */
function bootstrap(): void {
	// After GatherPress has registered the event post type, whose rewrite
	// slug the rules are built from.
	add_action( 'init', __NAMESPACE__ . '\register_rewrite_rules', 20 );
	add_filter( 'query_vars', __NAMESPACE__ . '\add_query_var' );
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
 * Adds the flyer rewrite rules, flushing the stored rules when they're missing.
 *
 * Follows GatherPress's calendar endpoints (`Calendar\Endpoint`): dropping the
 * `rewrite_rules` option makes WordPress rebuild it on the next request, so a
 * deploy needs no manual flush on each group's site and a site that already
 * has the rules pays for nothing more than one option read.
 */
function register_rewrite_rules(): void {
	$stored = get_option( 'rewrite_rules' );

	foreach ( get_rewrite_rules() as $pattern => $query ) {
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
