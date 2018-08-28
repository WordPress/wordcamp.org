<?php

namespace WordCamp\Blocks\Speakers;
defined( 'WPINC' ) || die();

use WordCamp_Post_Types_Plugin;

/**
 *
 */
function init() {
	$script_slug     = 'block-wordcamp-speakers';
	$script_filename = 'speakers.js';

	wp_register_script(
		$script_slug,
		plugins_url( $script_filename, __FILE__ ),
		array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-editor' ),
		filemtime( trailingslashit( plugin_dir_path( __FILE__ ) ) . $script_filename )
	);

	wp_localize_script(
		$script_slug,
		'WordCampBlocksSpeakers',
		array(
			'l10n'   => get_l10n_strings(),
			'schema' => get_attributes_schema(),
		)
	);

	register_block_type( 'wordcamp/speakers', array(
		'attributes'      => get_attributes_schema(),
		'editor_script'   => $script_slug,
		'render_callback' => __NAMESPACE__ . '\render',
	) );
}

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 *
 */
function render( $attributes ) {
	/** @var WordCamp_Post_Types_Plugin $wcpt_plugin */
	global $wcpt_plugin;

	$sort = explode( '_', $attributes['sort'] );

	if ( 2 === count( $sort ) ) {
		$attributes['orderby'] = $sort[0];
		$attributes['order']   = $sort[1];
	}

	return $wcpt_plugin->shortcode_speakers( $attributes, '' );
}

/**
 *
 *
 * @return array
 */
function get_l10n_strings() {
	return array(
		'block_label'          => __( 'Speakers', 'wordcamporg' ),
		'block_description'    => __( 'Add a summary of information about speakers.' ),
		'avatars_panel_title'  => __( 'Avatar settings', 'wordcamporg' ),
		'show_avatars_label'   => __( 'Show avatars', 'wordcamporg' ),
		'avatar_size_label'    => __( 'Avatar size (px)', 'wordcamporg' ),
		'posts_per_page_label' => __( 'Number', 'wordcamporg' ),
		'sort_label'           => __( 'Sort by', 'wordcamporg' ),
		'speaker_link_label'   => __( 'Speaker link', 'wordcamporg' ),
		'track_label'          => __( 'Track', 'wordcamporg' ),
		'groups_label'         => __( 'Groups', 'wordcamporg' ),
	);
}

/**
 *
 *
 * @return array
 */
function get_attributes_schema() {
	return array(
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
		'posts_per_page' => array(
			'type'    => 'integer',
			'minimum' => -1,
			'maximum' => 9999,
		),
		'sort'           => array(
			'type'    => 'string',
			'enum'    => array_keys( get_sort_options() ),
			'default' => 'title_asc',
		),
		'speaker_link'   => array(
			'type' => 'string',
			'enum' => array_keys( get_speaker_link_options() ),
		),
		'track'          => array(
			'type' => 'string',
			'enum' => array_keys( get_available_terms( 'wcb_track' ) ),
		),
		'groups'         => array(
			'type' => 'string',
			'enum' => array_keys( get_available_terms( 'wcb_speaker_group' ) ),
		),
	);
}

/**
 *
 *
 * @return array
 */
function get_sort_options() {
	return array(
		'title_asc'  => _x( 'Name, A-Z', 'sort option', 'wordcamporg' ),
		'title_desc' => _x( 'Name, Z-A', 'sort option', 'wordcamporg' ),
		'date_asc'   => _x( 'Date, oldest to newest', 'sort option', 'wordcamporg' ),
		'date_desc'  => _x( 'Date, newest to oldest', 'sort option', 'wordcamporg' ),
		'rand_asc'   => _x( 'Random', 'sort option', 'wordcamporg' ),
	);
}

/**
 *
 *
 * @return array
 */
function get_speaker_link_options() {
	return array(
		'permalink' => __( 'Permalink', 'wordcamporg' ),
		'none'      => __( 'None', 'wordcamporg' ),
	);
}

/**
 *
 *
 * @param string $taxonomy_id
 *
 * @return array
 */
function get_available_terms( $taxonomy_id ) {
	$terms = get_terms( array(
		'taxonomy'   => $taxonomy_id,
		'hide_empty' => false,
	) );

	return array_reduce( $terms, function( $carry, $item ) {
		$carry[ $item->slug ] = $item->name;

		return $carry;
	}, array() );
}
