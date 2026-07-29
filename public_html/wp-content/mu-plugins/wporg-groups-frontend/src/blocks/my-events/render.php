<?php
/**
 * Server-side rendering for the wporg/my-events block.
 *
 * Shows the current logged-in user their upcoming events: events they've
 * RSVPed "attending" to, unioned with events they organize (are the
 * post author of). Creating an event does not itself create an RSVP, so
 * both sources are needed for organizers to see events they're running.
 *
 * @package WordCamp\Groups\Frontend
 */

if ( ! is_user_logged_in() || ! is_user_member_of_blog() ) {
	return;
}

$user_id = get_current_user_id();

// Find RSVP comments by this user with "attending" status.
$rsvp_comments = get_comments(
	array(
		'user_id' => $user_id,
		'type'    => 'gatherpress_rsvp',
		'status'  => 'approve',
		'number'  => 100,
	)
);

$comment_event_ids = array();
foreach ( $rsvp_comments as $rsvp_comment ) {
	$comment_event_ids[ (int) $rsvp_comment->comment_ID ] = (int) $rsvp_comment->comment_post_ID;
}

$attending_ids = array();

if ( ! empty( $comment_event_ids ) ) {
	$rsvp_terms = wp_get_object_terms(
		array_keys( $comment_event_ids ),
		'_gatherpress_rsvp_status',
		array( 'fields' => 'all_with_object_id' )
	);

	if ( is_wp_error( $rsvp_terms ) ) {
		return;
	}

	// Filter to only attending RSVPs without re-querying per event.
	foreach ( $rsvp_terms as $rsvp_term ) {
		if ( 'attending' !== $rsvp_term->slug ) {
			continue;
		}

		$event_id = $comment_event_ids[ (int) $rsvp_term->object_id ] ?? 0;
		if ( $event_id ) {
			$attending_ids[ $event_id ] = $event_id;
		}
	}
}

// Find events this user organizes (is the author of).
$organized_ids = get_posts(
	array(
		'post_type'      => 'gatherpress_event',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'fields'         => 'ids',
		'posts_per_page' => 100,
	)
);

$my_event_ids = array_values( array_unique( array_merge( $attending_ids, $organized_ids ) ) );

if ( empty( $my_event_ids ) ) {
	return;
}

// Get event posts, filter to upcoming only.
$now = current_time( 'mysql', true );
global $wpdb;

$table        = $wpdb->prefix . 'gatherpress_events';
$placeholders = implode( ', ', array_fill( 0, count( $my_event_ids ), '%d' ) );
$query_args   = array_merge( $my_event_ids, array( $now ) );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are generated locally.
$upcoming_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE post_id IN ( {$placeholders} ) AND datetime_end_gmt >= %s ORDER BY datetime_start_gmt ASC", $query_args ) );
$upcoming_ids = array_map( 'intval', $upcoming_ids );

if ( empty( $upcoming_ids ) ) {
	return;
}

$upcoming_events = get_posts(
	array(
		'post_type'      => 'gatherpress_event',
		'post_status'    => 'publish',
		'post__in'       => $upcoming_ids,
		'orderby'        => 'post__in',
		'posts_per_page' => count( $upcoming_ids ),
	)
);

if ( empty( $upcoming_events ) ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-my-events' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h3 class="wporg-my-events__heading">
		<?php esc_html_e( 'My upcoming events', 'wporg-groups-frontend' ); ?>
	</h3>
	<div class="wporg-my-events__list">
		<?php foreach ( $upcoming_events as $event_post ) :
			$event = new \GatherPress\Core\Event\Event( $event_post->ID );
			$start = get_post_meta( $event_post->ID, 'gatherpress_datetime_start', true );
			$date_label = '';
			if ( $start ) {
				try {
					$dt = new \DateTime( $start );
					$date_label = $dt->format( 'M j, Y · g:i A' );
				} catch ( \Exception $e ) {
					$date_label = $start;
				}
			}
			?>
			<a class="wporg-my-events__item" href="<?php echo esc_url( get_permalink( $event_post->ID ) ); ?>">
				<span class="wporg-my-events__date"><?php echo esc_html( $date_label ); ?></span>
				<span class="wporg-my-events__title"><?php echo esc_html( get_the_title( $event_post->ID ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
</div>
