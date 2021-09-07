<?php
namespace WordCamp\Blocks\Avatar;

defined( 'WPINC' ) || die();

/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	register_block_type_from_metadata(
		__DIR__,
		array(
			'attributes'      => get_attributes_schema(),
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\init' );


/**
 * Renders the block on the server.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 * @return string Returns the avatar for the current post.
 */
function render( $attributes, $content, $block ) {
	if ( ! isset( $block->context['postId'] ) ) {
		return '';
	}
	$post_ID = $block->context['postId'];

	$defaults   = wp_list_pluck( get_attributes_schema(), 'default' );
	$attributes = wp_parse_args( $attributes, $defaults );
	$email      = get_post_meta( $post_ID, '_wcb_speaker_email', true );
	$user_id    = get_post_meta( $post_ID, '_wcpt_user_id', true );

	$size = intval( $attributes['size'] );
	$wrapper_attributes = get_block_wrapper_attributes( array(
		'style' => "width:{$size}px;height:{$size}px;",
	) );
	$id_or_email = $email ? $email : $user_id;

	// Get the gravatar source, or the default if no user info is set.
	if ( $id_or_email ) {
		$src = get_avatar_url( $id_or_email, array( 'size' => $size ) );
	} else {
		$src = get_avatar_url(
			0,
			array(
				'size' => $size,
				'force_default' => true,
			)
		);
	}

	$avatar = sprintf(
		'<img src="%1$s" alt="%2$s" />',
		esc_url( $src ),
		get_the_title( $post_ID )
	);

	// Remove Jetpack filter so that we can always get the featured image.
	remove_filter( 'get_post_metadata', 'jetpack_featured_images_remove_post_thumbnail', true, 4 );
	$featured_image = get_the_post_thumbnail( $post_ID );
	add_filter( 'get_post_metadata', 'jetpack_featured_images_remove_post_thumbnail', true, 4 );

	// If there is a featured image, it should override the gravatar.
	if ( $featured_image ) {
		$avatar = $featured_image;
	}

	if ( isset( $attributes['isLink'] ) && $attributes['isLink'] ) {
		$avatar = sprintf( '<a href="%1s">%2s</a>', get_the_permalink( $post_ID ), $avatar );
	}

	return "<figure $wrapper_attributes>$avatar</figure>";
}

/**
 * Add data to be used by the JS scripts in the block editor.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	$data['avatar'] = array(
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
	$schema = array(
		'isLink' => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'size'   => array(
			'type'    => 'number',
			'enum'    => get_options( 'size' ),
			'default' => 96,
		),
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
	$options = array(
		'size' => rest_get_avatar_sizes(),
	);

	if ( $type ) {
		return empty( $options[ $type ] ) ? array() : $options[ $type ];
	}

	return $options;
}
