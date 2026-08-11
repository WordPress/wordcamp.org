<?php
/**
 * Occurrence-scoped comments and RSVPs.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use GatherPress\Core\Rsvp\Cache;
use WP_Comment;
use WP_Comment_Query;

defined( 'WPINC' ) || die();

final class Comments {

	/**
	 * Adds occurrence identity to comment-query cache keys.
	 *
	 * @param WP_Comment_Query $query Comment query.
	 */
	public static function prepare_query( WP_Comment_Query $query ): void {
		$occurrence = Context::get();
		if ( $occurrence && self::targets_series( $query, $occurrence ) ) {
			$query->query_vars['gpre_occurrence'] = $occurrence->recurrence_id;
			$query->query_vars['cache_domain']    = 'gpre-' . $occurrence->recurrence_id;
		}
	}

	/**
	 * Restricts a comment query to the active occurrence.
	 *
	 * @param array            $clauses SQL clauses.
	 * @param WP_Comment_Query $query   Comment query.
	 * @return array Filtered SQL clauses.
	 */
	public static function clauses( array $clauses, WP_Comment_Query $query ): array {
		$occurrence = Context::get();
		if ( ! $occurrence || ! self::targets_series( $query, $occurrence ) || empty( $query->query_vars['gpre_occurrence'] ) ) {
			return $clauses;
		}

		global $wpdb;
		$table             = Database::comments_table();
		$clauses['join']  .= $wpdb->prepare(
			' INNER JOIN %i gpre_oc ON gpre_oc.comment_id = ' . $wpdb->comments . '.comment_ID',
			$table
		);
		$clauses['where'] .= $wpdb->prepare(
			' AND gpre_oc.series_post_id = %d AND gpre_oc.recurrence_id = %s',
			$occurrence->series_post_id,
			$occurrence->recurrence_id
		);

		return $clauses;
	}

	/**
	 * Checks whether a comment query explicitly targets the active series.
	 *
	 * @param WP_Comment_Query $query      Comment query.
	 * @param object           $occurrence Active occurrence row.
	 * @return bool Whether the query targets the occurrence's series post.
	 */
	private static function targets_series( WP_Comment_Query $query, object $occurrence ): bool {
		return (int) ( $query->query_vars['post_id'] ?? 0 ) === (int) $occurrence->series_post_id;
	}

	/** Prints occurrence identity into the standard comment form. */
	public static function hidden_field(): void {
		if ( Context::get() ) {
			printf(
				'<input type="hidden" name="gpre_occurrence" value="%s">',
				esc_attr( Context::recurrence_id() )
			);
		}
	}

	/**
	 * Adds occurrence identity to a GatherPress RSVP form.
	 *
	 * @param string $content Rendered form.
	 * @return string Filtered form.
	 */
	public static function rsvp_form( string $content ): string {
		if ( ! Context::get() || 'cancelled' === Context::get()->status ) {
			return 'cancelled' === ( Context::get()->status ?? '' ) ? '' : $content;
		}

		return preg_replace(
			'/(<\/form>)$/',
			'<input type="hidden" name="gpre_occurrence" value="' . esc_attr( Context::recurrence_id() ) . '">$1',
			$content
		) ?: $content;
	}

	/**
	 * Validates and activates occurrence context for a comment submission.
	 *
	 * @param array $comment_data Comment data.
	 * @return array Comment data.
	 */
	public static function capture_submission( array $comment_data ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies the comment submission.
		$requested = isset( $_POST['gpre_occurrence'] ) ? sanitize_text_field( wp_unslash( $_POST['gpre_occurrence'] ) ) : '';
		$post_id   = (int) ( $comment_data['comment_post_ID'] ?? 0 );

		if ( $requested ) {
			$occurrence = Occurrences::get( $post_id, $requested );
			if ( ! $occurrence ) {
				wp_die( esc_html__( 'Invalid event occurrence.', 'gpre' ), '', 400 );
			}
			Context::set( $occurrence );
		}

		return $comment_data;
	}

	/**
	 * Persists occurrence identity after inserting a comment or RSVP.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment object.
	 */
	public static function map_inserted( int $comment_id, WP_Comment $comment ): void {
		$occurrence = Context::get();
		if ( $occurrence && (int) $occurrence->series_post_id === (int) $comment->comment_post_ID ) {
			Database::map_comment( $comment_id, (int) $occurrence->series_post_id, (string) $occurrence->recurrence_id );
			Cache::delete( (int) $occurrence->series_post_id );
		}
	}
}
