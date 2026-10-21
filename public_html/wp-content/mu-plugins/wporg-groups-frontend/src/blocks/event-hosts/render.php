<?php
/**
 * Server-side rendering for the wporg/event-hosts block.
 *
 * "Hosted by" and every host's name, each linked to their WordPress.org
 * profile, after a stack of their avatars. Replaces the avatar and
 * post-author-name pair the event hero used, which could only ever credit the
 * event's author (#2152).
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Event_Hosts\get_event_hosts;

$event_post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $event_post_id ) {
	$event_post_id = get_queried_object_id();
}

/*
 * Behind the event's password gate, credit the author only, which is what
 * the hero showed there before hosts existed. The hosts an organizer picks
 * are event details like the speakers, so they wait for the password too.
 * Unconditional: `preview` is a plain query var any visitor can set, so it
 * must not relax this.
 */
if ( post_password_required( $event_post_id ) ) {
	$author = get_userdata( (int) get_post_field( 'post_author', $event_post_id ) );
	$hosts  = $author ? array( $author ) : array();
} else {
	$hosts = get_event_hosts( (int) $event_post_id );
}

if ( empty( $hosts ) ) {
	return;
}

// The stack is decoration next to the names, so it stops at a few faces
// rather than growing with the list.
$avatar_hosts = array_slice( $hosts, 0, 3 );

$host_links = array_map(
	static function ( WP_User $host ): string {
		return sprintf(
			'<a class="wporg-event-hosts__name" href="%s">%s</a>',
			esc_url( sprintf( 'https://profiles.wordpress.org/%s/', $host->user_nicename ) ),
			esc_html( $host->display_name )
		);
	},
	$hosts
);

$wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-event-hosts' )
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wporg-event-hosts__avatars" aria-hidden="true">
		<?php foreach ( $avatar_hosts as $host ) : ?>
			<img
				class="wporg-event-hosts__avatar"
				src="<?php echo esc_url( get_avatar_url( $host->ID, array( 'size' => 96 ) ) ); ?>"
				alt=""
				width="48"
				height="48"
			/>
		<?php endforeach; ?>
	</div>
	<p class="wporg-event-hosts__text">
		<span class="wporg-event-hosts__label"><?php esc_html_e( 'Hosted by', 'wordcamporg' ); ?></span>
		<?php echo wp_sprintf_l( '%l', $host_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each name is escaped above. ?>
	</p>
</div>
