<?php
/**
 * Server-side rendering for the wporg/event-speakers block.
 *
 * Displays speakers assigned to the current event.
 *
 * @package WordCamp\Groups\Frontend
 */

$event_post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $event_post_id ) {
	$event_post_id = get_queried_object_id();
}

$speaker_ids = get_post_meta( $event_post_id, '_event_speakers', true );

if ( empty( $speaker_ids ) || ! is_array( $speaker_ids ) ) {
	return;
}

$speakers = array();
foreach ( $speaker_ids as $user_id ) {
	$user = get_userdata( (int) $user_id );
	if ( ! $user ) {
		continue;
	}
	$speakers[] = $user;
}

if ( empty( $speakers ) ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-event-speakers' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h3 class="wporg-event-speakers__heading">
		<?php
		echo esc_html(
			sprintf(
				_n( 'Speaker', 'Speakers', count( $speakers ), 'wporg-groups-frontend' ),
				count( $speakers )
			)
		);
		?>
	</h3>
	<div class="wporg-event-speakers__list">
		<?php foreach ( $speakers as $user ) :
			$bio     = wp_trim_words( get_the_author_meta( 'description', $user->ID ), 30, "\u{2026}" );
			$profile = sprintf( 'https://profiles.wordpress.org/%s/', $user->user_nicename );
			?>
			<a class="wporg-event-speakers__card" href="<?php echo esc_url( $profile ); ?>" target="_blank" rel="noopener">
				<img
					class="wporg-event-speakers__avatar"
					src="<?php echo esc_url( get_avatar_url( $user->ID, array( 'size' => 128 ) ) ); ?>"
					alt=""
					width="64"
					height="64"
					loading="lazy"
				/>
				<div class="wporg-event-speakers__info">
					<span class="wporg-event-speakers__name"><?php echo esc_html( $user->display_name ); ?></span>
					<span class="wporg-event-speakers__badge"><?php esc_html_e( 'Speaker', 'wporg-groups-frontend' ); ?></span>
					<?php if ( $bio ) : ?>
						<span class="wporg-event-speakers__bio"><?php echo esc_html( $bio ); ?></span>
					<?php endif; ?>
				</div>
			</a>
		<?php endforeach; ?>
	</div>
</div>
