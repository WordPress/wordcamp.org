<?php
namespace WordCamp\Blocks\Utilities;

use WP_Post;

defined( 'WPINC' ) || die();

/**
 * Get the full content of a post, ignoring more and noteaser tags and pagination.
 *
 * This works similarly to `the_content`, including applying filters, but:
 * - It skips all of the logic in `get_the_content` that deals with tags like <!--more--> and
 *   <!--noteaser-->, as well as pagination and global state variables like `$page`, `$more`, and
 *   `$multipage`.
 * - It returns a string of content, rather than echoing it.
 *
 * @param int|WP_Post $post Post ID or post object.
 *
 * @return string The full, filtered post content.
 */
function get_all_the_content( $post ) {
	$post = get_post( $post );

	/*
	 * `get_the_content()` holds the password check, and the `the_content`
	 * filter runs after it, so reading the stored content directly has to
	 * repeat the check. Return what `get_the_content()` returns here, so a
	 * listing behaves the way one built on core's loop would.
	 */
	if ( post_password_required( $post ) ) {
		return get_the_password_form( $post );
	}

	$content = wp_kses_post( $post->post_content );

	/** This filter is documented in wp-includes/post-template.php */
	$content = apply_filters( 'the_content', $content );
	$content = str_replace( ']]>', ']]&gt;', $content );

	return $content;
}

/**
 * Get a trimmed excerpt from a post.
 *
 * @param int|WP_Post $post           Post ID or post object.
 * @param int         $excerpt_length Number of words in excerpt.
 *
 * @return string The escaped post excerpt.
 */
function get_trimmed_content( $post, $excerpt_length = 55 ) {
	$post = get_post( $post );

	/*
	 * The fallback below reads `post_content` directly, so this needs the
	 * same check as `get_all_the_content()`. `get_the_excerpt()` applies it
	 * to both the manual excerpt and the fallback, and stands this sentence
	 * in their place; the wording matches core's so the two read alike.
	 */
	if ( post_password_required( $post ) ) {
		return esc_html__( 'There is no excerpt because this is a protected post.', 'wordcamporg' );
	}

	$post_excerpt = $post->post_excerpt;
	if ( ! ( $post_excerpt ) ) {
		$post_excerpt = $post->post_content;
	}

	return esc_html( wp_trim_words( $post_excerpt, $excerpt_length, ' &hellip; ' ) );
}

/**
 * Convert an array of strings into one string that is a punctuated, human-readable list.
 *
 * @param array $array
 *
 * @return string
 */
function array_to_human_readable_list( array $array ) {
	$count = count( $array );
	$list  = '';

	switch ( $count ) {
		case 0:
			break;
		case 1:
			$list = array_shift( $array );
			break;
		case 2:
			$list = sprintf(
				/* translators: Each %s is a person's name. */
				_x( '%1$s and %2$s', 'list of two items', 'wordcamporg' ),
				array_shift( $array ),
				array_shift( $array )
			);
			break;
		default:
			/* translators: used between list items, there is a space after the comma */
			$item_separator = esc_html__( ', ', 'wordcamporg' );

			$initial = array_slice( $array, 0, $count - 1 );
			$initial = implode( $item_separator, $initial ) . $item_separator;
			$last    = array_slice( $array, -1, 1 )[0];

			$list = sprintf(
				/* translators: 1: A list of items. 2: The last item in a list of items. */
				_x( '%1$s and %2$s', 'list of three or more items', 'wordcamporg' ),
				$initial,
				$last
			);
			break;
	}

	return $list;
}

/**
 * Convert an array of class names into a space-separated string for use in an HTML attribute.
 *
 * @param array $classes
 *
 * @return string
 */
function render_class_string( array $classes ) {
	$classes = array_map( 'sanitize_html_class', $classes );
	$classes = array_filter( $classes );
	$classes = array_unique( $classes );

	return implode( ' ', $classes );
}
