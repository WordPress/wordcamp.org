<?php
/**
 * Title: Archive — events empty state
 * Slug: groups-site/archive-events-empty
 * Categories: groups-site
 * Inserter: no
 *
 * The no-results state for the events archive. The copy adapts to how the
 * visitor got here: a search or Time filter that matched nothing offers a
 * way back to the full upcoming list, while a group with genuinely nothing
 * scheduled points at its past events instead of a dead end.
 *
 * @package WordCamp\Groups\Site
 */

namespace WordCamp\Groups\Site\Patterns\ArchiveEventsEmpty;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view state.
$search_term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$time_filter = isset( $_GET['event_time'] ) ? sanitize_key( wp_unslash( $_GET['event_time'] ) ) : 'upcoming';
// phpcs:enable

$archive_url = get_post_type_archive_link( 'gatherpress_event' );
$is_filtered = '' !== $search_term || 'upcoming' !== $time_filter;

if ( $is_filtered ) {
	$message   = __( 'No events match.', 'groups-site' );
	$hint      = __( 'Try a different search, or switch the time filter.', 'groups-site' );
	$link_url  = $archive_url;
	$link_text = __( 'View all upcoming events', 'groups-site' );
} else {
	$message   = __( 'Nothing on the calendar yet.', 'groups-site' );
	$hint      = __( 'The organizers haven&rsquo;t scheduled the next event. Check back soon.', 'groups-site' );
	$link_url  = add_query_arg( 'event_time', 'past', $archive_url );
	$link_text = __( 'See past events', 'groups-site' );
}
?>
<!-- wp:group {"className":"groups-site-events-empty","layout":{"type":"constrained","justifyContent":"left"}} -->
<div class="wp-block-group groups-site-events-empty">
	<!-- wp:paragraph {"className":"groups-site-events-empty__title"} -->
	<p class="groups-site-events-empty__title"><?php echo esc_html( $message ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:paragraph {"textColor":"charcoal-3","className":"groups-site-events-empty__hint"} -->
	<p class="groups-site-events-empty__hint has-charcoal-3-color has-text-color"><?php echo wp_kses_post( $hint ); ?> <a href="<?php echo esc_url( $link_url ); ?>"><?php echo esc_html( $link_text ); ?></a></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
