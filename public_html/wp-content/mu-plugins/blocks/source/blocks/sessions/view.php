<?php
namespace WordCamp\Blocks\Sessions;

use WP_Post;
use function WordCamp\Blocks\Components\{ render_featured_image, render_item_title, render_item_content, render_item_permalink };
use function WordCamp\Blocks\Utilities\{ array_to_human_readable_list, get_all_the_content, get_trimmed_content };

defined( 'WPINC' ) || die();

/** @var array   $attributes */
/** @var array   $speakers */
/** @var WP_Post $session */

setup_postdata( $session );
?>

<div class="wordcamp-session wordcamp-sessions__post slug-<?php echo sanitize_html_class( $session->post_name ); ?>">
	<?php echo wp_kses_post(
		render_item_title(
			get_the_title( $session ),
			get_permalink( $session ),
			3,
			array( 'wordcamp-sessions__title' ),
			$attributes['headingAlign']
		)
	); ?>

	<?php if ( true === $attributes['show_speaker'] && ! empty( $speakers[ $session->ID ] ) ) :
		$speaker_linked_names = array_map(
			function( $speaker ) {
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( get_permalink( $speaker ) ),
					esc_html( get_the_title( $speaker ) )
				);
			},
			$speakers[ $session->ID ]
		);
		?>

		<p class="wordcamp-sessions__speakers">
			<?php
			printf(
				/* translators: %s is a list of names. */
				esc_html__( 'Presented by %s', 'wordcamporg' ),
				array_to_human_readable_list( $speaker_linked_names ) // phpcs:ignore -- Escaped above.
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( true === $attributes['show_images'] ) : ?>
		<?php echo render_featured_image( // phpcs:ignore -- User input escaped in function.
			$session,
			$attributes['featured_image_width'],
			array( 'wordcamp-sessions__featured-image', 'align-' . esc_attr( $attributes['image_align'] ) ),
			get_permalink( $session )
		); ?>
	<?php endif; ?>

	<?php if ( 'none' !== $attributes['content'] ) : ?>
		<?php echo render_item_content( // phpcs:ignore -- escaped in get_* functions.
			'excerpt' === $attributes['content']
				? get_trimmed_content( $session )
				: get_all_the_content( $session ),
			array( 'wordcamp-sessions__content', 'is-' . $attributes['content'] )
		); ?>
	<?php endif; ?>

	<?php if ( $attributes['show_meta'] || $attributes['show_category'] ) : ?>
		<div class="wordcamp-sessions__details">
			<?php if ( $attributes['show_meta'] ) : ?>
				<?php $tracks = get_the_terms( $session, 'wcb_track' ); ?>

				<div class="wordcamp-sessions__time-location">
					<?php if ( ! is_wp_error( $tracks ) && ! empty( $tracks ) ) :
						printf(
							/* translators: 1: A date; 2: A time; 3: A location; */
							esc_html__( '%1$s at %2$s in %3$s', 'wordcamporg' ),
							esc_html( wp_date( get_option( 'date_format' ), $session->_wcpt_session_time ) ),
							esc_html( wp_date( get_option( 'time_format' ) . ' T', $session->_wcpt_session_time ) ),
							sprintf(
								'<span class="wordcamp-sessions__track slug-%s">%s</span>',
								esc_attr( $tracks[0]->slug ),
								esc_html( $tracks[0]->name )
							)
						);

					else :
						printf(
							/* translators: 1: A date; 2: A time; */
							esc_html__( '%1$s at %2$s', 'wordcamporg' ),
							esc_html( wp_date( get_option( 'date_format' ), $session->_wcpt_session_time ) ),
							esc_html( wp_date( get_option( 'time_format' ) . ' T', $session->_wcpt_session_time ) )
						);
					endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $attributes['show_category'] && has_term( null, 'wcb_session_category', $session ) ) :
				$categories = array_map(
					function( $category ) {
						return sprintf(
							'<span class="wordcamp-sessions__category slug-%s">%s</span>',
							esc_attr( $category->slug ),
							esc_html( $category->name )
						);
					},
					get_the_terms( $session, 'wcb_session_category' )
				);
				?>

				<div class="wordcamp-sessions__categories">
					<?php
					/* translators: used between list items, there is a space after the comma */
					echo implode( esc_html__( ', ', 'wordcamporg' ), $categories ); // phpcs:ignore -- Escaped above.
					?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>

<?php
wp_reset_postdata();
