<?php
namespace WordCamp\Blocks\Sponsors;

use WP_Post;
use function WordCamp\Blocks\Components\{ render_featured_image, render_item_title, render_item_content, render_item_permalink };
use function WordCamp\Blocks\Utilities\{ get_all_the_content, get_trimmed_content };

defined( 'WPINC' ) || die();

/** @var array   $attributes */
/** @var WP_Post $sponsor */

setup_postdata( $sponsor ); // This is necessary for generating an excerpt from content if the excerpt field is empty.
?>

<div class="wordcamp-sponsor wordcamp-sponsors__post slug-<?php echo esc_attr( $sponsor->post_name ); ?>">
	<?php if ( true === $attributes['show_name'] ) : ?>
		<?php echo wp_kses_post(
			render_item_title(
				get_the_title( $sponsor ),
				get_permalink( $sponsor ),
				3,
				array( 'wordcamp-sponsors__title' ),
				$attributes['headingAlign']
			)
		); ?>
	<?php endif; ?>

	<?php if ( true === $attributes['show_logo'] ) : ?>
		<?php echo render_featured_image( // phpcs:ignore -- User input escaped in function.
			$sponsor,
			$attributes['featured_image_width'],
			array( 'wordcamp-sponsors__featured-image', 'wordcamp-sponsors__logo', 'align-' . esc_attr( $attributes['image_align'] ) ),
			get_permalink( $sponsor )
		); ?>
	<?php endif; ?>

	<?php if ( 'none' !== $attributes['content'] ) : ?>
		<?php echo render_item_content( // phpcs:ignore -- escaped in get_* functions.
			'excerpt' === $attributes['content']
				? get_trimmed_content( $sponsor )
				: get_all_the_content( $sponsor ),
			array( 'wordcamp-sponsors__content', 'is-' . $attributes['content'] )
		); ?>
	<?php endif; ?>
</div>

<?php
wp_reset_postdata();
