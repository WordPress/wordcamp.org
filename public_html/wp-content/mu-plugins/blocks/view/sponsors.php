<?php
namespace WordCamp\Blocks\Sponsors;
defined( 'WPINC' ) || die();

use function WordCamp\Blocks\Shared\{ get_all_the_content };
use function WordCamp\Blocks\Shared\Components\{ render_featured_image };

/** @var array     $attributes */
/** @var \WP_Post  $sponsor */
/** @var array     $sponsor_featured_urls */

setup_postdata( $sponsor );

?>
<div class="wordcamp-sponsor-details <?php echo sanitize_html_class( $sponsor->post_name ); ?> ">

	<?php if ( $attributes['show_logo'] && $sponsor_featured_urls[ $sponsor->ID ] ) { ?>
		<?php echo render_featured_image(
			array( 'wordcamp-sponsor-featured-image' ),
			$sponsor,
			$attributes['sponsor_logo_height'],
			$attributes['sponsor_logo_width'],
			$sponsor->post_title
		); ?>
	<?php } ?>

	<?php if ( $attributes['show_name'] ) { ?>
		<div class="wordcamp-sponsor-name">
			<a href="<?php esc_attr_e( get_permalink( $sponsor->ID ) ) ?>"><?php esc_html_e( $sponsor->post_title ) ?></a>
		</div>
	<?php } ?>

	<?php if ( $attributes['show_desc'] ) { ?>
		<?php echo get_all_the_content( $sponsor ) ?>
	<?php } ?>

</div>
