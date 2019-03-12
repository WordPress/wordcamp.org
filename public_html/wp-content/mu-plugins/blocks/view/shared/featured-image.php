<?php

namespace WordCamp\Blocks\Shared\Components;

/**
 * Provides render backend for FeaturedImage component.
 *
 * @param array    $class_names        Additional classes to add inside <img> tag.
 * @param \WP_Post $post               Current post object. This will be used to calculate srcset attribute.
 * @param string   $selected_image_url URL for selected sized image.
 * @param int      $height             Height of the image
 * @param int      $width              Width of the image
 *
 * @return string Output markup for featured image.
 */
function render_featured_image( $class_names, $post, $height, $width ) {
	$class_names[] = 'wordcamp-featured-image';
	$class_names[] = 'wordcamp-featured-image-' . $post->post_name;
	$class_names = implode( ' ', $class_names );
	$style = esc_attr( 'height: ' . $height . 'px; width: ' . $width . 'px;' );
	return get_the_post_thumbnail(
		$post,
		array( $width, $height ),
		array(
			'class' => esc_attr( $class_names ),
			'alt'   => esc_attr( $post->post_name ),
			'style' => $style,
		)
	);
}
