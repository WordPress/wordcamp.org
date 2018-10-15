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
		//'editor_script'   => $script_slug,
		'render_callback' => __NAMESPACE__ . '\render',
		//'supports'        => array(
		//	'customClassName' => false,
		//),
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
			'sort'   => get_sort_options(),
			'track'  => get_available_terms( 'wcb_track' ),
			'groups' => get_available_terms( 'wcb_speaker_group' ),
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

	if ( is_array( $attributes['track'] ) ) {
		$attributes['track'] = implode( ',', $attributes['track'] );
	}

	if ( is_array( $attributes['groups'] ) ) {
		$attributes['groups'] = implode( ',', $attributes['groups'] );
	}

	return $wcpt_plugin->shortcode_speakers( $attributes, '' );
}

/**
 * Get the schema for the block's attributes.
 *
 * @return array
 */
function get_attributes_schema() {
	return array(
		'show_all_posts' => array(
			'type'    => 'bool',
			'default' => true,
		),
		'posts_per_page' => array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => 100,
			'default' => 10,
		),
		'track'          => array(
			'type'  => 'array',
			'items' => array(
				'type' => 'string',
				'enum' => wp_list_pluck( get_available_terms( 'wcb_track' ), 'value' ),
			),
		),
		'groups'         => array(
			'type'  => 'array',
			'items' => array(
				'type' => 'string',
				'enum' => wp_list_pluck( get_available_terms( 'wcb_speaker_group' ), 'value' ),
			),
		),
		'sort'           => array(
			'type'    => 'string',
			'enum'    => wp_list_pluck( get_sort_options(), 'value' ),
			'default' => 'title_asc',
		),
		'speaker_link'   => array(
			'type'    => 'bool',
			'default' => false,
		),
		'show_avatars'   => array(
			'type'    => 'bool',
			'default' => true,
		),
		'avatar_size'    => array(
			'type'    => 'integer',
			'minimum' => 64,
			'maximum' => 512,
			'default' => 100,
		),
	);
}

/**
 * Get the labels and values of the Sort By options.
 *
 * @return array
 */
function get_sort_options() {
	return array(
		array(
			'label' => _x( 'A → Z', 'sort option', 'wordcamporg' ),
			'value' => 'title_asc',
		),
		array(
			'label' => _x( 'Z → A', 'sort option', 'wordcamporg' ),
			'value' => 'title_desc',
		),
		array(
			'label' => _x( 'Newest to Oldest', 'sort option', 'wordcamporg' ),
			'value' => 'date_desc',
		),
		array(
			'label' => _x( 'Oldest to Newest', 'sort option', 'wordcamporg' ),
			'value' => 'date_asc',
		),
		array(
			'label' => _x( 'Random', 'sort option', 'wordcamporg' ),
			'value' => 'rand_asc',
		),
	);
}

/**
 * Get the available terms, each with label and value, for a particular taxonomy.
 *
 * @param string $taxonomy_id
 *
 * @return array
 */
function get_available_terms( $taxonomy_id ) {
	$all_items = array(
		array(
			'label' => __( 'All', 'wordcamporg' ),
			'value' => '',
		),
	);

	$terms = get_terms( array(
		'taxonomy'   => $taxonomy_id,
		'hide_empty' => false,
	) );

	if ( ! is_array( $terms ) ) {
		return $all_items;
	}

	return array_reduce( $terms, function( $carry, $item ) {
		$carry[] = array(
			'label' => $item->name,
			'value' => $item->slug,
		);

		return $carry;
	}, $all_items );
}
