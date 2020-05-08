<?php

namespace WordCamp\SpeakerFeedback\View;

use WP_User;
use WordCamp\SpeakerFeedback\Feedback;
use WordCamp\SpeakerFeedback\Walker_Feedback;
use function WordCamp\SpeakerFeedback\View\render_rating_stars;
use const WordCamp\SpeakerFeedback\Cron\SPEAKER_OPT_OUT_KEY;

defined( 'WPINC' ) || die();

/** @var int[] $session_speakers */
/** @var bool $is_session_speaker */
/** @var Feedback[] $feedback */
/** @var int $avg_rating */
/** @var int $approved */
/** @var int $moderated */

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

$show_helpful = isset( $_GET['helpful'] ) && 'yes' === $_GET['helpful'];
$show_order = isset( $_GET['forder'] ) ? $_GET['forder'] : 'oldest';

?>
<hr />
<div class="speaker-feedback">
	<h2><?php esc_html_e( 'Session Feedback', 'wordcamporg' ); ?></h2>

	<?php if ( ! $is_session_speaker ) : ?>
		<p class="speaker-feedback__notice">
			<?php esc_html_e( 'Only feedback recipients can mark submissions as helpful.', 'wordcamporg' ); ?>
		</p>
	<?php endif; ?>

	<?php foreach ( $session_speakers as $session_speaker ) :
		if ( $is_session_speaker && get_current_user_id() !== $session_speaker ) {
			continue;
		}
		$user = new WP_User( $session_speaker );
		$notifications_opt_out = get_user_meta( $user->ID, SPEAKER_OPT_OUT_KEY, true );
		?>
		<div class="speaker-feedback__notifications <?php echo ( $notifications_opt_out ) ? 'is-disabled' : ''; ?>">
			<span>
				<?php if ( ! $is_session_speaker ) : ?>
					<span class="sft-notifications-speaker-name">
						<?php echo esc_html( $user->user_login ); ?>
					</span>
				<?php endif; ?>
				<span class="sft-notifications-label-text">
					<?php if ( $notifications_opt_out ) : ?>
						<?php esc_html_e( 'Feedback notifications are disabled.', 'wordcamporg' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Feedback notifications are enabled.', 'wordcamporg' ); ?>
					<?php endif; ?>
				</span>
			</span>
			<label>
				<input
					type="checkbox"
					data-user-id="<?php echo absint( $user->ID ); ?>"
					<?php checked( $notifications_opt_out ); ?>
				/>
				<span class="sft-notifications-toggle-text">
					<?php if ( $notifications_opt_out ) : ?>
						<?php esc_html_e( 'Enable', 'wordcamporg' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Disable', 'wordcamporg' ); ?>
					<?php endif; ?>
				</span>
			</label>
		</div>
	<?php endforeach; ?>

	<?php if ( $approved >= 1 ) : ?>
		<div class="speaker-feedback__overview">
			<strong><?php esc_html_e( 'Overall rating:', 'wordcamporg' ); ?></strong>
			<?php echo render_rating_stars( $avg_rating ); //phpcs:ignore -- escaped in function. ?>

			<p><?php echo esc_html( $count_string ); ?></p>
		</div>

		<form action="" method="get" class="speaker-feedback__filters">
			<div class="speaker-feedback__filter-sort">
				<label for="sft-filter-sort">
					<?php esc_html_e( 'Sort by', 'wordcamporg' ); ?>
				</label>
				<select id="sft-filter-sort" name="forder">
					<option <?php selected( $show_order, 'oldest' ); ?> value="oldest">
						<?php esc_html_e( 'Oldest first', 'wordcamporg' ); ?>
					</option>
					<option <?php selected( $show_order, 'newest' ); ?> value="newest">
						<?php esc_html_e( 'Newest first', 'wordcamporg' ); ?>
					</option>
					<option <?php selected( $show_order, 'highest' ); ?> value="highest">
						<?php esc_html_e( 'Highest rated first', 'wordcamporg' ); ?>
					</option>
				</select>
			</div>
			<div class="speaker-feedback__filter-helpful">
				<label>
					<input
						name="helpful"
						type="checkbox"
						value="yes"
						id="sft-filter-helpful"
						<?php checked( $show_helpful ); ?>
					/>
					<?php esc_html_e( 'Only show feedback marked as helpful', 'wordcamporg' ); ?>
				</label>
			</div>
			<input type="submit" value="Filter" />
		</form>

		<div class="speaker-feedback__list comment-list">
			<?php
			wp_list_comments(
				array(
					// Note: `Walker_Feedback` does not support the callback or format args.
					'walker' => new Walker_Feedback(),
					'style'  => 'div',
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
