<?php
/**
 * Guards GatherPress's series-wide RSVP cache against unscoped rosters.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use GatherPress\Core\Rsvp\Cache;

defined( 'WPINC' ) || die();

/**
 * GatherPress caches an event's RSVP roster in a transient keyed by post ID
 * alone. On a recurring series every date shares that one key, so whoever
 * computes a roster last decides what every other date is served until the
 * entry expires or is deleted.
 *
 * `Context::set()` deletes the entry, which keeps occurrence page renders
 * honest: each one busts the cache before recomputing its own date. What it
 * cannot cover is a read that has no occurrence at all -- a REST request that
 * arrived without one, cron, a notification email. Those compute the whole
 * series' roster and then store it, and the next visitor to any date is served
 * that. This is the bleed behind #2072: the count came from the correctly
 * scoped server render while the list came from the shared entry.
 *
 * So while a recurring series has no occurrence in view, its cache entry is
 * neither served nor written. An unscoped read still answers (unscoped, as it
 * did before), it just cannot leave its answer behind for a scoped reader to
 * pick up.
 */
final class Rsvp_Cache {

	/**
	 * Series whose cache filters are already registered.
	 *
	 * @var array<int, true>
	 */
	private static array $guarded = array();

	/**
	 * Guards a series' RSVP cache entry for the rest of the request.
	 *
	 * Safe to call repeatedly and from anywhere: the filters register once per
	 * series, and each one re-reads the occurrence context when it fires, so a
	 * request that establishes context after this call is unaffected.
	 *
	 * @param int $post_id Event post ID.
	 */
	public static function guard( int $post_id ): void {
		if ( isset( self::$guarded[ $post_id ] ) || ! Rule::is_recurring( $post_id ) ) {
			return;
		}

		self::$guarded[ $post_id ] = true;

		$transient = sprintf( Cache::CACHE_KEY, $post_id );

		// An empty array rather than `false`: `false` means "no short-circuit"
		// to `get_transient()`, and `Cache::get()` reads an empty array as a
		// miss, which is exactly what an unscoped reader should see.
		add_filter(
			"pre_transient_{$transient}",
			static fn( $value ) => self::is_unscoped( $post_id ) ? array() : $value
		);

		// Same value on the way in, so an unscoped roster cannot be stored.
		// This clears any scoped entry that was there, which is the safe
		// direction: the next reader recomputes for its own date.
		add_filter(
			"pre_set_transient_{$transient}",
			static fn( $value ) => self::is_unscoped( $post_id ) ? array() : $value
		);
	}

	/**
	 * Whether a series is being read without one of its dates in view.
	 *
	 * @param int $post_id Event post ID.
	 * @return bool Whether the active occurrence belongs to this series.
	 */
	private static function is_unscoped( int $post_id ): bool {
		$occurrence = Context::get();

		return ! $occurrence || (int) $occurrence->series_post_id !== $post_id;
	}

	/** Forgets which series have been guarded. Test seam. */
	public static function reset(): void {
		self::$guarded = array();
	}
}
