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
 * @param string      $alt  Alt text to use, defaults to post title. Can be empty string to indicate empty alt.
 *
 * @return string
 */
function get_avatar_or_image( $post, $size, $alt = false ) {
	global $wcpt_plugin;
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	if ( false === $alt ) {
		$alt = get_the_title( $post->ID );
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

	$avatar = sprintf( '<img src="%1$s" alt="%2$s" />', esc_attr( $src ), esc_attr( $alt ) );

	// Remove the filter so that we can get the real featured image.
	remove_filter( 'get_post_metadata', array( $wcpt_plugin, 'hide_featured_image_on_people' ), 10, 3 );

	// If this Jetpack function exists, we need to remove it too.
	if ( function_exists( 'jetpack_featured_images_remove_post_thumbnail' ) ) {
		remove_filter( 'get_post_metadata', 'jetpack_featured_images_remove_post_thumbnail', true, 4 );
		$featured_image = get_the_post_thumbnail( $post->ID, array( $size, $size ), array( 'alt' => $alt ) );
		add_filter( 'get_post_metadata', 'jetpack_featured_images_remove_post_thumbnail', true, 4 );
	} else {
		$featured_image = get_the_post_thumbnail( $post->ID, array( $size, $size ), array( 'alt' => $alt ) );
	}
	add_filter( 'get_post_metadata', array( $wcpt_plugin, 'hide_featured_image_on_people' ), 10, 3 );

	// If there is a featured image, it should override the gravatar.
	if ( $featured_image ) {
		$avatar = $featured_image;
	}

	return $avatar;
}
