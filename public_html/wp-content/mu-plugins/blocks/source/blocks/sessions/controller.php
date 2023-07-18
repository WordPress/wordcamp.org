<?php
namespace WordCamp\Blocks\Sessions;

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
		'wordcamp/sessions',
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
	$current_post = get_post();

	if ( 'revision' === $current_post->post_type ) {
		$current_post = get_post( $current_post->post_parent );
	}

	// Showing sessions inside a session can lead to an infinite loop, and doesn't have any apparent use cases.
	if ( 'wcb_session' === $current_post->post_type ) {
		return '<div class="notice notice-error"> <p> This block can\'t be used inside a Session post. It\'s intended to be used in a page or post. </p> </div>';
	}

	$defaults   = wp_list_pluck( get_attributes_schema(), 'default' );
	$attributes = wp_parse_args( $attributes, $defaults );
	$sessions   = get_session_posts( $attributes );

	$speakers = array();
	if ( ! empty( $sessions ) && true === $attributes['show_speaker'] ) {
		$speakers = get_session_speakers( wp_list_pluck( $sessions, 'ID' ) );
	}

	$rendered_session_posts = array();

	foreach ( $sessions as $session ) {
		ob_start();
		require Blocks\PLUGIN_DIR . 'source/blocks/sessions/view.php';
		$rendered_session_posts[] = ob_get_clean();
	}

	$container_classes = array(
		'wordcamp-sessions',
		sanitize_html_class( $attributes['className'] ),
	);

	if ( ! empty( $attributes['align'] ) ) {
		$container_classes[] = 'align' . sanitize_html_class( $attributes['align'] );
	}

	return render_post_list( $rendered_session_posts, $attributes['layout'], $attributes['grid_columns'], $container_classes );
}

/**
 * Add data to be used by the JS scripts in the block editor.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	$data['sessions'] = array(
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
	$schema = array_merge(
		get_shared_definitions(
			array(
				'content',
				'grid_columns',
				'item_ids',
				'layout',
			),
			'attribute'
		),
		array(
			'align'                => get_shared_definition( 'align_block', 'attribute' ),
			'className'            => get_shared_definition( 'string_empty', 'attribute' ),
			'featured_image_width' => get_shared_definition( 'image_size', 'attribute' ),
			'headingAlign'         => get_shared_definition( 'align_content', 'attribute' ),
			'image_align'          => get_shared_definition( 'align_image', 'attribute' ),
			'mode'                 => array(
				'type'    => 'string',
				'enum'    => wp_list_pluck( get_options( 'mode' ), 'value' ),
				'default' => '',
			),
			'show_category'        => get_shared_definition( 'boolean_false', 'attribute' ),
			'show_images'          => get_shared_definition( 'boolean_true', 'attribute' ),
			'show_meta'            => get_shared_definition( 'boolean_false', 'attribute' ),
			'show_speaker'         => get_shared_definition( 'boolean_false', 'attribute' ),
			'sort'                 => array(
				'type'    => 'string',
				'enum'    => wp_list_pluck( get_options( 'sort' ), 'value' ),
				'default' => 'session_time',
			),
		)
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
			array(
				'align_block',
				'align_image',
				'content',
				'layout',
			),
			'option'
		),
		array(
			'mode' => array(
				array(
					'label' => '',
					'value' => '',
				),
				array(
					'label' => _x( 'List all sessions', 'mode option', 'wordcamporg' ),
					'value' => 'all',
				),
				array(
					'label' => _x( 'Choose sessions', 'mode option', 'wordcamporg' ),
					'value' => 'wcb_session',
				),
				array(
					'label' => _x( 'Choose tracks', 'mode option', 'wordcamporg' ),
					'value' => 'wcb_track',
				),
				array(
					'label' => _x( 'Choose session categories', 'mode option', 'wordcamporg' ),
					'value' => 'wcb_session_category',
				),
			),
			'sort' => array_merge(
				array(
					array(
						'label' => _x( 'Day and Time', 'sort option', 'wordcamporg' ),
						'value' => 'session_time',
					),
				),
				get_shared_definition( 'sort_title', 'option' ),
				get_shared_definition( 'sort_date', 'option' )
			),
		)
	);

	if ( $type ) {
		if ( ! empty( $options[ $type ] ) ) {
			return $options[ $type ];
		} else {
			return array();
		}
	}

	return $options;
}

/**
 * Get the posts to display in the block.
 *
 * @param array $attributes
 *
 * @return array
 */
function get_session_posts( array $attributes ) {
	if ( empty( $attributes['mode'] ) ) {
		return array();
	}

	$post_args = array(
		'post_type'      => 'wcb_session',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_wcpt_session_type',
					'value'   => 'session',
					'compare' => '=',
				),
				array(
					'key'     => '_wcpt_session_type',
					'value'   => '',
					'compare' => 'NOT EXISTS',
				),
			),
		),
	);

	switch ( $attributes['sort'] ) {
		case 'session_time':
			$post_args['meta_key'] = '_wcpt_session_time';
			$post_args['orderby']  = 'meta_value_num title';
			$post_args['order']    = 'asc';
			break;

		case 'title_asc':
		case 'title_desc':
		case 'date_desc':
		case 'date_asc':
			$sort = explode( '_', $attributes['sort'] );

			if ( 2 === count( $sort ) ) {
				$post_args['orderby'] = $sort[0];
				$post_args['order']   = $sort[1];
			}
			break;
	}

	switch ( $attributes['mode'] ) {
		case 'wcb_session':
			$post_args['post__in'] = $attributes['item_ids'];
			break;

		case 'wcb_track':
		case 'wcb_session_category':
			$post_args['tax_query'] = array(
				array(
					'taxonomy' => $attributes['mode'],
					'field'    => 'id',
					'terms'    => $attributes['item_ids'],
				),
			);
			break;
	}

	return get_posts( $post_args );
}

/**
 * Get speaker posts grouped by session.
 *
 * @param array $session_ids
 *
 * @return array
 */
function get_session_speakers( array $session_ids ) {
	$speakers_by_session = array();

	$session_args = array(
		'post_type'      => 'wcb_session',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'post__in'       => $session_ids,
	);

	$session_posts = get_posts( $session_args );

	foreach ( $session_posts as $session ) {
		$speaker_ids = get_post_meta( $session->ID, '_wcpt_speaker_id', false );

		if ( ! empty( $speaker_ids ) ) {
			$speaker_args = array(
				'post_type'      => 'wcb_speaker',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'post__in'       => $speaker_ids,
				'orderby'        => 'post__in',
				'order'          => 'ASC',
			);

			$speakers_by_session[ $session->ID ] = get_posts( $speaker_args );
		}
	}

	return $speakers_by_session;
}
