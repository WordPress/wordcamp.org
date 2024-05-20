<?php

if ( ! defined( 'WPINC' ) ) {
	die();
}

if ( empty( $existing_notes ) ) :
	esc_html_e( 'There are no private notes yet.', 'wordcamporg' );
else :
	foreach ( $existing_notes as $note ) : ?>
		<div class="wcbrr-note">
			<span class="wcbrr-note-meta">
				<?php echo esc_html( gmdate( 'Y-m-d', $note['timestamp'] ) ); ?>
				<?php echo esc_html( WordCamp_Budgets::get_requester_name( $note['author_id'] ) ); ?>:
			</span>

			<?php echo esc_html( $note['message'] ); ?>
		</div>
	<?php endforeach;
endif; ?>

<div>
	<h3>
		<label for="wcbrr_new_note_private">
			<?php esc_html_e( 'Add a private note', 'wordcamporg' ); ?>
		</label>
	</h3>

	<textarea id="wcbrr_new_note_private" name="wcbrr_new_note_private" class="large-text"></textarea>

	<?php submit_button(
		esc_html__( 'Add private note', 'wordcamporg' ),
		'secondary',
		'wcbrr_add_note'
	); ?>
</div>
