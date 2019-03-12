<?php
namespace WordCamp\Blocks\Sponsors;
defined( 'WPINC' ) || die();

use function WordCamp\Blocks\Shared\{ get_all_the_content };

/** @var array     $attributes */
/** @var \WP_Post  $sponsor */
/** @var array     $sponsor_featured_urls */

setup_postdata( $sponsor );

?>
<div class="wordcamp-sponsor-details <?php echo sanitize_html_class( $sponsor->post_name ); ?> ">

	<?php if ( $attributes['show_logo'] && $sponsor_featured_urls[ $sponsor->ID ] ) { ?>
		<img
			class="featured-image wordcamp-sponsor-featured-image wordcamp-sponsor-logo"
			src="<?php echo esc_attr( $sponsor_featured_urls[ $sponsor->ID ] ) ?>"
			alt="<?php echo esc_attr( $sponsor->post_title ) ?>"
			style=" height: <?php echo esc_attr( $attributes['sponsor_logo_height'] ) ?>px; "
			width="<?php echo esc_attr( $attributes['sponsor_logo_width'] ) ?>px; "
		/>
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
