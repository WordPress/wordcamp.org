<?php
namespace WordCamp\Blocks\Sponsors;
use function WordCamp\Blocks\Speakers\get_attributes_schema;

defined( 'WPINC' ) || die();

/**
 * Register sponsor block and enqueue scripts.
 */
function init() {
	register_block_type(
		'wordcamp/sponsors',
		[
			'attributes' => get_attributes_schema(),
		]
	);
}

function get_attribute_schema() {
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

add_action( 'init', __NAMESPACE__ . '\init' );
