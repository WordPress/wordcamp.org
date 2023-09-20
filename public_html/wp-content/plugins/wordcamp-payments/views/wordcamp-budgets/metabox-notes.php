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
endif;

if ( current_user_can( 'view_wordcamp_payment_details', $post->ID ) ) : ?>
	<div>
		<h3>
			<label for="wcbrr_new_note">
				<?php esc_html_e( 'Add a Note', 'wordcamporg' ); ?>
			</label>

			<?php if ( current_user_can( 'manage_network' ) ) : ?>
				<p class="description">(visible to organizers)</p>
			<?php endif; ?>
		</h3>

		<textarea id="wcbrr_new_note" name="wcbrr_new_note" class="large-text"></textarea>

		<?php submit_button(
			esc_html__( 'Add Note', 'wordcamporg' ),
			'secondary',
			'wcbrr_add_note'
		); ?>
	</div>
<?php endif; ?>
