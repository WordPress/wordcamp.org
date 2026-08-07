<?php
/**
 * Server-side rendering for the wporg/group-news block.
 *
 * @package WordCamp\Groups\Frontend
 */

$wporg_limit = max( 1, min( 10, (int) ( $attributes['limit'] ?? 3 ) ) );
$wporg_posts = get_posts(
	array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => $wporg_limit,
		'orderby'             => 'date',
		'order'               => 'DESC',
		'ignore_sticky_posts' => true,
	)
);

// A heading with no posts would advertise an empty section, so the entire
// block disappears until the group publishes its first update.
if ( empty( $wporg_posts ) ) {
	return;
}

$wporg_wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-group-news' )
);
?>
<section <?php echo $wporg_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core. ?>>
	<h2 class="wporg-group-news__heading"><?php esc_html_e( 'News', 'wporg-groups-frontend' ); ?></h2>

	<div class="wporg-group-news__list">
		<?php foreach ( $wporg_posts as $wporg_post ) : ?>
			<article class="wporg-group-news__item">
				<time
					class="wporg-group-news__date"
					datetime="<?php echo esc_attr( get_the_date( DATE_W3C, $wporg_post ) ); ?>"
				>
					<?php echo esc_html( get_the_date( '', $wporg_post ) ); ?>
				</time>
				<h3 class="wporg-group-news__title">
					<a href="<?php echo esc_url( get_permalink( $wporg_post ) ); ?>">
						<?php echo esc_html( get_the_title( $wporg_post ) ); ?>
					</a>
				</h3>
				<?php if ( has_excerpt( $wporg_post ) ) : ?>
					<p class="wporg-group-news__excerpt">
						<?php echo esc_html( wp_trim_words( get_the_excerpt( $wporg_post ), 28 ) ); ?>
					</p>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
</section>
