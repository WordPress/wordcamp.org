<?php

namespace WordCamp\Blocks\Schedule;

defined( 'WPINC' ) || die();


/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	register_block_type(
		'wordcamp/schedule',
		array(
			'attributes'      => get_attributes_schema(),
			'render_callback' => __NAMESPACE__ . '\render',
			'editor_script'   => 'wordcamp-blocks',
			'editor_style'    => 'wordcamp-blocks',
			'style'           => 'wordcamp-blocks',
		)
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
	// @todo Render via JS instead?
	return '';
}

/**
 * Add data to be used by the JS scripts in the block editor.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	$data['schedule'] = array(
		'schema'  => get_attributes_schema(),
		'options' => get_options(),
	);

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );

/**
 * Get the schema for the block's attributes.
 *
 * @return array
 */
function get_attributes_schema() {
	// todo need to update this when building inspector controls, to make them DRY
	// this one will be different than others in that it wont have a 'mode', so need to make that not a shared attribute?
		// or maybe leave it shared since most blocks use it, but explicitly remove it here?

	return array(
		'item_ids' => array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array(
				'type' => 'integer',
			),
		),

		'className' => array(
			'type'    => 'string',
			'default' => '',
		),

		'show_categories' => array(
			'type'    => 'bool',
			'default' => false,
		),

		'chosen_days' => array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array(
				'type' => 'string',
			),
		),

		'chosen_tracks' => array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array(
				'type' => 'integer',
			),
		),
	);
}

/**
 * Get the label/value pairs for all options or a specific type.
 *
 * @param string $type
 *
 * @return array
 */
function get_options( $type = '' ) {
	$options = array();

	if ( $type ) {
		return empty( $options[ $type ] ) ? array() : $options[ $type ];
	}

	return $options;
}
