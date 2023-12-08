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
add_filter( 'render_block', __NAMESPACE__ . '\customize_navigation_block_icon', 10, 2 );

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
function init() {
	register_block_type( __DIR__ . '/build' );

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

/**
 * Replace a nested navigation block mobile button icon with a caret icon.
 * Only applies if it has the 3 bar icon set, as this has an svg with <path> to update. 
 *
 * @param string $block_content The block content.
 * @param array  $block The parsed block data.
 *
 * @return string
 */
function customize_navigation_block_icon( $block_content, $block ) {
	if ( ! empty( $block['blockName'] ) && 'wporg/local-navigation-bar' === $block['blockName'] ) {
		$tag_processor = new \WP_HTML_Tag_Processor( $block_content );

		if (
			$tag_processor->next_tag( array( 
				'tag_name' => 'nav', 
				'class_name' => 'wp-block-navigation' 
			)
		) ) {
			if ( 
				$tag_processor->next_tag( array( 
					'tag_name' => 'button', 
					'class_name' => 'wp-block-navigation__responsive-container-open' 
				) ) &&
				$tag_processor->next_tag( 'path' )
			) {
				$tag_processor->set_attribute( 'd', 'M17.5 11.6L12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z' );
			}
		
			if ( 
				$tag_processor->next_tag( array( 
					'tag_name' => 'button', 
					'class_name' => 'wp-block-navigation__responsive-container-close' 
				) ) &&
				$tag_processor->next_tag( 'path' )
			) {
				$tag_processor->set_attribute( 'd', 'M6.5 12.4L12 8l5.5 4.4-.9 1.2L12 10l-4.5 3.6-1-1.2z' );
			}
		
			return $tag_processor->get_updated_html();
		}
	}

	return $block_content;
}
