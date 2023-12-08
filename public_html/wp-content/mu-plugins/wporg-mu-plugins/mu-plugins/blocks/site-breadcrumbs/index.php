<?php
/**
 * Block Name: Site Breadcrumbs
 * Description: Display breadcrumbs of the site.
 *
 * @package wporg
 */

namespace WordPressdotorg\MU_Plugins\Site_Breadcrumbs;

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
			'render_callback' => __NAMESPACE__ . '\render_block',
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
function render_block( $attributes, $content, $block ) {
	$breadcrumbs = array(
		array(
			'url'   => get_site_url(),
			'title' => get_bloginfo( 'name', 'display' ),
		),
	);

	$title = get_the_title();

	if ( is_home() ) {
		$title = esc_html__( 'Archives', 'wporg' );
	} elseif ( is_search() ) {
		$title = esc_html__( 'Results', 'wporg' );
	} elseif ( is_category() ) {
		$title = esc_html__( 'Categories', 'wporg' );
	} elseif ( is_tag() ) {
		$title = esc_html__( 'Tags', 'wporg' );
	}

	$breadcrumbs[] = array(
		'url'   => false,
		'title' => $title,
	);

	/**
	 * Filters the breadcrumbs used on a given page.
	 *
	 * @param array    $breadcrumbs An array of breadcrumb links, in format [url => '', title => ''].
	 * @param array    $attributes  Block attributes.
	 * @param WP_Block $block       Block instance.
	 */
	$breadcrumbs = apply_filters( 'wporg_block_site_breadcrumbs', $breadcrumbs, $attributes, $block );

	$content = '';
	foreach ( $breadcrumbs as $i => $crumb ) {
		// We can assume that the item without a URL is the current page.
		if ( ! $crumb['url'] ) {
			$content .= sprintf( '<span class="is-current-page">%s</span>', esc_html( $crumb['title'] ) );
		} else {
			$content .= sprintf( '<span><a href="%s">%s</a></span>', esc_url( $crumb['url'] ), esc_html( $crumb['title'] ) );
		}
	}

	$wrapper_attributes = get_block_wrapper_attributes();
	return sprintf( '<div role="navigation" aria-label="%s" %s>%s</div>', esc_attr__( 'Breadcrumbs', 'wporg' ), $wrapper_attributes, $content );
}
