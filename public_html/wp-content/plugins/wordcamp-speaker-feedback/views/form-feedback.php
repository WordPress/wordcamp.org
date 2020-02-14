<?php

namespace WordCamp\SpeakerFeedback\View;

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
				<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 20 20" height="20" width="20" role="img" aria-hidden="true">
					<path d="M10 1l3 6 6 .75-4.12 4.62L16 19l-6-3-6 3 1.13-6.63L1 7.75 7 7z"/>
				</svg>
			</label>
			<input type="radio" id="sft-rating-val-2" name="feedback-rating" value="2" />
			<label for="sft-rating-val-2">
				<span class="screen-reader-text"><?php esc_html_e( '2 stars', 'wordcamporg' ); ?></span>
				<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 20 20" height="20" width="20" role="img" aria-hidden="true">
					<path d="M10 1l3 6 6 .75-4.12 4.62L16 19l-6-3-6 3 1.13-6.63L1 7.75 7 7z"/>
				</svg>
			</label>
			<input type="radio" id="sft-rating-val-3" name="feedback-rating" value="3" />
			<label for="sft-rating-val-3">
				<span class="screen-reader-text"><?php esc_html_e( '3 stars', 'wordcamporg' ); ?></span>
				<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 20 20" height="20" width="20" role="img" aria-hidden="true">
					<path d="M10 1l3 6 6 .75-4.12 4.62L16 19l-6-3-6 3 1.13-6.63L1 7.75 7 7z"/>
				</svg>
			</label>
			<input type="radio" id="sft-rating-val-4" name="feedback-rating" value="4" />
			<label for="sft-rating-val-4">
				<span class="screen-reader-text"><?php esc_html_e( '4 stars', 'wordcamporg' ); ?></span>
				<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 20 20" height="20" width="20" role="img" aria-hidden="true">
					<path d="M10 1l3 6 6 .75-4.12 4.62L16 19l-6-3-6 3 1.13-6.63L1 7.75 7 7z"/>
				</svg>
			</label>
			<input type="radio" id="sft-rating-val-5" name="feedback-rating" value="5" />
			<label for="sft-rating-val-5">
				<span class="screen-reader-text"><?php esc_html_e( '5 stars', 'wordcamporg' ); ?></span>
				<svg xmlns="http://www.w3.org/2000/svg" viewbox="0 0 20 20" height="20" width="20" role="img" aria-hidden="true">
					<path d="M10 1l3 6 6 .75-4.12 4.62L16 19l-6-3-6 3 1.13-6.63L1 7.75 7 7z"/>
				</svg>
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
