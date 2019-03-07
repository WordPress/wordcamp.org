<?php
namespace WordCamp\Blocks\Sponsors;
defined( 'WPINC' ) || die();

use WordCamp\Blocks;

defined( 'WPINC' ) || die();

/**
 * Register sponsor block and enqueue scripts.
 */
function init() {
	register_block_type(
		'wordcamp/sponsors',
		[
			'attributes'      => get_attributes_schema(),
			'editor_script'   => 'wordcamp-blocks',
			'editor_style'    => 'wordcamp-blocks',
			'style'           => 'wordcamp-blocks',
			'render_callback' => __NAMESPACE__ . '\render',
		]
	);
}

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Renders content of Sponsor block based on attributes.
 *
 * @param $attributes
 *
 * @return false|string
 */
function render( $attributes ) {
	$html = '';
	$sponsors = get_sponsor_posts( $attributes );

	$sponsor_featured_urls = array();

	if ( ! empty( $attributes['sponsor_image_urls'] ) ) {
		$sponsor_featured_urls = json_decode( urldecode( $attributes['sponsor_image_urls'] ), true );
	}

	$container_classes = array(
		'wordcamp-sponsors-block',
		'wordcamp-sponsors-list',
	);

	if ( ! empty( $attributes['columns'] ) && 1 !== $attributes['columns'] ) {
		$columns = (int) $attributes['columns'];
		$container_classes[] = 'grid-columns-' . $columns;
		$container_classes[] = 'layout-grid';
		$container_classes[] = 'layout-' . $columns;
	}
	$container_classes = implode( ' ', $container_classes );

	if ( $attributes['mode'] ) {
		ob_start();
		require Blocks\PLUGIN_DIR . 'view/sponsors.php';
		$html = ob_get_clean();
	}

	return $html;
}

/**
 * Return sponsor posts what will rendered based on attributes.
 *
 * @param $attributes
 *
 * @return array
 */
function get_sponsor_posts( $attributes ) {
	if ( empty( $attributes[ 'mode' ] ) ) {
		return array();
	}

	$post_args = array(
		'post_type'      => 'wcb_sponsor',
		'post_status'    => 'publish',
		'posts_per_page' => - 1,
		'orderby'        => 'title',
		'order'          => 'asc',
	);

	switch ( $attributes[ 'mode' ] ) {
		case 'specific_posts':
			$post_args['post__in'] = $attributes['post_ids'];
			break;
		case 'specific_terms':
			$post_args['tax_query'] = [
				[
					'taxonomy' => 'wcb_sponsor_level',
					'field'    => 'id',
					'terms'    => $attributes['term_ids'],
				],
			];
			break;
	}
	return get_posts( $post_args );
}

/**
 * Get attribute schema for Sponsor block
 *
 * @return array
 */
function get_attributes_schema() {
	return array(
		'mode' => array(
			'type' => 'string',
		),
		'post_ids' => array(
			'type' => 'array',
			'default' => array(),
			'items' => array(
				'type' => 'integer',
			),
		),
		'term_ids' => array(
			'type' => 'array',
			'default' => array(),
			'items' => array(
				'type' => 'integer',
			),
		),
		'sponsor_image_urls' => array(
			'type' => 'string',
			'default' => '{}',
		),
		'show_name' => array(
			'type' => 'bool',
			'default' => true,
		),
		'show_logo' => array(
			'type' => 'bool',
			'default' => true,
		),
		'show_desc' => array(
			'type' => 'bool',
			'default' => true,
		),
		'columns' => array(
			'type' => 'integer',
			'minimum' => 1,
			'maximum' => 4,
			'default' => 1
		),
		'sponsor_logo_height' => array(
			'type' => 'integer',
			'default' => 150
		),
		'sponsor_logo_width' => array(
			'type' => 'integer',
			'default' => 150
		),
	);
}
