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
	);
}

add_action( 'init', __NAMESPACE__ . '\init' );
