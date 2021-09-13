<?php
namespace WordCamp\Blocks\Organizers;

use WP_Post;
use function WordCamp\Blocks\Components\{ render_item_title, render_item_content };
use function WordCamp\Blocks\Utilities\{ get_all_the_content, get_trimmed_content };
use function WordCamp\Post_Types\Utilities\get_avatar_or_image;

defined( 'WPINC' ) || die();

/** @var array   $attributes */
/** @var WP_Post $organizer */

// Note that organizer posts are not 'public', so there are no permalinks.

setup_postdata( $organizer ); // This is necessary for generating an excerpt from content if the excerpt field is empty.
?>

<div class="wordcamp-organizer wordcamp-organizers__post slug-<?php echo esc_attr( $organizer->post_name ); ?>">
	<?php echo wp_kses_post(
		render_item_title(
			get_the_title( $organizer ),
			'',
			3,
			array( 'wordcamp-organizers__title' ),
			$attributes['headingAlign']
		)
	); ?>

	<?php if ( true === $attributes['show_avatars'] ) : ?>
		<div class="wordcamp-image__avatar-container align-<?php echo esc_attr( $attributes['avatar_align'] ); ?>">
			<?php echo get_avatar_or_image( // phpcs:ignore -- escaped in function.
				$organizer->ID,
				$attributes['avatar_size'],
				sprintf( __( 'Avatar of %s', 'wordcamporg'), get_the_title( $organizer ) )
			); ?>
		</div>
	<?php endif; ?>

	<?php if ( 'none' !== $attributes['content'] ) : ?>
		<?php echo render_item_content( // phpcs:ignore -- escaped in get_* functions.
			'excerpt' === $attributes['content']
				? get_trimmed_content( $organizer )
				: get_all_the_content( $organizer ),
			array( 'wordcamp-organizers__content', 'is-' . $attributes['content'] )
		); ?>
	<?php endif; ?>
</div>

<?php
wp_reset_postdata();
