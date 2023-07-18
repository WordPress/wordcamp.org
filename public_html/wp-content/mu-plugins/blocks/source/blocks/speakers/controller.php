<?php
namespace WordCamp\Blocks\Speakers;

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
		'wordcamp/speakers',
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
	$defaults   = wp_list_pluck( get_attributes_schema(), 'default' );
	$attributes = wp_parse_args( $attributes, $defaults );
	$speakers   = get_speaker_posts( $attributes );

	$sessions = array();
	if ( ! empty( $speakers ) && true === $attributes['show_session'] ) {
		$sessions = get_speaker_sessions( wp_list_pluck( $speakers, 'ID' ) );
	}

	$rendered_speaker_posts = array();

	foreach ( $speakers as $speaker ) {
		ob_start();
		require Blocks\PLUGIN_DIR . 'source/blocks/speakers/view.php';
		$rendered_speaker_posts[] = ob_get_clean();
	}

	$container_classes = array(
		'wordcamp-speakers',
		sanitize_html_class( $attributes['className'] ),
	);

	if ( ! empty( $attributes['align'] ) ) {
		$container_classes[] = 'align' . sanitize_html_class( $attributes['align'] );
	}

	return render_post_list( $rendered_speaker_posts, $attributes['layout'], $attributes['grid_columns'], $container_classes );
}

/**
 * Add data to be used by the JS scripts in the block editor.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	$data['speakers'] = array(
		'schema'  => get_attributes_schema(),
		'options' => get_options(),
	);

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
function get_speaker_posts( array $attributes ) {
	if ( empty( $attributes['mode'] ) ) {
		return array();
	}

	$post_args = array(
		'post_type'      => 'wcb_speaker',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
	);

	$sort = explode( '_', $attributes['sort'] );

	if ( 2 === count( $sort ) ) {
		$post_args['orderby'] = $sort[0];
		$post_args['order']   = $sort[1];
	}

	switch ( $attributes['mode'] ) {
		case 'wcb_speaker':
			$post_args['post__in'] = $attributes['item_ids'];
			break;

		case 'wcb_speaker_group':
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
 * Get session posts grouped by speaker.
 *
 * @param array $speaker_ids
 *
 * @return array
 */
function get_speaker_sessions( array $speaker_ids ) {
	$sessions_by_speaker = array();

	$session_args = array(
		'post_type'      => 'wcb_session',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_key'       => '_wcpt_session_time',
		'orderby'        => 'meta_value_num',
		'order'          => 'ASC',
	);

	$session_posts = get_posts( $session_args );

	foreach ( $session_posts as $session ) {
		$session_speaker_ids = get_post_meta( $session->ID, '_wcpt_speaker_id', false );

		foreach ( $session_speaker_ids as $speaker_id ) {
			$speaker_id = absint( $speaker_id );

			if ( in_array( $speaker_id, $speaker_ids, true ) ) {
				if ( ! isset( $sessions_by_speaker[ $speaker_id ] ) ) {
					$sessions_by_speaker[ $speaker_id ] = array();
				}

				$sessions_by_speaker[ $speaker_id ][] = $session;
			}
		}
	}

	return $sessions_by_speaker;
}

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
			'align'        => get_shared_definition( 'align_block', 'attribute' ),
			'avatar_align' => get_shared_definition( 'align_image', 'attribute' ),
			'avatar_size'  => get_shared_definition( 'image_size_avatar', 'attribute' ),
			'className'    => get_shared_definition( 'string_empty', 'attribute' ),
			'headingAlign' => get_shared_definition( 'align_content', 'attribute' ),
			'mode'         => array(
				'type'    => 'string',
				'enum'    => wp_list_pluck( get_options( 'mode' ), 'value' ),
				'default' => '',
			),
			'show_avatars' => get_shared_definition( 'boolean_true', 'attribute' ),
			'show_session' => get_shared_definition( 'boolean_false', 'attribute' ),
			'sort'         => array(
				'type'    => 'string',
				'enum'    => wp_list_pluck( get_options( 'sort' ), 'value' ),
				'default' => 'title_asc',
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
					'label' => _x( 'List all speakers', 'mode option', 'wordcamporg' ),
					'value' => 'all',
				),
				array(
					'label' => _x( 'Choose speakers', 'mode option', 'wordcamporg' ),
					'value' => 'wcb_speaker',
				),
				array(
					'label' => _x( 'Choose groups', 'mode option', 'wordcamporg' ),
					'value' => 'wcb_speaker_group',
				),
			),
			'sort' => array_merge(
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
