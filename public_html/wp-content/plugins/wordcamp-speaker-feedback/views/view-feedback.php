<?php

namespace WordCamp\SpeakerFeedback\View;

use WordCamp\SpeakerFeedback\Walker_Feedback;
use function WordCamp\SpeakerFeedback\View\render_rating_stars;

defined( 'WPINC' ) || die();

$approved_string = '';
$moderated_string = '';
if ( $approved > 0 ) {
	$approved_string = sprintf( _n( '%d submission', '%d submissions', $approved, 'wordcamporg' ), $approved );
}
if ( $moderated > 0 ) {
	$moderated_string = sprintf(
		_n( '%d submission pending', '%d submissions pending', $moderated, 'wordcamporg' ),
		$moderated
	);
}

if ( '' === $approved_string || '' === $moderated_string ) {
	$count_string = $approved_string . $moderated_string; // One is empty, so this does not need a separator.
} else {
	// Translators: Combined string of X submussions, Y submissions pending.
	$count_string = sprintf( __( '%1$s, %2$s', 'wordcamporg' ), $approved_string, $moderated_string );
}

?>
<hr />
<div class="speaker-feedback">
	<h3><?php esc_html_e( 'Session Feedback', 'wordcamporg' ); ?></h3>

	<?php if ( $approved >= 1 ) : ?>
		<div class="speaker-feedback__overview">
			<strong><?php esc_html_e( 'Overall rating:', 'wordcamporg' ); ?></strong>
			<?php echo render_rating_stars( $avg_rating ); //phpcs:ignore -- escaped in function. ?>

			<p><?php echo esc_html( $count_string ); ?></p>
		</div>

		<div class="speaker-feedback__filters">
			<div class="speaker-feedback__filter-sort">
				<label for="sft-filter-sort">
					<?php esc_html_e( 'Sort by', 'wordcamporg' ); ?>
				</label>
				<select id="sft-filter-sort">
					<option><?php esc_html_e( 'Oldest first', 'wordcamporg' ); ?></option>
					<option><?php esc_html_e( 'Newest first', 'wordcamporg' ); ?></option>
					<option><?php esc_html_e( 'Highest rated first', 'wordcamporg' ); ?></option>
				</select>
			</div>
			<div class="speaker-feedback__filter-helpful">
				<label>
					<input type="checkbox" />
					<?php esc_html_e( 'Only show comments marked as helpful', 'wordcamporg' ); ?>
				</label>
			</div>
		</div>

		<div class="speaker-feedback__list comment-list">
			<?php
			wp_list_comments(
				array(
					// Note: `Walker_Feedback` does not support the callback or format args.
					'walker' => new Walker_Feedback(),
					'style' => 'div',
				),
				$feedback
			);
			?>
		</div>
	<?php elseif ( $moderated >= 1 ) : ?>
		<div class="speaker-feedback__overview">
			<p><?php echo esc_html( $count_string ); ?></p>
		</div>
	<?php else : ?>
		<div class="speaker-feedback__overview">
			<p><?php echo esc_html_e( 'No feedback has been submitted yet.', 'wordcamporg' ); ?></p>
		</div>
	<?php endif; ?>
</div>
