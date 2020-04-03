<?php

namespace WordCamp\SpeakerFeedback\View;

use function WordCamp\SpeakerFeedback\get_assets_path;

defined( 'WPINC' ) || die();

?>
<hr />
<form id="sft-feedback" class="speaker-feedback">
	<h3><?php esc_html_e( 'Rate this talk', 'wordcamporg' ); ?></h3>

	<div class="speaker-feedback__field">
		<fieldset class="speaker-feedback__field-rating" aria-label="<?php esc_attr_e( 'Rate this talk', 'wordcamporg' ); ?>">
			<input
				type="radio"
				name="feedback-rating"
				value="0"
				checked
				aria-label="<?php esc_attr_e( 'No stars', 'wordcamporg' ); ?>"
			/>
			<input type="radio" id="sft-rating-val-1" name="feedback-rating" value="1" />
			<label for="sft-rating-val-1">
				<span class="screen-reader-text"><?php esc_html_e( '1 star', 'wordcamporg' ); ?></span>
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</label>
			<input type="radio" id="sft-rating-val-2" name="feedback-rating" value="2" />
			<label for="sft-rating-val-2">
				<span class="screen-reader-text"><?php esc_html_e( '2 stars', 'wordcamporg' ); ?></span>
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</label>
			<input type="radio" id="sft-rating-val-3" name="feedback-rating" value="3" />
			<label for="sft-rating-val-3">
				<span class="screen-reader-text"><?php esc_html_e( '3 stars', 'wordcamporg' ); ?></span>
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</label>
			<input type="radio" id="sft-rating-val-4" name="feedback-rating" value="4" />
			<label for="sft-rating-val-4">
				<span class="screen-reader-text"><?php esc_html_e( '4 stars', 'wordcamporg' ); ?></span>
				<?php require get_assets_path() . 'svg/star.svg'; ?>
			</label>
			<input type="radio" id="sft-rating-val-5" name="feedback-rating" value="5" />
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
		<textarea id="sft-question-1" required></textarea>
	</div>

	<div class="speaker-feedback__field">
		<label for="sft-question-2">
			<?php esc_html_e( 'What’s one thing you’d tweak to improve the presentation?', 'wordcamporg' ); ?>
		</label>
		<textarea id="sft-question-2"></textarea>
	</div>

	<div class="speaker-feedback__field">
		<label for="sft-question-3">
			<?php esc_html_e( 'What’s one unhelpful thing you’d delete from the presentation?', 'wordcamporg' ); ?>
		</label>
		<textarea id="sft-question-3"></textarea>
	</div>

	<input type="submit" value="<?php esc_attr_e( 'Send Feedback', 'wordcamporg' ); ?>" />
</form>
