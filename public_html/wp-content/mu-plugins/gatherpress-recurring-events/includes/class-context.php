<?php
/**
 * Occurrence request routing and date context.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Rsvp\Cache;
use WP_HTML_Tag_Processor;

defined( 'WPINC' ) || die();

final class Context {

	private static ?object $occurrence = null;

	/** Registers occurrence permalink rewriting. */
	public static function register(): void {
		add_rewrite_rule(
			'^event/([^/]+)/([0-9]{8}(?:T[0-9]{6})?)/?$',
			'index.php?gatherpress_event=$matches[1]&gpre_occurrence=$matches[2]',
			'top'
		);
	}

	/**
	 * Allows the occurrence query variable.
	 *
	 * @param string[] $query_vars Public query variables.
	 * @return string[] Filtered variables.
	 */
	public static function query_vars( array $query_vars ): array {
		$query_vars[] = 'gpre_occurrence';
		return $query_vars;
	}

	/** Resolves occurrence context for a singular event request. */
	public static function resolve(): void {
		if ( ! is_singular( 'gatherpress_event' ) ) {
			return;
		}

		$post_id       = get_queried_object_id();
		$recurrence_id = (string) get_query_var( 'gpre_occurrence' );

		if ( $recurrence_id ) {
			$occurrence = Occurrences::get( $post_id, $recurrence_id );
			if ( ! $occurrence ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
				return;
			}
			self::set( $occurrence );
		} elseif ( Rule::is_recurring( $post_id ) ) {
			self::set( Occurrences::select_for_series( $post_id ) );
		}
	}

	/**
	 * Sets request-scoped occurrence context.
	 *
	 * @param object|null $occurrence Occurrence row.
	 */
	public static function set( ?object $occurrence ): void {
		self::$occurrence = $occurrence;
		if ( $occurrence ) {
			Cache::delete( (int) $occurrence->series_post_id );
		}
	}

	/** Gets the active occurrence row. */
	public static function get(): ?object {
		return self::$occurrence;
	}

	/** Gets the active recurrence identifier. */
	public static function recurrence_id(): string {
		return self::$occurrence ? (string) self::$occurrence->recurrence_id : '';
	}

	/**
	 * Overrides GatherPress date metadata for the active occurrence.
	 *
	 * @param mixed  $value     Existing short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Metadata key.
	 * @param bool   $single    Whether one value was requested.
	 * @return mixed Occurrence value or existing value.
	 */
	public static function metadata( $value, int $object_id, string $meta_key, bool $single ) {
		$occurrence = self::$occurrence;
		if ( ! $occurrence || (int) $occurrence->series_post_id !== $object_id ) {
			return $value;
		}

		$values = array(
			'gatherpress_datetime_start'     => $occurrence->datetime_start,
			'gatherpress_datetime_start_gmt' => $occurrence->datetime_start_gmt,
			'gatherpress_datetime_end'       => $occurrence->datetime_end,
			'gatherpress_datetime_end_gmt'   => $occurrence->datetime_end_gmt,
			'gatherpress_timezone'           => $occurrence->timezone,
		);

		if ( ! array_key_exists( $meta_key, $values ) ) {
			return $value;
		}

		return $single ? $values[ $meta_key ] : array( $values[ $meta_key ] );
	}

	/**
	 * Builds a canonical occurrence URL.
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Recurrence identifier.
	 * @return string Occurrence URL.
	 */
	public static function occurrence_url( int $post_id, string $recurrence_id ): string {
		remove_filter( 'post_type_link', array( self::class, 'post_link' ), 10 );
		$series_url = get_permalink( $post_id );
		add_filter( 'post_type_link', array( self::class, 'post_link' ), 10, 2 );

		return trailingslashit( $series_url ) . $recurrence_id . '/';
	}

	/**
	 * Uses an occurrence permalink while rendering a canonical occurrence.
	 *
	 * @param string $permalink Existing permalink.
	 * @param object $post      Post object.
	 * @return string Filtered permalink.
	 */
	public static function post_link( string $permalink, $post ): string {
		if ( self::$occurrence && (int) self::$occurrence->series_post_id === (int) $post->ID && ( get_query_var( 'gpre_occurrence' ) || ! is_singular() ) ) {
			return self::occurrence_url( (int) $post->ID, self::recurrence_id() );
		}

		return $permalink;
	}

	/**
	 * Prepends the date selector to singular event content.
	 *
	 * @param string $content Event content.
	 * @return string Filtered content.
	 */
	public static function prepend_selector( string $content ): string {
		if ( ! is_singular( 'gatherpress_event' ) || ! in_the_loop() || ! is_main_query() || ! self::$occurrence ) {
			return $content;
		}

		return self::selector( (int) self::$occurrence->series_post_id ) . $content;
	}

	/**
	 * Prevents canonical redirects from hiding invalid occurrence 404s.
	 *
	 * @param string|false $redirect_url Proposed canonical URL.
	 * @return string|false Filtered canonical URL.
	 */
	public static function canonical_redirect( $redirect_url ) {
		if ( ! get_query_var( 'gpre_occurrence' ) ) {
			return $redirect_url;
		}

		if ( $redirect_url && self::$occurrence ) {
			return self::occurrence_url( (int) self::$occurrence->series_post_id, self::recurrence_id() );
		}

		return false;
	}

	/**
	 * Renders the occurrence selector.
	 *
	 * @param int $post_id Series post ID.
	 * @return string Selector markup.
	 */
	public static function selector( int $post_id ): string {
		$current     = self::recurrence_id();
		$occurrences = Occurrences::around( $post_id, 6 );
		$items       = '';

		if ( ! in_array( $current, wp_list_pluck( $occurrences, 'recurrence_id' ), true ) ) {
			$occurrences[] = self::$occurrence;
			usort( $occurrences, static fn( object $first, object $second ): int => strcmp( $first->datetime_start_gmt, $second->datetime_start_gmt ) );
		}

		foreach ( $occurrences as $occurrence ) {
			$timezone = new DateTimeZone( $occurrence->timezone );
			$date     = new DateTimeImmutable( $occurrence->datetime_start, $timezone );
			$label    = wp_date( 'M j @ g:i A T', $date->getTimestamp(), $timezone );
			if ( 'cancelled' === $occurrence->status ) {
				$label .= ' — ' . __( 'Canceled', 'wordcamporg' );
			}

			$items .= sprintf(
				'<li><a href="%1$s"%2$s class="gpre-occurrence-link%3$s">%4$s</a></li>',
				esc_url( self::occurrence_url( $post_id, $occurrence->recurrence_id ) ),
				$current === $occurrence->recurrence_id ? ' aria-current="date"' : '',
				'cancelled' === $occurrence->status ? ' is-cancelled' : '',
				esc_html( $label )
			);
		}

		$notice = '';
		if ( 'cancelled' === self::$occurrence->status ) {
			$notice = '<p class="gpre-cancelled-notice" role="status">' . esc_html__( 'This occurrence has been canceled.', 'wordcamporg' ) . '</p>';
		} elseif ( self::$occurrence->datetime_end_gmt < current_time( 'mysql', true ) ) {
			$notice = '<p class="gpre-series-ended" role="status">' . esc_html__( 'This event series has ended.', 'wordcamporg' ) . '</p>';
		}

		return sprintf(
			'<nav class="gpre-occurrence-selector" aria-label="%1$s">' .
			'<button class="gpre-occurrence-selector__control is-previous" type="button" aria-label="%2$s">' .
			'<span aria-hidden="true">‹</span></button>' .
			'<ul>%3$s</ul>' .
			'<button class="gpre-occurrence-selector__control is-next" type="button" aria-label="%4$s">' .
			'<span aria-hidden="true">›</span></button>' .
			'</nav>%5$s',
			esc_attr__( 'Event dates', 'wordcamporg' ),
			esc_attr__( 'Previous event dates', 'wordcamporg' ),
			$items,
			esc_attr__( 'Next event dates', 'wordcamporg' ),
			$notice
		);
	}

	/**
	 * Makes the Groups RSVP block occurrence-aware.
	 *
	 * @param string $content Rendered block.
	 * @return string Filtered block.
	 */
	public static function render_rsvp_block( string $content ): string {
		if ( ! self::$occurrence ) {
			return $content;
		}

		if ( 'cancelled' === self::$occurrence->status ) {
			return '<p class="gpre-rsvp-closed">' . esc_html__( 'RSVP is closed for this canceled occurrence.', 'wordcamporg' ) . '</p>';
		}

		$tag = new WP_HTML_Tag_Processor( $content );
		if ( $tag->next_tag() ) {
			$context = json_decode( (string) $tag->get_attribute( 'data-wp-context' ), true );
			if ( is_array( $context ) ) {
				$context['apiBase']      = rest_url( 'gpre/v1/event/' . self::recurrence_id() );
				$context['loginUrl']     = wp_login_url( self::occurrence_url( (int) self::$occurrence->series_post_id, self::recurrence_id() ) );
				$context['recurrenceId'] = self::recurrence_id();
				$tag->set_attribute( 'data-wp-context', wp_json_encode( $context ) );
				return $tag->get_updated_html();
			}
		}

		return $content;
	}
}
