<?php

namespace WordCamp\Schedule_Meta;

defined( 'WPINC' ) || die();

/*
 * Mirror each WordCamp's schedule onto its site's blogmeta, so code that runs too early to read the central
 * `wordcamp` posts can still tell which edition is current. In particular `sunrise.php` runs before the
 * `wordcamp` post type is registered, so it can't query post meta -- but blogmeta is queryable that early.
 *
 * The keys below are written here and read by `WordCamp\Sunrise\get_current_edition_site()` (canonical
 * redirect) and `WordCamp\Latest_Site_Hints\get_latest_home_url()` (next-edition banner). Keep the key names
 * in sync with those readers.
 *
 *   _wc_event_start  Start Date as a Unix timestamp. Present only for live, scheduled editions (LIVE_STATUSES).
 *   _wc_event_end    End Date (falls back to the Start Date) as a Unix timestamp. Always written alongside
 *                    the start, so a single key tells readers when an edition is over.
 *   _wc_event_status The `wordcamp` post status. Stored for observability/flexibility; the readers key off
 *                    the dates above, not this.
 */

/**
 * Statuses for editions that are actually happening, whose schedule should be advertised to visitors.
 *
 * These are the public WordCamp statuses (mirrors `WordCamp_Loader::get_public_post_statuses()`). Other
 * statuses are excluded even when a Start/End date is present on the post: pre-planning camps (e.g.
 * `wcpt-needs-schedule`) often have tentative dates saved by `Event_Admin::metabox_save()` but aren't on the
 * official schedule yet, and cancelled/rejected camps keep their dates too. Using an allowlist of the public
 * statuses is the authoritative "this edition is real" signal.
 */
const LIVE_STATUSES = array( 'wcpt-scheduled', 'wcpt-closed' );

// Priority 20 runs after `Event_Admin::metabox_save()` (priority 10) has written the date meta and, for new
// camps, after `maybe_create_new_sites()` has set `_site_id` -- so both are available here. `save_post` fires
// on every edit path (normal, quick/bulk edit, the auto-close cron, programmatic updates), so this stays in
// sync without a separate `transition_post_status` hook.
add_action( 'save_post_wordcamp', __NAMESPACE__ . '\sync_on_save', 20, 2 );

/**
 * Re-derive the schedule blogmeta when a `wordcamp` post is saved.
 *
 * @param int      $post_id
 * @param \WP_Post $post
 */
function sync_on_save( $post_id, $post ) {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
		return;
	}

	sync_wordcamp_schedule_meta( $post_id );
}

/**
 * Mirror a `wordcamp` post's schedule onto the blogmeta of each site it's linked to.
 *
 * Idempotent: it fully re-derives the meta from the post on every call, so an edition that later leaves the
 * schedule (cancelled, or moved back to pre-planning) or has its dates cleared gets its date keys removed.
 * The date keys are written only for editions that are actually happening -- a Start Date must be set and the
 * post must be in a public status (see LIVE_STATUSES) -- so downstream "has a start date" reliably means
 * "live, scheduled edition".
 *
 * Must be called on the central blog, where `wordcamp` posts live. `update_site_meta()` writes to the global
 * `wp_blogmeta` table keyed by the passed site ID, so no `switch_to_blog()` to the event site is needed.
 *
 * @param int $post_id A `wordcamp` post ID.
 */
function sync_wordcamp_schedule_meta( $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post || 'wordcamp' !== $post->post_type ) {
		return;
	}

	$primary_site   = (array) get_post_meta( $post_id, '_site_id', true );
	$secondary_site = (array) get_post_meta( $post_id, '_secondary_site_id', false );
	$site_ids       = array_filter( array_map( 'absint', array_merge( $primary_site, $secondary_site ) ) );

	if ( ! $site_ids ) {
		return; // No site linked yet (e.g. an application that hasn't had its site created).
	}

	$start_date = absint( get_post_meta( $post_id, 'Start Date (YYYY-mm-dd)', true ) );
	$end_date   = absint( get_post_meta( $post_id, 'End Date (YYYY-mm-dd)', true ) );
	$is_live    = $start_date && in_array( $post->post_status, LIVE_STATUSES, true );

	foreach ( $site_ids as $site_id ) {
		update_site_meta( $site_id, '_wc_event_status', $post->post_status );

		if ( $is_live ) {
			update_site_meta( $site_id, '_wc_event_start', $start_date );
			update_site_meta( $site_id, '_wc_event_end', $end_date ?: $start_date );
		} else {
			delete_site_meta( $site_id, '_wc_event_start' );
			delete_site_meta( $site_id, '_wc_event_end' );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Backfill the schedule blogmeta for every existing WordCamp.
	 *
	 * Run once after deploying this feature; `sync_on_save()` keeps it current thereafter.
	 *
	 *     wp wordcamp-schedule-meta backfill
	 */
	\WP_CLI::add_command(
		'wordcamp-schedule-meta backfill',
		function () {
			switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

			$post_ids = get_posts( array(
				'post_type'      => 'wordcamp',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );

			foreach ( $post_ids as $post_id ) {
				sync_wordcamp_schedule_meta( $post_id );
			}

			restore_current_blog();

			\WP_CLI::success( sprintf( 'Synced schedule meta for %d WordCamps.', count( $post_ids ) ) );
		}
	);
}
