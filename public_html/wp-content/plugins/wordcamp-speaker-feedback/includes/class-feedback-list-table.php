<?php

namespace WordCamp\SpeakerFeedback;

use WP_Comment, WP_User;
use WP_Comments_List_Table;
use function WordCamp\SpeakerFeedback\get_assets_path;
use function WordCamp\SpeakerFeedback\Admin\feedback_bubble;
use function WordCamp\SpeakerFeedback\Comment\get_feedback_comment;
use function WordCamp\SpeakerFeedback\CommentMeta\get_feedback_questions;

defined( 'WPINC' ) || die();

/**
 * Class Feedback_List_Table.
 *
 * Display feedback comments in the WP Admin.
 */
class Feedback_List_Table extends WP_Comments_List_Table {
	/**
	 * Other controls above/below the list table besides bulk actions.
	 *
	 * @param string $which
	 *
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		// This omits the comment type filter.
		parent::extra_tablenav( '' );
	}

	/**
	 * Define list table columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		global $post_id;

		$columns = array();

		if ( $this->checkbox ) {
			$columns['cb'] = '<input type="checkbox" />';
		}

		$columns['name']     = _x( 'Submitted by', 'column name', 'wordcamporg' );
		$columns['feedback'] = _x( 'Feedback', 'column name', 'wordcamporg' );
		$columns['rating']   = _x( 'Rating', 'column name', 'wordcamporg' );

		if ( ! $post_id ) {
			/* translators: Column name or table row header. */
			$columns['response'] = _x( 'In Response To', 'column name', 'wordcamporg' );
		}

		$columns['date'] = _x( 'Submitted On', 'column name', 'wordcamporg' );

		return $columns;
	}

	/**
	 * Which columns are sortable.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'     => 'comment_author',
			'rating'   => array( 'rating', true ), // Second array item `true` means sort will descend first.
			'response' => 'comment_post_ID',
			'date'     => 'comment_date',
		);
	}


	/**
	 * Get the name of the default primary column.
	 *
	 * @return string Name of the default primary column.
	 */
	protected function get_default_primary_column_name() {
		return 'feedback';
	}

	/**
	 * Render the Name column.
	 *
	 * @param WP_Comment $comment
	 *
	 * @return void
	 */
	public function column_name( $comment ) {
		$feedback = get_feedback_comment( $comment );

		$name = get_comment_author( $feedback->comment_ID );
		$user = ( $feedback->user_id ) ? new WP_User( $feedback->user_id ) : null;

		printf(
			'<strong>%s</strong>',
			esc_html( $name )
		);

		if ( $user ) {
			printf(
				'<br /><a href="%s">%s</a>',
				esc_url( sprintf(
					'https://profiles.wordpress.org/%s/',
					$user->user_login
				) ),
				sprintf(
					'@%s',
					esc_html( $user->user_login )
				)
			);
		}
	}

	/**
	 * Render the Feedback column.
	 *
	 * @param WP_Comment $comment
	 *
	 * @return void
	 */
	public function column_feedback( $comment ) {
		$feedback  = get_feedback_comment( $comment );
		$questions = get_feedback_questions( $feedback->version );

		foreach ( $questions as $key => $question ) {
			if ( 'rating' === $key ) {
				continue;
			}

			$answer = $feedback->$key;

			if ( $answer ) {
				printf(
					'<p class="speaker-feedback__question">%s</p><p class="speaker-feedback__answer">%s</p>',
					wp_kses_data( $question ),
					wp_kses_data( $answer )
				);
			}
		}
	}

	/**
	 * Render the Rating column.
	 *
	 * @param WP_Comment $comment
	 *
	 * @return void
	 */
	public function column_rating( $comment ) {
		$feedback = get_feedback_comment( $comment );

		$rating      = $feedback->rating;
		$max_stars   = 5;
		$star_output = 0;
		?>
		<span class="screen-reader-text">
			<?php
			printf(
				esc_html__( '%d stars', 'wordcamporg' ),
				absint( $rating )
			);
			?>
		</span>
		<span class="speaker-feedback__meta-rating">
			<?php while ( $star_output < $max_stars ) :
				$class = ( $star_output < $rating ) ? 'star__full' : 'star__empty';
				?>
				<span class="star <?php echo esc_attr( $class ); ?>">
					<?php require get_assets_path() . 'svg/star.svg'; ?>
				</span>
				<?php
				$star_output ++;
			endwhile; ?>
		</span>
		<?php
	}

	/**
	 * Render the In Response To column.
	 *
	 * @param WP_Comment $comment
	 *
	 * @return void
	 */
	public function column_response( $comment ) {
		$feedback = get_feedback_comment( $comment );

		$post = get_post( $feedback->comment_post_ID );

		if ( ! $post ) {
			return;
		}

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$post_link  = "<a href='" . get_edit_post_link( $post->ID ) . "' class='comments-edit-item-link'>";
			$post_link .= esc_html( get_the_title( $post->ID ) ) . '</a>';
		} else {
			$post_link = esc_html( get_the_title( $post->ID ) );
		}
		?>
		<div class="response-links">
			<?php echo wp_kses_post( $post_link ); ?>
			<a href="<?php the_permalink( $post->ID ); ?>" class="comments-view-item-link">
				<?php echo wp_kses_post( get_post_type_object( $post->post_type )->labels->view_item ); ?>
			</a>
			<span class="post-com-count-wrapper post-com-count-<?php echo esc_attr( $post->ID ); ?>">
				<?php feedback_bubble( $post->ID ); ?>
			</span>
		</div>
		<?php
	}
}
