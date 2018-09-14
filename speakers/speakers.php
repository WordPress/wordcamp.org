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
			'l10n'    => get_l10n_strings(),
			'schema'  => get_attributes_schema(),
			'options' => array(
				'sort'   => get_sort_options(),
				'track'  => get_available_terms( 'wcb_track' ),
				'groups' => get_available_terms( 'wcb_speaker_group' ),
			),
		)
	);

	register_block_type( 'wordcamp/speakers', array(
		'attributes'      => get_attributes_schema(),
		'editor_script'   => $script_slug,
		'render_callback' => __NAMESPACE__ . '\render',
		'supports'        => array(
			'customClassName' => false,
		),
	) );
}

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 *
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
 *
 *
 * @return array
 */
function get_l10n_strings() {
	return array(
		'block_label'               => __( 'Speakers', 'wordcamporg' ),
		'block_description'         => __( 'Add a list of speakers.' ),

		'speaker_posts_panel_title' => __( 'Speaker Settings', 'wordcamporg' ),
		'show_all_posts_label'      => __( 'Show all', 'wordcamporg' ),
		'posts_per_page_label'      => __( 'Number to show', 'wordcamporg' ),
		'track_label'               => __( 'From which session tracks?', 'wordcamporg' ),
		'track_help'                => __( 'Multiple tracks can be chosen.', 'wordcamporg' ),
		'groups_label'              => __( 'From which speaker groups?', 'wordcamporg' ),
		'groups_help'               => __( 'Multiple groups can be chosen.', 'wordcamporg' ),
		'sort_label'                => __( 'Sort by', 'wordcamporg' ),
		'speaker_link_label'        => __( 'Link titles to single posts', 'wordcamporg' ),
		'speaker_link_help'         => __( 'These will not appear in the block preview.', 'wordcamporg' ),
		'show_avatars_label'        => __( 'Show avatars', 'wordcamporg' ),
		'avatar_size_label'         => __( 'Avatar size (px)', 'wordcamporg' ),
		'avatar_size_help'          => __( 'Height and width in pixels.', 'wordcamporg' ),
	);
}

/**
 *
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
		'className'      => array(
			'type' => 'string',
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
 *
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
