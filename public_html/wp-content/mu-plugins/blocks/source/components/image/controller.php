<?php
namespace WordCamp\Blocks\Components;

use WP_Post;

defined( 'WPINC' ) || die();

/**
 * Provides render backend for FeaturedImage component.
 *
 * @param WP_Post $post        Current post object. This will be used to calculate srcset attribute.
 * @param int     $width       Width of the image.
 * @param array   $class_names Additional classes to add inside <img> tag.
 * @param string  $image_link  URL link. If provided, image will be linked to this URL.
 *
 * @return string Output markup for featured image.
 */
function render_featured_image( $post, $width, $class_names = array(), $image_link = '' ) {
	$attachment_id = get_post_thumbnail_id( $post->ID );
	$image_data    = wp_get_attachment_metadata( $attachment_id );

	if ( ! isset( $image_data['width'], $image_data['height'] ) ) {
		return '';
	}

	$aspect_ratio = $image_data['height'] / $image_data['width'];
	$height       = round( $aspect_ratio * $width, 1 );
	$size         = array( $width, $height );

	$container_classes = array_merge(
		array( 'wordcamp-image__featured-image-container' ),
		$class_names
	);
	$container_classes = implode( ' ', $container_classes );

	$image = render_featured_image_element( $post, $size );

	$output = '<div class="' . esc_attr( $container_classes ) . '">';
	if ( '' !== $image_link ) {
		$output .= sprintf(
			'<a href="%1$s" class="wordcamp-image__featured-image-link">%2$s</a>',
			esc_attr( $image_link ),
			$image
		);
	} else {
		$output .= $image;
	}
	$output .= '</div>';

	return $output;
}

/**
 * Helper method to render thumbnail image.
 *
 * @param \WP_Post     $post
 * @param string|array $size
 *
 * @return string
 */
function render_featured_image_element( $post, $size ) {
	$attr = array(
		'class' => 'wordcamp-image__featured-image',
	);

	return get_the_post_thumbnail( $post, $size, $attr );
}
