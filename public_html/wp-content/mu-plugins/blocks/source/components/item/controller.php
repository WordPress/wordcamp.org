<?php
namespace WordCamp\Blocks\Components;

use function WordCamp\Blocks\Utilities\{ render_class_string };

defined( 'WPINC' ) || die();

/**
 * Render HTML for a title with specified heading level and optionally a link.
 *
 * @param string $title
 * @param string $link
 * @param int    $heading_level
 * @param array  $classes
 * @param string $align
 *
 * @return false|string
 */
function render_item_title( $title, $link = '', $heading_level = 3, array $classes = array(), $align = 'none' ) {
	$valid_heading_levels = array( 1, 2, 3, 4, 5, 6 );

	if ( ! in_array( $heading_level, $valid_heading_levels, true ) ) {
		$heading_level = 3;
	}

	$tag = 'h' . $heading_level;

	$classes = render_class_string( array_merge(
		array( 'wordcamp-block__item-title' ),
		$classes
	) );

	$style = '';

	if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
		$style = "text-align:$align;";
	}

	$output = sprintf(
		'<%1$s class="%2$s" style="%3$s">',
		esc_html( $tag ),
		esc_attr( $classes ),
		esc_attr( $style )
	);
	if ( $link ) {
		$output .= '<a href="' . esc_url( $link ) . '">';
	}
	$output .= $title;
	if ( $link ) {
		$output .= '</a>';
	}
	$output .= '</' . esc_html( $tag ) . '>';

	return $output;
}

/**
 * Render arbitrary HTML content within a div container.
 *
 * @param string $content
 * @param array  $classes
 *
 * @return false|string
 */
function render_item_content( $content, array $classes = array() ) {
	$classes = render_class_string( array_merge(
		array( 'wordcamp-block__item-content' ),
		$classes
	) );

	return sprintf(
		'<div class="%1$s">%2$s</div>',
		esc_attr( $classes ),
		wpautop( $content )
	);
}

/**
 * Render HTML for a permalink wrapped in its own paragraph.
 *
 * @param string $link
 * @param string $label
 * @param array  $classes
 *
 * @return false|string
 */
function render_item_permalink( $link, $label = '', array $classes = array() ) {
	if ( ! $label ) {
		$label = __( 'Read more', 'wordcamporg' );
	}

	$classes = render_class_string( array_merge(
		array( 'wordcamp-block__item-permalink' ),
		$classes
	) );

	return sprintf(
		'<p class="%1$s"><a href="%2$s>%3$s</a></p>',
		esc_attr( $classes ),
		esc_url( $link ),
		esc_html( $label )
	);
}
