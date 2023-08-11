<?php
/**
 * Block Name: Local Navigation Bar
 * Description: A special block to handle the local navigation on pages in a section.
 *
 * @package wporg
 */

namespace WordPressdotorg\MU_Plugins\LocalNavigationBar_Block;

add_action( 'init', __NAMESPACE__ . '\init' );
add_filter( 'render_block_data', __NAMESPACE__ . '\update_block_attributes' );

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
function init() {
	register_block_type(
		__DIR__ . '/build',
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);

	// Add the Brush Stroke block style.
	register_block_style(
		'wporg/local-navigation-bar',
		array(
			'name'         => 'brush-stroke',
			'label'        => __( 'Brush Stroke', 'wporg' ),
		)
	);
}

/**
 * Render the block content.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block markup.
 */
function render( $attributes, $content, $block ) {
	$wrapper_attributes = get_block_wrapper_attributes();

	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		$content
	);
}

/**
 * Inject the default block values. In the editor, these are read from block.json.
 * See https://github.com/WordPress/gutenberg/issues/50229.
 *
 * @param array $block The parsed block data.
 *
 * @return array
 */
function update_block_attributes( $block ) {
	if ( ! empty( $block['blockName'] ) && 'wporg/local-navigation-bar' === $block['blockName'] ) {
		// Always override alignment.
		$block['attrs']['align'] = 'full';

		// Set layout values if they don't exist.
		$default_layout = array(
			'type' => 'flex',
			'flexWrap' => 'wrap',
			'justifyContent' => 'space-between',
		);
		if ( ! empty( $block['attrs']['layout'] ) ) {
			$block['attrs']['layout'] = array_merge( $default_layout, $block['attrs']['layout'] );
		} else {
			$block['attrs']['layout'] = $default_layout;
		}

		// Set position if it doesn't exist (functionally this will always be
		// sticky, unless different positions are added).
		if ( empty( $block['attrs']['style']['position'] ) ) {
			$block['attrs']['style']['position'] = array(
				'type' => 'sticky',
			);
		}
	}

	return $block;
}
