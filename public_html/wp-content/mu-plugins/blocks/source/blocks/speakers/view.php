<?php
namespace WordCamp\Blocks\Speakers;

use WP_Post;
use function WordCamp\Blocks\Components\{ render_item_title, render_item_content, render_item_permalink };
use function WordCamp\Blocks\Utilities\{ get_all_the_content, get_trimmed_content };
use function WordCamp\Post_Types\Utilities\get_avatar_or_image;

defined( 'WPINC' ) || die();

/** @var array   $attributes */
/** @var array   $sessions */
/** @var WP_Post $speaker */

setup_postdata( $speaker ); // This is necessary for generating an excerpt from content if the excerpt field is empty.
?>

<div class="wordcamp-speaker wordcamp-speakers__post slug-<?php echo esc_attr( $speaker->post_name ); ?>">
	<?php echo wp_kses_post(
		render_item_title(
			get_the_title( $speaker ),
			get_permalink( $speaker ),
			3,
			array( 'wordcamp-speakers__title' ),
			$attributes['headingAlign']
		)
	); ?>

	<?php if ( true === $attributes['show_avatars'] ) : ?>
		<div class="wordcamp-image__avatar-container align-<?php echo esc_attr( $attributes['avatar_align'] ); ?>">
			<a href="<?php echo esc_url( get_permalink( $speaker ) ); ?>" class="wordcamp-image__avatar-link">
				<?php echo get_avatar_or_image( // phpcs:ignore -- escaped in function.
					$speaker->ID,
					$attributes['avatar_size'],
					sprintf( __( 'Avatar of %s', 'wordcamporg'), get_the_title( $speaker ) )
				); ?>
			</a>
		</div>
	<?php endif; ?>

	<?php if ( 'none' !== $attributes['content'] ) : ?>
		<?php echo render_item_content( // phpcs:ignore -- escaped in get_* functions.
			'excerpt' === $attributes['content']
				? get_trimmed_content( $speaker )
				: get_all_the_content( $speaker ),
			array( 'wordcamp-speakers__content', 'is-' . $attributes['content'] )
		); ?>
	<?php endif; ?>

	<?php if ( true === $attributes['show_session'] && ! empty( $sessions[ $speaker->ID ] ) ) : ?>
		<div class="wordcamp-speakers__sessions">
			<h4 class="wordcamp-speakers__sessions-heading">
				<?php echo esc_html( _n( 'Session', 'Sessions', count( $sessions[ $speaker->ID ] ), 'wordcamporg' ) ); ?>
			</h4>

			<ul class="wordcamp-speakers__sessions-list">
				<?php foreach ( $sessions[ $speaker->ID ] as $session ) : ?>
					<?php $tracks = get_the_terms( $session, 'wcb_track' ); ?>
					<li class="wordcamp-speakers__sessions-list-item">
						<a class="wordcamp-speakers__session-link" href="<?php echo esc_url( get_permalink( $session ) ); ?>">
							<?php echo wp_kses_post( get_the_title( $session ) ); ?>
						</a>

						<span class="wordcamp-speakers__session-info">
							<?php if ( ! is_wp_error( $tracks ) && ! empty( $tracks ) ) : ?>
								<?php
									printf(
										/* translators: 1: A date; 2: A time; 3: A location; */
										esc_html__( '%1$s at %2$s in %3$s', 'wordcamporg' ),
										esc_html( wp_date( get_option( 'date_format' ), $session->_wcpt_session_time ) ),
										esc_html( wp_date( get_option( 'time_format' ) . ' T', $session->_wcpt_session_time ) ),
										esc_html( $tracks[0]->name )
									);
								?>

							<?php else : ?>
								<?php
									printf(
										/* translators: 1: A date; 2: A time; */
										esc_html__( '%1$s at %2$s', 'wordcamporg' ),
										esc_html( wp_date( get_option( 'date_format' ), $session->_wcpt_session_time ) ),
										esc_html( wp_date( get_option( 'time_format' ) . ' T', $session->_wcpt_session_time ) )
									);
								?>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
</div>

<?php
wp_reset_postdata();
