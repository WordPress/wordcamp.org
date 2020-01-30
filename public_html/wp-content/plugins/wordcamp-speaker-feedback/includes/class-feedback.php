<?php

namespace WordCamp\SpeakerFeedback;

use WP_Comment;

defined( 'WPINC' ) || die();

/**
 * Class Feedback
 *
 * A wrapper for WP_Comment that allows access to comment meta in a similar way to WP_Post.
 *
 * @package WordCamp\SpeakerFeedback
 */
class Feedback {
	/**
	 * @var WP_Comment|null
	 */
	protected $wp_comment = null;

	/**
	 * Feedback constructor.
	 *
	 * @param WP_Comment $wp_comment
	 */
	public function __construct( WP_Comment $wp_comment ) {
		$this->wp_comment = $wp_comment;
	}

	/**
	 * Enable getting props directly from the WP_Comment instance and comment meta.
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function __get( $name ) {
		if ( property_exists( $this->wp_comment, $name ) ) {
			return $this->wp_comment->$name;
		} else {
			return get_comment_meta( $this->wp_comment->comment_ID, $name, true );
		}
	}

	/**
	 * Enable calling methods directly from the WP_Comment instance.
	 *
	 * @param string $name
	 * @param array  $arguments
	 *
	 * @return mixed
	 */
	public function __call( $name, $arguments ) {
		return call_user_func_array( array( $this->wp_comment, $name ), $arguments );
	}
}
