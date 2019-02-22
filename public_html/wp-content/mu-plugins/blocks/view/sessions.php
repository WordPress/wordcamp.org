<?php
namespace WordCamp\Blocks\Sessions;
defined( 'WPINC' ) || die();

use function WordCamp\Blocks\Shared\{ get_all_the_content, array_to_human_readable_list };

/** @var array  $attributes */
/** @var array  $sessions */
/** @var array  $speakers */
/** @var string $container_classes */

?>

<?php if ( ! empty( $sessions ) ) : ?>
	<ul class="<?php echo esc_attr( $container_classes ); ?>">
		<?php foreach ( $sessions as $session ) : setup_postdata( $session ); // phpcs:ignore Squiz.ControlStructures.ControlSignature ?>
			<li class="wordcamp-session wordcamp-session-<?php echo sanitize_html_class( $session->post_name ); ?> wordcamp-clearfix">
				<h3 class="wordcamp-session-name-heading">
					<a href="<?php echo esc_url( get_permalink( $session ) ); ?>">
						<?php echo get_the_title( $session ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</a>
				</h3>

				<?php if ( true === $attributes['show_speaker'] && ! empty( $speakers[ $session->ID ] ) ) :
					$speaker_linked_names = array_map( function( $speaker ) {
						return sprintf(
							'<a href="%s">%s</a>',
							get_permalink( $speaker ),
							get_the_title( $speaker )
						);
					}, $speakers[ $session->ID ] );
					?>
					<div class="wordcamp-session-speakers">
						<?php
						printf(
							/* translators: %s is a list of names. */
							__( 'Presented by %s', 'wordcamporg' ),
							array_to_human_readable_list( $speaker_linked_names )
						);
						?>
					</div>
				<?php endif; ?>

				<?php if ( true === $attributes['show_images'] ) : ?>
					<div class="wordcamp-session-image-container align-<?php echo esc_attr( $attributes['image_align'] ); ?>">
						<a href="<?php echo esc_url( get_permalink( $session ) ); ?>" class="wordcamp-session-image-link">
							<?php if ( get_post_thumbnail_id( $session ) ) : ?>
								<?php
								echo get_the_post_thumbnail(
									$session,
									[ $attributes['image_size'], 9999 ],
									[
										'class' => 'wordcamp-session-image',
									]
								);
								?>
							<?php else : ?>
								<div class="wordcamp-session-default-image"></div>
							<?php endif; ?>
						</a>
					</div>
				<?php endif; ?>

				<?php if ( 'none' !== $attributes['content'] ) : ?>
					<div class="wordcamp-session-content wordcamp-session-content-<?php echo esc_attr( $attributes['content'] ); ?>">
						<?php if ( 'full' === $attributes['content'] ) : ?>
							<?php echo wpautop( get_all_the_content( $session ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<p class="wordcamp-session-permalink">
								<a href="<?php echo esc_url( get_permalink( $session ) ); ?>">
									<?php esc_html_e( 'Visit session page', 'wordcamporg' ); ?>
								</a>
							</p>
						<?php elseif ( 'excerpt' === $attributes['content'] ) : ?>
							<?php wpautop( the_excerpt() ); ?>
							<?php if ( true === $attributes['excerpt_more'] ) : ?>
								<p class="wordcamp-session-permalink">
									<a href="<?php echo esc_url( get_permalink( $session ) ); ?>" class="wordcamp-session-permalink">
										<?php esc_html_e( 'Read more', 'wordcamporg' ); ?>
									</a>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $attributes['show_meta'] || $attributes['show_category'] ) : ?>

				<?php endif; ?>
			</li>
		<?php endforeach; wp_reset_postdata(); // phpcs:ignore Generic.Formatting.DisallowMultipleStatements,Squiz.PHP.EmbeddedPhp ?>
	</ul>
<?php endif; ?>
