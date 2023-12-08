<?php
/**
 * Block Name: Query Total
 * Description: Display the total items found in the current query.
 *
 * @package wporg
 */

namespace WordPressdotorg\MU_Plugins\Query_Total_Block;

use WP_Query;

add_action( 'init', __NAMESPACE__ . '\init' );
add_filter( 'render_block_data', __NAMESPACE__ . '\update_block_attributes' );

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
	$page_key = isset( $block->context['queryId'] ) ? 'query-' . $block->context['queryId'] . '-page' : 'query-page';
	$page = empty( $_GET[ $page_key ] ) ? 1 : (int) $_GET[ $page_key ];
	$found_posts = 0;

	// Check whether this is a custom query or inheriting from global.
	if ( isset( $block->context['query']['inherit'] ) && $block->context['query']['inherit'] ) {
		global $wp_query;
		$found_posts = $wp_query->found_posts;
	} else {
		$custom_query = new WP_Query( build_query_vars_from_query_block( $block, $page ) );
		$found_posts = (int) $custom_query->found_posts;
		wp_reset_postdata();
	}

	/**
	 * Get a custom label for the result set.
	 *
	 * The default label uses "item," but this filter can be used to change that to the
	 * relevant content type label.
	 *
	 * @param string   $label       The maybe-pluralized label to use, a result of `_n()`.
	 * @param int      $found_posts The number of posts to use for determining pluralization.
	 * @param WP_Block $block       The current block being rendered.
	 */
	$label = apply_filters(
		'wporg_query_total_label',
		/* translators: %s: the result count. */
		_n( '%s item', '%s items', $found_posts, 'wporg' ),
		$found_posts,
		$block
	);

	$wrapper_attributes = get_block_wrapper_attributes();
	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		sprintf( $label, number_format_i18n( $found_posts ) )
	);
}

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
	if ( ! empty( $block['blockName'] ) && 'wporg/query-total' === $block['blockName'] ) {
		$metadata = wp_json_file_decode( __DIR__ . '/build/block.json' );
		$attributes = $metadata->attributes;

		// phpcs:disable WordPress.NamingConventions.ValidVariableName -- fontSize and textColor are valid.
		if ( isset( $attributes->textColor->default ) ) {
			// Check for a preset or custom text color.
			if ( ! isset( $block['attrs']['textColor'] ) && ! isset( $block['attrs']['style']['color']['text'] ) ) {
				$block['attrs']['textColor'] = $attributes->textColor->default;
			}
		}
		if ( isset( $attributes->fontSize->default ) ) {
			// Check for a preset or custom font size.
			if ( ! isset( $block['attrs']['fontSize'] ) && ! isset( $block['attrs']['style']['typography']['fontSize'] ) ) {
				$block['attrs']['fontSize'] = $attributes->fontSize->default;
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName
	}

	return $block;
}
