<?php

defined( 'WPINC' ) or die();

?>

<?php if ( empty ( $existing_notes ) ) : ?>

	<?php _e( 'There are no private notes yet.', 'wordcamporg' ); ?>

<?php else : ?>

	<?php foreach ( $existing_notes as $note ) : ?>
		<div class="wcbrr-note">
			<span class="wcbrr-note-meta">
				<?php echo esc_html( date( 'Y-m-d', $note['timestamp'] ) ); ?>
				<?php echo esc_html( WordCamp_Budgets::get_requester_name( $note['author_id'] ) ); ?>:
			</span>

			<?php echo esc_html( $note['message'] ); ?>
		</div>
	<?php endforeach; ?>

<?php endif; ?>

<div>
	<h3>
		<label for="wcbrr_new_note_private">
			<?php _e( 'Add a private note', 'wordcamporg' ); ?>
		</label>
	</h3>

	<textarea id="wcbrr_new_note_private" name="wcbrr_new_note_private" class="large-text"></textarea>

	<?php submit_button(
		esc_html__( 'Add private note', 'wordcamporg' ),
		'secondary',
		'wcbrr_add_note'
	); ?>
</div>
