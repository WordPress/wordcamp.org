<?php
/**
 * Server-side rendering for the wporg/my-events block.
 *
 * Shows the current logged-in user their upcoming RSVPed events.
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

if ( empty( $rsvp_comments ) ) {
	return;
}

// Get unique event post IDs.
$event_ids = array_unique(
	array_map(
		function ( $comment ) {
			return (int) $comment->comment_post_ID;
		},
		$rsvp_comments
	)
);

// Filter to only attending RSVPs.
$attending_ids = array();
foreach ( $event_ids as $eid ) {
	$terms = wp_get_object_terms(
		get_comments(
			array(
				'user_id' => $user_id,
				'post_id' => $eid,
				'type'    => 'gatherpress_rsvp',
				'number'  => 1,
				'fields'  => 'ids',
			)
		),
		'_gatherpress_rsvp_status',
		array( 'fields' => 'slugs' )
	);

	if ( ! is_wp_error( $terms ) && in_array( 'attending', $terms, true ) ) {
		$attending_ids[] = $eid;
	}
}

if ( empty( $attending_ids ) ) {
	return;
}

// Get event posts, filter to upcoming only.
$now = current_time( 'mysql', true );
$upcoming_events = array();

foreach ( $attending_ids as $eid ) {
	$post = get_post( $eid );
	if ( ! $post || 'publish' !== $post->post_status ) {
		continue;
	}

	// Check if event is upcoming via GatherPress events table.
	global $wpdb;
	$table = $wpdb->prefix . 'gatherpress_events';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$dt = $wpdb->get_var( $wpdb->prepare( "SELECT datetime_end_gmt FROM {$table} WHERE post_id = %d", $eid ) );

	if ( $dt && $dt >= $now ) {
		$upcoming_events[] = $post;
	}
}

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
		<?php foreach ( $upcoming_events as $post ) :
			$event = new \GatherPress\Core\Event( $post->ID );
			$start = get_post_meta( $post->ID, 'gatherpress_datetime_start', true );
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
			<a class="wporg-my-events__item" href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>">
				<span class="wporg-my-events__date"><?php echo esc_html( $date_label ); ?></span>
				<span class="wporg-my-events__title"><?php echo esc_html( get_the_title( $post->ID ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
</div>
