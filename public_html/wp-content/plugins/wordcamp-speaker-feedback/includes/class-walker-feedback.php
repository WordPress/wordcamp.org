<?php

namespace WordCamp\SpeakerFeedback;

use WP_Comment, Walker_Comment;
use function WordCamp\SpeakerFeedback\Post\get_session_speaker_user_ids;
use function WordCamp\SpeakerFeedback\View\{ render_feedback_comment, render_feedback_rating };
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

/**
 * Class Walker_Feedback.
 *
 * Render feedback submissions on the frontend of the site.
 */
class Walker_Feedback extends Walker_Comment {
	/**
	 * What the class handles.
	 *
	 * @var string
	 */
	public $tree_type = COMMENT_TYPE;

	/**
	 * Starts the element output.
	 *
	 * @global int        $comment_depth
	 * @global WP_Comment $comment       Global comment object.
	 *
	 * @param string     $output  Used to append additional content. Passed by reference.
	 * @param WP_Comment $comment Comment data object.
	 * @param int        $depth   Optional. Depth of the current comment in reference to parents. Default 0.
	 * @param array      $args    Optional. An array of arguments. Default empty array.
	 * @param int        $id      Optional. ID of the current comment. Default 0 (unused).
	 */
	public function start_el( &$output, $comment, $depth = 0, $args = array(), $id = 0 ) {
		$depth++;
		$GLOBALS['comment_depth'] = $depth; // phpcs:ignore
		$GLOBALS['comment']       = $comment; // phpcs:ignore

		ob_start();
		$this->render_feedback( $comment, $depth, $args );
		$output .= ob_get_clean();
	}

	/**
	 * Ends the element output, if needed.
	 *
	 * @param string     $output  Used to append additional content. Passed by reference.
	 * @param WP_Comment $comment The current comment object. Default current comment.
	 * @param int        $depth   Optional. Depth of the current comment. Default 0.
	 * @param array      $args    Optional. An array of arguments. Default empty array.
	 */
	public function end_el( &$output, $comment, $depth = 0, $args = array() ) {
		$output .= "</div><!-- #comment-## -->\n";
	}

	/**
	 * Outputs a feedback submission.
	 *
	 * @param WP_Comment $comment Comment to display.
	 * @param int        $depth   Depth of the current comment.
	 * @param array      $args    An array of arguments.
	 */
	protected function render_feedback( $comment, $depth, $args ) {
		$commenter = wp_get_current_commenter();
		$comment_id = $comment->comment_ID;
		$comment_author = $comment->comment_author_email;
		$session_speakers = get_session_speaker_user_ids( $comment->comment_post_ID );
		?>
		<div <?php comment_class( 'speaker-feedback__comment', $comment ); ?>>
			<article id="speaker-feedback-<?php echo absint( $comment_id ); ?>" class="speaker-feedback__comment-body comment-body">
				<header class="speaker-feedback__comment-meta comment-meta">
					<div class="speaker-feedback__comment-author comment-author vcard">
						<?php
						if ( 0 != $args['avatar_size'] ) {
							echo get_avatar( $comment_author, $args['avatar_size'] );
						}
						?>
						<?php printf( '<b class="fn">%s</b>', get_comment_author_link( $comment_id ) ); ?>
					</div><!-- .comment-author -->

					<div class="speaker-feedback__comment-metadata comment-metadata">
						<time datetime="<?php echo esc_attr( get_comment_date( 'c', $comment_id ) ); ?>">
							<?php
								echo esc_html( get_comment_date( '', $comment_id ) );
							?>
						</time>
					</div><!-- .comment-metadata -->
				</header><!-- .comment-meta -->

				<div class="speaker-feedback__comment-content comment-content">
					<p class="speaker-feedback__question"><?php esc_html_e( 'Rating', 'wordcamporg' ); ?></p>
					<p class="speaker-feedback__answer"><?php render_feedback_rating( $comment ); ?></p>

					<?php render_feedback_comment( $comment ); ?>
				</div><!-- .comment-content -->

				<footer class="speaker-feedback__helpful <?php echo ( $comment->helpful ) ? 'is-helpful' : ''; ?>">
					<span id="sft-helpful-<?php echo absint( $comment_id ); ?>">
						<?php esc_html_e( 'Was this feedback helpful?', 'wordcamporg' ); ?>
					</span>
					<label>
						<?php if ( in_array( get_current_user_id(), $session_speakers, true ) ) : ?>
							<input
								type="checkbox"
								data-comment-id="<?php echo absint( $comment_id ); ?>"
								aria-describedby="sft-helpful-<?php echo absint( $comment_id ); ?>"
								<?php checked( $comment->helpful ); ?>
							/>
						<?php endif; ?>
						<?php esc_html_e( 'Yes', 'wordcamporg' ); ?>
					</label>
				</footer>
			</article><!-- .comment-body -->
		<?php
	}
}
