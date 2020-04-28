<?php

namespace WordCamp\SpeakerFeedback;

use WP_Comment, WP_User;
use WP_Comments_List_Table;
use function WordCamp\SpeakerFeedback\get_assets_path;
use function WordCamp\SpeakerFeedback\Comment\get_feedback_comment;
use function WordCamp\SpeakerFeedback\Post\get_session_feedback_url;
use function WordCamp\SpeakerFeedback\View\{ render_feedback_comment, render_feedback_rating };
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

/**
 * Class Feedback_List_Table.
 *
 * Display feedback comments in the WP Admin.
 */
class Feedback_List_Table extends WP_Comments_List_Table {
	/**
	 * Feedback_List_Table constructor.
	 *
	 * @param array $args
	 */
	public function __construct( $args = array() ) {
		parent::__construct( $args );

		// This is an ugly hack to prevent the avatar from rendering twice, from both the parent and child class.
		// See \WordCamp\SpeakerFeedback\Admin\filter_list_table_add_comment_avatar().
		remove_filter( 'comment_author', array( $this, 'floated_admin_avatar' ), 10 );
	}

	/**
	 * Get the screen option for items per page.
	 *
	 * @param string $comment_status Unused.
	 *
	 * @return int
	 */
	public function get_per_page( $comment_status = 'all' ) {
		/** @global string $page_hook */
		global $page_hook;

		$option = str_replace( '-', '_', $page_hook ) . '_per_page';

		return $this->get_items_per_page( $option );
	}

	/**
	 * Add more bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		$bulk_actions = parent::get_bulk_actions();

		global $comment_status;

		$key_indexes = array_keys( $bulk_actions );
		$slice_index = array_search( 'spam', $key_indexes, true );
		$new_actions = array();

		if ( in_array( $comment_status, array( 'all', 'approved', 'moderated' ) ) ) {
			$new_actions['mark-inappropriate'] = __( 'Mark as Inappropriate', 'wordcamporg' );
		}

		if ( in_array( $comment_status, array( 'inappropriate' ) ) ) {
			$new_actions['unmark-inappropriate'] = __( 'Unmark as Inappropriate', 'wordcamporg' );

			if ( false === $slice_index ) {
				$slice_index = 0;
				$new_actions['spam'] = _x( 'Mark as Spam', 'comment' );
			}
		}

		if ( false !== $slice_index && ! empty( $new_actions ) ) {
			$after  = array_slice( $bulk_actions, $slice_index, null, true );
			$before = array_slice( $bulk_actions, 0, count( $bulk_actions ) - count( $after ), true );

			$bulk_actions = $before + $new_actions + $after;
		}

		return $bulk_actions;
	}

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
	 * Render the bulk edit checkbox.
	 *
	 * @param WP_Comment $comment
	 *
	 * @return void
	 */
	public function column_cb( $comment ) {
		if ( current_user_can( 'moderate_' . COMMENT_TYPE ) ) {
			?>
			<label class="screen-reader-text" for="cb-select-<?php echo esc_attr( $comment->comment_ID ); ?>"><?php esc_html_e( 'Select feedback', 'wordcamporg' ); ?></label>
			<input id="cb-select-<?php echo esc_attr( $comment->comment_ID ); ?>" type="checkbox" name="bulk_edit[]" value="<?php echo esc_attr( $comment->comment_ID ); ?>" />
			<?php
		}
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

		echo '<strong>';
		comment_author( $comment );
		echo '</strong>';

		$user  = ( $feedback->user_id ) ? new WP_User( $feedback->user_id ) : null;
		$email = get_comment_author_email( $feedback->comment_ID );

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
		} elseif ( $email ) {
			printf(
				'<br /><a href="%1$s">%2$s</a><br />',
				esc_url( 'mailto:' . $email ),
				esc_html( $email )
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
		// This is only displayed on smaller screens.
		echo '<div class="comment-author">';
		$this->column_author( $comment );
		echo '</div>';

		render_feedback_comment( $comment );
	}

	/**
	 * Render the Rating column.
	 *
	 * @param WP_Comment $comment
	 *
	 * @return void
	 */
	public function column_rating( $comment ) {
		render_feedback_rating( $comment );
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
			<a href="<?php echo esc_url( get_session_feedback_url( $post->ID ) ); ?>" class="comments-view-item-link">
				<?php esc_html_e( 'View Feedback', 'wordcamporg' ); ?>
			</a>
		</div>
		<?php
	}
}
