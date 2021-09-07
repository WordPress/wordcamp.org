<?php
/**
 * Utilities and helper functions for WordCamp Post Types.
 *
 * @package WordCamp\Post_Types\Utilities
 */

namespace WordCamp\Post_Types\Utilities;
use WP_Post;

defined( 'WPINC' ) || die();

/**
 * Get the user's avatar or featured image given a Speaker or Organizer post.
 *
 * @param int|WP_Post $post Post ID or post object.
 * @param int         $size Size for avatar.
 *
 * @return string
 */
function get_avatar_or_image( $post, $size ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}

	$email       = get_post_meta( $post->ID, '_wcb_speaker_email', true );
	$user_id     = get_post_meta( $post->ID, '_wcpt_user_id', true );
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
		get_the_title( $post->ID )
	);

	// Remove Jetpack filter so that we can always get the featured image.
	if ( function_exists( 'jetpack_featured_images_remove_post_thumbnail' ) ) {
		remove_filter( 'get_post_metadata', 'jetpack_featured_images_remove_post_thumbnail', true, 4 );
		$featured_image = get_the_post_thumbnail( $post->ID, array( $size, $size ) );
		add_filter( 'get_post_metadata', 'jetpack_featured_images_remove_post_thumbnail', true, 4 );
	} else {
		$featured_image = get_the_post_thumbnail( $post->ID, array( $size, $size ) );
	}

	// If there is a featured image, it should override the gravatar.
	if ( $featured_image ) {
		$avatar = $featured_image;
	}

	return $avatar;
}
