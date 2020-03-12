<?php

namespace WordCamp\SpeakerFeedback\View;

use WordCamp\SpeakerFeedback\Feedback_List_Table;

defined( 'WPINC' ) || die();

/** @var int $post_id */
/** @var string $search */
/** @var int $paged */
/** @var string $comment_status */
/** @var Feedback_List_Table $list_table */

$pagenum = $list_table->get_pagenum();

$list_table->prepare_items();

?>
<div class="wrap">
	<h1 class="wp-heading-inline">
		<?php
		if ( $post_id ) {
			printf(
				/* translators: %s: Link to post. */
				wp_kses_post( __( 'Feedback for &#8220;%s&#8221;', 'wordcamporg' ) ),
				sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( get_edit_post_link( $post_id ) ),
					wp_kses_data( get_the_title( $post_id ) )
				)
			);
		} else {
			esc_html_e( 'Feedback', 'wordcamporg' );
		}
		?>
	</h1>

	<?php
	if ( $search && strlen( $search ) ) {
		echo '<span class="subtitle">';
		printf(
			/* translators: %s: Search query. */
			esc_html__( 'Search results for &#8220;%s&#8221;' ),
			esc_html( $search )
		);
		echo '</span>';
	}
	?>

	<hr class="wp-header-end">

	<?php $list_table->views(); ?>

	<form id="comments-form" method="get">

		<?php $list_table->search_box( __( 'Search Feedback', 'wordcamporg' ), 'comment' ); ?>

		<?php if ( $post_id ) : ?>
			<input type="hidden" name="p" value="<?php echo esc_attr( $post_id ); ?>" />
		<?php endif; ?>
		<input type="hidden" name="comment_status" value="<?php echo esc_attr( $comment_status ); ?>" />
		<input type="hidden" name="pagegen_timestamp" value="<?php echo esc_attr( current_time( 'mysql', 1 ) ); ?>" />

		<input type="hidden" name="_total" value="<?php echo esc_attr( $list_table->get_pagination_arg( 'total_items' ) ); ?>" />
		<input type="hidden" name="_per_page" value="<?php echo esc_attr( $list_table->get_pagination_arg( 'per_page' ) ); ?>" />
		<input type="hidden" name="_page" value="<?php echo esc_attr( $list_table->get_pagination_arg( 'page' ) ); ?>" />

		<?php if ( $paged ) { ?>
			<input type="hidden" name="paged" value="<?php echo esc_attr( $paged ); ?>" />
		<?php } ?>

		<?php $list_table->display(); ?>
	</form>
</div>

<div id="ajax-response"></div>

<?php
wp_comment_reply( '-1', true, 'detail' );
wp_comment_trashnotice();
