<?php

namespace WordCamp\SpeakerFeedback\View;

use WP_Post;
use function WordCamp\SpeakerFeedback\get_assets_path;
use function WordCamp\SpeakerFeedback\Post\get_session_feedback_url;

defined( 'WPINC' ) || die();

/** @var WP_Post $post */
/** @var array   $schema */
/** @var string  $rating_question */
/** @var array   $text_questions */
?>
<hr />
<form id="sft-feedback" class="speaker-feedback">

	<h3><?php esc_html_e( 'Leave Feedback', 'wordcamporg' ); ?></h3>

	<?php if ( ! is_user_logged_in() ) : ?>
	<div class="speaker-feedback__field">
		<div class="speaker-feedback__notice">
			<p><?php echo wp_kses_post( sprintf(
				__( '<a href="%s">Log in to your WordPress.org account,</a> or add your name & email to leave feedback.', 'wordcamporg' ),
				wp_login_url( get_session_feedback_url( $post->ID ) )
			) ); ?></p>
		</div>

		<div class="speaker-feedback__field-inline">
			<label for="sft-author-name">
				<?php esc_html_e( 'Name', 'wordcamporg' ); ?>
				<span class="is-required" aria-hidden="true">*</span>
			</label>
			<input type="text" id="sft-author-name" name="sft-author-name" required />
		</div>

		<div class="speaker-feedback__field-inline">
			<label for="sft-author-email">
				<?php esc_html_e( 'Email', 'wordcamporg' ); ?>
				<span class="is-required" aria-hidden="true">*</span>
			</label>
			<input type="email" id="sft-author-email" name="sft-author-email" required />
		</div>
	</div>
	<?php else : ?>
		<div class="speaker-feedback__notice">
			<p><?php
			$user = wp_get_current_user();
			echo wp_kses_post( sprintf(
				__( 'You are leaving feedback as %s.', 'wordcamporg' ),
				'<strong>' . $user->display_name . '</strong>'
			) ); ?></p>
		</div>
	<?php endif; ?>

	<div id="speaker-feedback-notice" aria-live="polite" aria-relevant="additions text" aria-atomic="true"></div>

	<div class="speaker-feedback__field">
		<fieldset class="speaker-feedback__field-rating" id="sft-rating">
			<legend>
				<?php echo esc_html( $rating_question ); ?>
				<?php if ( $schema['rating']['required'] ) : ?>
					<span class="is-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</legend>
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

	<?php foreach ( $text_questions as list( $key, $question ) ) :
		$maxlength = 0;
		$maxlength_attr = '';
		if ( isset( $schema[ $key ]['attributes']['maxlength'] ) ) {
			$maxlength = $schema[ $key ]['attributes']['maxlength'];
			// Uses `data-maxlength` instead of `maxlength` to prevent browsers from truncating length.
			$maxlength_attr = sprintf( 'data-maxlength="%d"', $maxlength );
		}
		?>
	<div class="speaker-feedback__field">
		<label for="sft-<?php echo esc_attr( $key ); ?>">
			<?php echo esc_html( $question ); ?>
			<?php if ( $schema[ $key ]['required'] ) : ?>
				<span class="is-required" aria-hidden="true">*</span>
			<?php endif; ?>
		</label>
		<textarea
			id="sft-<?php echo esc_attr( $key ); ?>"
			name="sft-<?php echo esc_attr( $key ); ?>"
			aria-describedby="sft-<?php echo esc_attr( $key ); ?>-extra"
			<?php echo $maxlength_attr; // phpcs:ignore ?>
			<?php echo $schema[ $key ]['required'] ? 'required' : ''; ?>
		></textarea>
		<?php if ( $maxlength_attr ) : ?>
			<span class="screen-reader-text" id="sft-<?php echo esc_attr( $key ); ?>-extra">
				<?php echo esc_html( sprintf(
					_n( 'Max %s character.', 'Max %s characters.', $maxlength, 'wordcamporg' ),
					number_format_i18n( $maxlength )
				) ); ?>
			</span>
			<div class="speaker-feedback__field-help">
				0/<?php echo absint( $maxlength ); ?>
			</div>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>

	<input type="hidden" name="sft-author" value="<?php echo intval( get_current_user_id() ); ?>" />
	<input type="hidden" name="sft-post" value="<?php echo intval( get_the_ID() ); ?>" />
	<input type="submit" value="<?php esc_attr_e( 'Send Feedback', 'wordcamporg' ); ?>" />
</form>
