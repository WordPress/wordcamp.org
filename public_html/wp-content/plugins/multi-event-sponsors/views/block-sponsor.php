<?php
namespace WordCamp\Multi_Event_Sponsors\Block;

use WP_Post;
use function WordCamp\Blocks\Components\{ render_featured_image, render_item_title, render_item_content, render_item_permalink };

defined( 'WPINC' ) || die();

/** @var array   $attributes */
/** @var WP_Post $sponsor */

setup_postdata( $sponsor ); // This is necessary for generating an excerpt from content if the excerpt field is empty.
?>

<div class="wordcamp-mes-sponsor slug-<?php echo esc_attr( $sponsor->post_name ); ?>">
	<?php echo wp_kses_post(
		render_featured_image(
			$sponsor,
			$attributes['image_width'],
			array( 'wordcamp-mes-sponsor__featured-image', 'wordcamp-mes-sponsor__logo', 'align-' . esc_attr( $attributes['image_align'] ) )
		)
	); ?>
</div>

<?php
wp_reset_postdata();
