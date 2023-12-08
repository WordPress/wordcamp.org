<?php
/**
 * Block Name: Dynamic Table of Contents
 * Description: A dynamic list of headings in the current page.
 *
 * @package wporg
 */

namespace WordPressdotorg\MU_Plugins\Dynamic_ToC_Block;

add_action( 'init', __NAMESPACE__ . '\init' );

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
 * Render the block content.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block markup.
 */
function render( $attributes, $content, $block ) {
	if ( ! isset( $block->context['postId'] ) ) {
		return '';
	}

	$post         = get_post( $block->context['postId'] );
	$post_content = apply_filters( 'wporg_table_of_contents_post_content', get_the_content( null, false, $post ) );
	$items        = get_headings( $post_content );
	if ( ! $items ) {
		return '';
	}

	/**
	 * Filters the title for the Table of Contents.
	 *
	 * @param string $title   The title to display.
	 * @param int    $post_id The current post ID.
	 */
	$title = apply_filters( 'wporg_table_of_contents_heading', __( 'In this article', 'wporg' ), $post->ID );

	$content = '<div class="wporg-table-of-contents__header">';
	$content .= do_blocks(
		'<!-- wp:heading {"style":{"typography":{"fontStyle":"normal","fontWeight":"400"},"spacing":{"margin":{"top":"0","bottom":"0"}}},"fontSize":"normal","fontFamily":"inter"} -->
		<h2 class="wp-block-heading has-inter-font-family has-normal-font-size" style="margin-top:0;margin-bottom:0;font-style:normal;font-weight:400">' . esc_html( $title ) . '</h2>
		<!-- /wp:heading -->'
	);
	$content .= '<button type="button" class="wporg-table-of-contents__toggle" aria-expanded="false">';
	$content .= '<span class="screen-reader-text">' . esc_html__( 'Table of Contents', 'wporg' ) . '</span>';
	$content .= '</button>';
	$content .= '</div>';

	$content .= '<ul class="wporg-table-of-contents__list">';

	$last_item = false;

	foreach ( $items as $item ) {
		if ( $last_item ) {
			if ( $last_item < $item['level'] ) {
				$content .= "\n<ul>\n";
			} elseif ( $last_item > $item['level'] ) {
				$content .= "\n</ul></li>\n";
			} else {
				$content .= "</li>\n";
			}
		}

		$last_item = $item['level'];

		$content .= '<li><a href="#' . esc_attr( $item['id'] ) . '">' . wp_strip_all_tags( $item['title'] ) . '</a>';
	}

	$content .= "</ul>\n";

	// Use the parsed headings & IDs to inject IDs into the post content.
	add_filter(
		'the_content',
		function( $content ) use ( $items ) {
			return inject_ids_into_headings( $content, $items );
		},
		5 // Run early, before special character handling, so the items match.
	);

	$wrapper_attributes = get_block_wrapper_attributes();
	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		$content
	);
}

/**
 * Get headings from a content string.
 *
 * @param string $content The post content.
 *
 * @return array A list of heading objects.
 */
function get_headings( $content ) {
	$tag = 'h(?P<level>[1-3])';
	preg_match_all( "/(?P<tag><{$tag}(?P<attrs>[^>]*)>)(?P<title>.*?)(<\/{$tag}>)/iJ", $content, $matches, PREG_SET_ORDER );

	foreach ( $matches as $i => $item ) {
		// Set an ID property to prevent warnings later.
		$matches[ $i ]['id'] = '';

		// Remove heading if there is no plain text.
		if ( empty( trim( wp_strip_all_tags( $item['title'] ) ) ) ) {
			unset( $matches[ $i ] );
		}
	}

	if ( count( $matches ) < 2 ) {
		return array();
	}

	$reserved_ids = (array) apply_filters(
		'handbooks_reserved_ids',
		array(
			'main',
			'masthead',
			'menu-header',
			'page',
			'primary',
			'secondary',
			'secondary-content',
			'site-navigation',
			'wordpress-org',
			'wp-toolbar',
			'wpadminbar',
			'wporg-footer',
			'wporg-header',
		)
	);

	// Generate IDs for the headings.
	foreach ( $matches as $i => $item ) {
		$used_ids            = array_filter( wp_list_pluck( $matches, 'id' ) );
		$matches[ $i ]['id'] = get_id_for_item( $item, array_merge( $reserved_ids, $used_ids ) );
	}

	return $matches;
}

/**
 * Generate an ID for a given HTML element, use the tags `id` attribute if set.
 *
 * @param array    $item     A single heading item.
 * @param string[] $used_ids The list of existing IDs plus reserved IDs.
 *
 * @return string A unique ID.
 */
function get_id_for_item( $item, $used_ids ) {
	if ( ! empty( $item['id'] ) ) {
		return $item['id'];
	}

	// Check to see if the item already had a non-empty ID, else generate one from the title.
	if ( preg_match( '/id=(["\'])(?P<id>[^"\']+)\\1/', $item['attrs'], $m ) ) {
		$id = $m['id'];
	} else {
		$id = sanitize_title( $item['title'] );
	}

	// Append unique suffix if anchor ID isn't unique in the document.
	$count   = 2;
	$orig_id = $id;
	while ( in_array( $id, $used_ids, true ) && $count < 50 ) {
		$id = $orig_id . '-' . $count;
		$count++;
	}

	return $id;
}

/**
 * Replace the headings in a content string with headings including the ID attribute.
 *
 * @param string $content The post content.
 * @param array  $items   The headings as parsed from the content, plus unique IDs.
 */
function inject_ids_into_headings( $content, $items ) {
	$matches      = [];
	$replacements = [];

	foreach ( $items as $item ) {
		$matches[]   = $item[0];
		$tag         = 'h' . $item['level'];
		$id          = $item['id'];
		$title       = wp_strip_all_tags( $item['title'] );
		$extra_attrs = $item['attrs'];
		$class_name  = 'is-toc-heading';

		if ( $extra_attrs ) {
			// Strip all IDs from the heading attributes (including empty), we'll replace it with one below.
			$extra_attrs = trim( preg_replace( '/id=(["\'])[^"\']*\\1/i', '', $extra_attrs ) );

			// Extract any classes present, we're adding our own attribute.
			if ( preg_match( '/class=(["\'])(?P<class>[^"\']+)\\1/i', $extra_attrs, $m ) ) {
				$extra_attrs = str_replace( $m[0], '', $extra_attrs );
				$class_name .= ' ' . $m['class'];
			}
		}

		$replacements[] = sprintf(
			'<%1$s id="%2$s" class="%3$s" tabindex="-1" %4$s><a href="#%2$s">%5$s</a></%1$s>',
			$tag,
			$id,
			$class_name,
			$extra_attrs,
			$title
		);
	}

	if ( $replacements ) {
		if ( count( array_unique( $matches ) ) !== count( $matches ) ) {
			foreach ( $matches as $i => $match ) {
				$content = preg_replace( '/' . preg_quote( $match, '/' ) . '/', $replacements[ $i ], $content, 1 );
			}
		} else {
			$content = str_replace( $matches, $replacements, $content );
		}
	}

	return $content;
}
