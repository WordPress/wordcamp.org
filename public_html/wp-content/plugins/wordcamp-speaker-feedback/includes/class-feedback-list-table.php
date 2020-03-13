<?php

namespace WordCamp\SpeakerFeedback;

use WP_Comment, WP_User;
use WP_Comments_List_Table;
use function WordCamp\SpeakerFeedback\get_assets_path;
use function WordCamp\SpeakerFeedback\Comment\get_feedback_comment;

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

		$columns['name']     = _x( 'Name', 'column name', 'wordcamporg' );
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


	public function column_feedback( $comment ) {
		$feedback = get_feedback_comment( $comment );

		echo 'asdf';
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
}
