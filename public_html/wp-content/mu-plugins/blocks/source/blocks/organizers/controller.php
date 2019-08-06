<?php
namespace WordCamp\Blocks\Organizers;

use WordCamp\Blocks;
use function WordCamp\Blocks\Components\{ render_post_list };
use function WordCamp\Blocks\Definitions\{ get_shared_definitions, get_shared_definition };

defined( 'WPINC' ) || die();

/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	register_block_type(
		'wordcamp/organizers',
		[
			'attributes'      => get_attributes_schema(),
			'render_callback' => __NAMESPACE__ . '\render',
			'editor_script'   => 'wordcamp-blocks',
			'editor_style'    => 'wordcamp-blocks',
			'style'           => 'wordcamp-blocks',
		]
	);
}

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Render the block on the front end.
 *
 * @param array $attributes Block attributes.
 *
 * @return string
 */
function render( $attributes ) {
	$defaults   = wp_list_pluck( get_attributes_schema(), 'default' );
	$attributes = wp_parse_args( $attributes, $defaults );

	$organizers               = get_organizer_posts( $attributes );
	$rendered_organizer_posts = [];

	foreach ( $organizers as $organizer ) {
		ob_start();
		require Blocks\PLUGIN_DIR . 'source/blocks/organizers/view.php';
		$rendered_organizer_posts[] = ob_get_clean();
	}

	$container_classes = [
		'wordcamp-organizers',
		sanitize_html_class( $attributes['className'] ),
	];

	if ( ! empty( $attributes['align'] ) ) {
		$container_classes[] = 'align' . sanitize_html_class( $attributes['align'] );
	}

	return render_post_list( $rendered_organizer_posts, $attributes['layout'], $attributes['grid_columns'], $container_classes );
}

/**
 * Add data to be used by the JS scripts in the block editor.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	$data['organizers'] = [
		'schema'  => get_attributes_schema(),
		'options' => get_options(),
	];

	return $data;
}

add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );

/**
 * Get the posts to display in the block.
 *
 * @param array $attributes
 *
 * @return array
 */
function get_organizer_posts( array $attributes ) {
	if ( empty( $attributes['mode'] ) ) {
		return [];
	}

	$post_args = [
		'post_type'      => 'wcb_organizer',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
	];

	$sort = explode( '_', $attributes['sort'] );

	if ( 2 === count( $sort ) ) {
		$post_args['orderby'] = $sort[0];
		$post_args['order']   = $sort[1];
	}

	switch ( $attributes['mode'] ) {
		case 'wcb_organizer':
			$post_args['post__in'] = $attributes['item_ids'];
			break;

		case 'wcb_organizer_team':
			$post_args['tax_query'] = [
				[
					'taxonomy' => $attributes['mode'],
					'field'    => 'id',
					'terms'    => $attributes['item_ids'],
				],
			];
			break;
	}

	return get_posts( $post_args );
}

/**
 * Get the schema for the block's attributes.
 *
 * @return array
 */
function get_attributes_schema() {
	$schema = array_merge(
		get_shared_definitions(
			[
				'content',
				'grid_columns',
				'item_ids',
				'layout',
			],
			'attribute'
		),
		[
			'align'        => get_shared_definition( 'align_block', 'attribute' ),
			'avatar_align' => get_shared_definition( 'align_image', 'attribute' ),
			'avatar_size'  => get_shared_definition( 'image_size_avatar', 'attribute' ),
			'className'    => get_shared_definition( 'string_empty', 'attribute' ),
			'headingAlign' => get_shared_definition( 'align_content', 'attribute' ),
			'mode'         => [
				'type'    => 'string',
				'enum'    => wp_list_pluck( get_options( 'mode' ), 'value' ),
				'default' => '',
			],
			'show_avatars' => get_shared_definition( 'boolean_true', 'attribute' ),
			'sort'         => [
				'type'    => 'string',
				'enum'    => wp_list_pluck( get_options( 'sort' ), 'value' ),
				'default' => 'title_asc',
			],
		]
	);

	return $schema;
}

/**
 * Get the label/value pairs for all options or a specific type.
 *
 * @param string $type
 *
 * @return array
 */
function get_options( $type = '' ) {
	$options = array_merge(
		get_shared_definitions(
			[
				'align_block',
				'align_image',
				'content',
				'layout',
			],
			'option'
		),
		[
			'mode' => [
				[
					'label' => '',
					'value' => '',
				],
				[
					'label' => _x( 'List all organizers', 'mode option', 'wordcamporg' ),
					'value' => 'all',
				],
				[
					'label' => _x( 'Choose organizers', 'mode option', 'wordcamporg' ),
					'value' => 'wcb_organizer',
				],
				[
					'label' => _x( 'Choose teams', 'mode option', 'wordcamporg' ),
					'value' => 'wcb_organizer_team',
				],
			],
			'sort' => array_merge(
				get_shared_definition( 'sort_title', 'option' ),
				get_shared_definition( 'sort_date', 'option' )
			),
		]
	);

	if ( $type ) {
		return empty( $options[ $type ] ) ? [] : $options[ $type ];
	}

	return $options;
}
