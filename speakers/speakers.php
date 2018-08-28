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

		'speaker_posts_panel_title' => __( 'Speaker Posts', 'wordcamporg' ),
		'show_all_posts_label'      => __( 'Show all', 'wordcamporg' ),
		'posts_per_page_label'      => __( 'Number to show', 'wordcamporg' ),
		'sort_label'                => __( 'Sort by', 'wordcamporg' ),
		'speaker_link_label'        => __( 'Link to single post', 'wordcamporg' ),
		'speaker_link_help'         => __( 'This will not appear in the block preview.', 'wordcamporg' ),

		'avatars_panel_title'       => __( 'Avatars', 'wordcamporg' ),
		'show_avatars_label'        => __( 'Show avatars', 'wordcamporg' ),
		'avatar_size_label'         => __( 'Avatar size (px)', 'wordcamporg' ),
		'avatar_size_help'          => __( 'Height and width in pixels.', 'wordcamporg' ),

		'track_panel_title'         => __( 'Session Tracks', 'wordcamporg' ),
		'track_label'               => __( 'Tracks', 'wordcamporg' ),
		'track_help'                => __( 'Multiple tracks can be chosen.', 'wordcamporg' ),

		'groups_label'              => __( 'Groups', 'wordcamporg' ),
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
		'sort'           => array(
			'type'    => 'string',
			'enum'    => wp_list_pluck( get_sort_options(), 'value' ),
			'default' => 'title_asc',
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
		'speaker_link'   => array(
			'type'    => 'bool',
			'default' => false,
		),
		'track'          => array(
			'type' => 'array',
			'enum' => wp_list_pluck( get_available_terms( 'wcb_track' ), 'value' ),
		),
		'groups'         => array(
			'type' => 'array',
			'enum' => wp_list_pluck( get_available_terms( 'wcb_speaker_group' ), 'value' ),
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
	$terms = get_terms( array(
		'taxonomy'   => $taxonomy_id,
		'hide_empty' => false,
	) );

	$all_items = array(
		array(
			'label' => __( 'All', 'wordcamporg' ),
			'value' => '',
		),
	);

	return array_reduce( $terms, function( $carry, $item ) {
		$carry[] = array(
			'label' => $item->name,
			'value' => $item->slug,
		);

		return $carry;
	}, $all_items );
}
