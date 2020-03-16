<?php

namespace WordCamp\SpeakerFeedback\View;

use function WordCamp\SpeakerFeedback\get_assets_path;

defined( 'WPINC' ) || die();

?>
<hr />
<form id="sft-feedback" class="speaker-feedback">
	<h3><?php esc_html_e( 'Rate this talk', 'wordcamporg' ); ?></h3>

	<div id="speaker-feedback-notice" aria-live="polite" aria-relevant="additions text" aria-atomic="true"></div>

	<div class="speaker-feedback__field">
		<fieldset class="speaker-feedback__field-rating" aria-label="<?php esc_attr_e( 'Rate this talk', 'wordcamporg' ); ?>">
			<input
				type="radio"
				name="sft-rating"
				value="0"
				checked
				aria-label="<?php esc_attr_e( 'No stars', 'wordcamporg' ); ?>"
			/>
			<input type="radio" id="sft-rating-val-1" name="sft-rating" value="1" />
			<label for="sft-rating-val-1">
				<span class="screen-reader-text"><?php esc_html_e( '1 star', 'wordcamporg' ); ?></span>
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</label>
			<input type="radio" id="sft-rating-val-2" name="sft-rating" value="2" />
			<label for="sft-rating-val-2">
				<span class="screen-reader-text"><?php esc_html_e( '2 stars', 'wordcamporg' ); ?></span>
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</label>
			<input type="radio" id="sft-rating-val-3" name="sft-rating" value="3" />
			<label for="sft-rating-val-3">
				<span class="screen-reader-text"><?php esc_html_e( '3 stars', 'wordcamporg' ); ?></span>
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</label>
			<input type="radio" id="sft-rating-val-4" name="sft-rating" value="4" />
			<label for="sft-rating-val-4">
				<span class="screen-reader-text"><?php esc_html_e( '4 stars', 'wordcamporg' ); ?></span>
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</label>
			<input type="radio" id="sft-rating-val-5" name="sft-rating" value="5" />
			<label for="sft-rating-val-5">
				<span class="screen-reader-text"><?php esc_html_e( '5 stars', 'wordcamporg' ); ?></span>
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</label>
		</fieldset>
	</div>

	<div class="speaker-feedback__field">
		<label for="sft-question-1">
			<?php esc_html_e( 'What’s one good thing you’d keep in this presentation?', 'wordcamporg' ); ?>
		</label>
		<textarea id="sft-question-1" name="sft-question-1" required></textarea>
	</div>

	<div class="speaker-feedback__field">
		<label for="sft-question-2">
			<?php esc_html_e( 'What’s one thing you’d tweak to improve the presentation?', 'wordcamporg' ); ?>
		</label>
		<textarea id="sft-question-2" name="sft-question-2"></textarea>
	</div>

	<div class="speaker-feedback__field">
		<label for="sft-question-3">
			<?php esc_html_e( 'What’s one unhelpful thing you’d delete from the presentation?', 'wordcamporg' ); ?>
		</label>
		<textarea id="sft-question-3" name="sft-question-3"></textarea>
	</div>

	<input type="hidden" name="sft-author" value="<?php echo intval( get_current_user_id() ); ?>" />
	<input type="hidden" name="sft-post" value="<?php echo intval( get_the_ID() ); ?>" />
	<input type="submit" value="<?php esc_attr_e( 'Send Feedback', 'wordcamporg' ); ?>" />
</form>
