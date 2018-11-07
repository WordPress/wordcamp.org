<?php

namespace WordCamp\Blocks\Speakers;
defined( 'WPINC' ) || die();

use WordCamp_Post_Types_Plugin;

/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	register_block_type( 'wordcamp/speakers', array(
		'attributes'      => get_attributes_schema(),
		'render_callback' => __NAMESPACE__ . '\render',
	) );
}

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 *
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	$data['speakers'] = [
		'schema'  => get_attributes_schema(),
		'options' => array(
			'align'   => get_options( 'align' ),
			'content' => get_options( 'content' ),
			'mode'    => get_options( 'mode' ),
			'sort'    => get_options( 'sort' ),
		),
	];

	return $data;
}

add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );

/**
 * Run the shortcode callback after normalizing attribute values.
 *
 * @return string
 */
function render( $attributes ) {
	/** @var WordCamp_Post_Types_Plugin $wcpt_plugin */
	global $wcpt_plugin;

	if ( true === $attributes['show_all_posts'] ) {
		$attributes['posts_per_page'] = -1;
	}

	$sort = explode( '_', $attributes['sort'] );

	if ( 2 === count( $sort ) ) {
		$attributes['orderby'] = $sort[0];
		$attributes['order']   = $sort[1];
	}

	if ( true === $attributes['speaker_link'] ) {
		$attributes['speaker_link'] = 'permalink';
	}

	return $wcpt_plugin->shortcode_speakers( $attributes, '' );
}

/**
 * Get the schema for the block's attributes.
 *
 * @return array
 */
function get_attributes_schema() {
	return [
		'mode'           => [
			'type'    => 'string',
			'enum'    => wp_list_pluck( get_options( 'mode' ), 'value' ),
			'default' => '',
		],
		'post_ids'       => [
			'type'    => 'array',
			'default' => [],
			'items'   => [
				'type' => 'integer',
			],
		],
		'term_ids'       => [
			'type'    => 'array',
			'default' => [],
			'items'   => [
				'type' => 'integer',
			],
		],
		'show_avatars'   => [
			'type'    => 'bool',
			'default' => true,
		],
		'avatar_size'    => [
			'type'    => 'integer',
			'minimum' => 25,
			'maximum' => 600,
			'default' => 150,
		],
		'avatar_align'   => [
			'type'    => 'string',
			'enum'    => wp_list_pluck( get_options( 'align' ), 'value' ),
			'default' => 'none',
		],
		'content'        => [
			'type'    => 'string',
			'enum'    => wp_list_pluck( get_options( 'content' ), 'value' ),
			'default' => 'full',
		],
		'excerpt_length' => [
			'type'    => 'integer',
			'minimum' => 10,
			'maximum' => 1000,
			'default' => 55,
		],
		'speaker_link'   => array(
			'type'    => 'bool',
			'default' => false,
		),
		'show_session'   => array(
			'type'    => 'bool',
			'default' => false,
		),
		'sort'           => array(
			'type'    => 'string',
			'enum'    => wp_list_pluck( get_options( 'sort' ), 'value' ),
			'default' => 'title_asc',
		),
		'className'      => array(
			'type' => 'string',
		),
	];
}

/**
 * Get the label/value pairs for a type of options.
 *
 * @param string $type
 *
 * @return array
 */
function get_options( $type ) {
	$options = [];

	switch ( $type ) {
		case 'align':
			$options = [
				[
					'label' => _x( 'None', 'alignment option', 'wordcamporg' ),
					'value' => 'none',
				],
				[
					'label' => _x( 'Left', 'alignment option', 'wordcamporg' ),
					'value' => 'left',
				],
				[
					'label' => _x( 'Center', 'alignment option', 'wordcamporg' ),
					'value' => 'center',
				],
				[
					'label' => _x( 'Right', 'alignment option', 'wordcamporg' ),
					'value' => 'right',
				],
			];
			break;
		case 'content':
			$options = [
				[
					'label' => _x( 'Full', 'content option', 'wordcamporg' ),
					'value' => 'full',
				],
				[
					'label' => _x( 'Excerpt', 'content option', 'wordcamporg' ),
					'value' => 'excerpt',
				],
				[
					'label' => _x( 'None', 'content option', 'wordcamporg' ),
					'value' => 'none',
				],
			];
			break;
		case 'mode':
			$options = [
				[
					'label' => '',
					'value' => '',
				],
				[
					'label' => _x( 'List all speakers', 'mode option', 'wordcamporg' ),
					'value' => 'query',
				],
				[
					'label' => _x( 'Choose specific speakers', 'mode option', 'wordcamporg' ),
					'value' => 'specific',
				],
			];
			break;
		case 'sort':
			$options = [
				[
					'label' => _x( 'A → Z', 'sort option', 'wordcamporg' ),
					'value' => 'title_asc',
				],
				[
					'label' => _x( 'Z → A', 'sort option', 'wordcamporg' ),
					'value' => 'title_desc',
				],
				[
					'label' => _x( 'Newest to Oldest', 'sort option', 'wordcamporg' ),
					'value' => 'date_desc',
				],
				[
					'label' => _x( 'Oldest to Newest', 'sort option', 'wordcamporg' ),
					'value' => 'date_asc',
				],
			];
			break;
	}

	return $options;
}
