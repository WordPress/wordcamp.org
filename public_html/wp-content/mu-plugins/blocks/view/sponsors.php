<?php
	namespace WordCamp\Blocks\Sponsors;
	defined( 'WPINC' ) || die();

	use function WordCamp\Blocks\Shared\{ get_all_the_content };

	/** @var array  $attributes */
	/** @var array  $sponsors */
	/** @var string $container_classes */

?>

<ul class="<?php echo esc_attr( $container_classes ); ?>">

	<?php foreach ( $sponsors as $sponsor ) : setup_postdata( $sponsor ); // phpcs:ignore Squiz.ControlStructures.ControlSignature ?>

		<li class="wordcamp-sponsor wordcamp-sponsor-<?php echo sanitize_html_class( $sponsor->post_name ); ?> wordcamp-clearfix">
			<div class="wordcamp-sponsor-details">

				<?php if ( $attributes['show_logo'] ) { ?>
					<img
						class="featured-image wordcamp-sponsor-featured-image wordcamp-sponsor-logo"
						src="<?php echo esc_attr( $attributes['sponsor-logo-url'][ $sponsor->ID ]) ?>"
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
		</li>

	<?php endforeach; wp_reset_postdata(); // phpcs:ignore Generic.Formatting.DisallowMultipleStatements,Squiz.PHP.EmbeddedPhp ?>
</ul>
