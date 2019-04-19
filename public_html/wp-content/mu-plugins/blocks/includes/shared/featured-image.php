<?php

namespace WordCamp\Blocks\Shared\Components;

/**
 * Provides render backend for FeaturedImage component.
 *
 * @param array    $class_names        Additional classes to add inside <img> tag.
 * @param \WP_Post $post               Current post object. This will be used to calculate srcset attribute.
 * @param int      $width              Width of the image.
 * @param string   $image_link         URL link. If provided, image will be linked to this URL.
 *
 * @return string Output markup for featured image.
 */
function render_featured_image( $class_names, $post, $width, $image_link = '', $fallback_icon = '' ) {
	$attachment_id = get_post_thumbnail_id( $post->ID );
	$image_data    = wp_get_attachment_metadata( $attachment_id );
	$size          = 'post-thumbnail';

	if ( ! isset( $image_data['width'], $image_data['height'] ) && $fallback_icon ) {
		return render_featured_image_fallback( $fallback_icon, $width, $image_link, $class_names );
	}

	$aspect_ratio      = $image_data['height'] / $image_data['width'];
	$height            = $aspect_ratio * $width;
	$size              = array( $width, $height );
	$class_names       = implode( ' ', $class_names );
	$container_classes = "wordcamp-image-container wordcamp-featured-image-container $class_names";

	$image = render_featured_image_element( $post, $size );

	ob_start();
	?>
		<div class="<?php echo esc_attr( $container_classes ); ?>">
			<?php if ( '' !== $image_link ) { ?>
				<a href="<?php echo esc_html( $image_link ); ?>" class="wordcamp-image-link wordcamp-featured-image-link">
					<?php echo wp_kses_post( $image ); ?>
				</a>
			<?php } else { ?>
				<?php echo wp_kses_post( $image ); ?>
			<?php } ?>
		</div>
	<?php

	return ob_get_clean();
}

/**
 * Helper method to render thumbnail image.
 *
 * @param \WP_Post     $post
 * @param string|array $size
 * @param string       $class_names
 *
 * @return string
 */
function render_featured_image_element( $post, $size ) {
	$attr = [
		'class' => 'wordcamp-featured-image',
	];

	if ( is_array( $size ) ) {
		$attr = array_merge( $attr, $size );
	}

	return get_the_post_thumbnail( $post, $size, $attr );
}


function render_featured_image_fallback( $icon, $width, $link = '', $class_names = [] ) {
	$class_names[]     = 'wordcamp-featured-image-fallback-container';
	$container_classes = implode( ' ', $class_names );

	$style = sprintf(
		'max-width: %dpx;',
		$width
	);

	$content = sprintf(
		'<span class="dashicons dashicons-%s" style="font-size: %dpx" aria-hidden="true">&nbsp;</span>',
		esc_attr( $icon ),
		$width * 0.65
	);

	ob_start();
	?>
		<div class="<?php echo esc_attr( $container_classes ); ?>" style="<?php echo esc_attr( $style ); ?>">
			<div class="wordcamp-featured-image-fallback-container-inner">
				<?php if ( $link ) : ?>
					<a href="<?php echo esc_url( $link ); ?>" class="wordcamp-featured-image-link wordcamp-featured-image-fallback-link">
						<?php echo wp_kses_post( $content ); ?>
					</a>
				<?php else : ?>
					<?php echo wp_kses_post( $content ); ?>
				<?php endif; ?>
			</div>
		</div>
	<?php

	return ob_get_clean();
}
