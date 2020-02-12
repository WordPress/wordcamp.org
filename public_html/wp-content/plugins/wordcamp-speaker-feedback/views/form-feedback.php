<?php

namespace WordCamp\SpeakerFeedback\View;

?>
<hr />
<form id="sft-feedback" class="speaker-feedback">
	<h3><?php esc_html_e( 'Rate this talk', 'wordcamporg' ); ?></h3>
	
	<div>
		star rating…
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
