<?php

namespace WordCamp\Budgets\Reimbursement_Requests;
defined( 'WPINC' ) or die();

/**
 * @var \WP_Post $post
 * @var bool     $current_user_can_edit_request
 * @var string   $request_id
 * @var string   $requested_by
 * @var string   $incomplete_notes
 * @var bool     $incomplete_readonly
 * @var string   $submit_text
 * @var string   $submit_note
 * @var string   $submit_note_class
 */
?>

<div id="submitpost" class="wcb submitbox">
	<div id="minor-publishing">
		<?php if ( $current_user_can_edit_request && ! current_user_can( 'manage_network' ) ) : ?>
		<div id="minor-publishing-actions">
			<div id="save-action">
				<?php submit_button( esc_html__( 'Save Draft', 'wordcamporg' ), 'button', 'wcb-save-draft', false ); ?>
				<span class="spinner"></span>
			</div>
			<div class="clear"></div>
		</div>
		<?php endif; ?>

		<div id="misc-publishing-actions">
			<div class="misc-pub-section misc-pub-request-id">
				<?php esc_html_e( 'ID:' ); ?>

				<span id="request_id">
					<?php echo esc_html( $request_id ); ?>
				</span>
			</div> <!-- .misc-pub-section -->

			<div class="misc-pub-section misc-pub-requested-by">
				<label><?php esc_html_e( 'Requested By:' ); ?>

					<span id="requested_by">
						<?php echo esc_html( $requested_by ); ?>
					</span>
				</label>
			</div> <!-- .misc-pub-section -->

			<div class="misc-pub-section misc-pub-post-status">
				<label>
					<?php esc_html_e( 'Status:' ); ?>

					<span id="post-status-display">
						<?php if ( current_user_can( 'manage_network' ) ) : ?>

							<select id="wcb_status" name="post_status">
								<?php foreach ( get_post_statuses() as $status ) : ?>
									<?php $status = get_post_status_object( $status ); ?>
									<option value="<?php echo esc_attr( $status->name ); ?>" <?php selected( $post->post_status, $status->name ); ?> >
										<?php echo esc_html( $status->label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

						<?php else : ?>

							<?php $status = get_post_status_object( $post->post_status ); ?>
							<?php echo esc_html( $status->label ); ?>
							<input type="hidden" name="post_status" value="<?php echo esc_attr( $post->post_status ); ?>" />

						<?php endif; ?>
					</span>
				</label>
			</div>

			<div class="misc-pub-section hide-if-js wcb-mark-incomplete-notes">
				<label for="wcp_mark_incomplete_notes">What information is needed?</label>
				<textarea id="wcp_mark_incomplete_notes" name="wcp_mark_incomplete_notes" class="large-text" rows="5"
					placeholder="Need to attach receipt, etc" <?php echo $incomplete_readonly; ?>><?php echo esc_textarea( $incomplete_notes ); ?></textarea>
			</div>

			<div class="misc-pub-section misc-pub-total-amount-requested">
				<label>
					<?php esc_html_e( 'Total Amount Requested:' ); ?>

					<span id="total_amount_requested" class="loading-content">
						<span class="spinner is-active"></span>
					</span>
				</label>
			</div> <!-- .misc-pub-section -->

			<div class="clear"></div>
		</div> <!-- #misc-publishing-actions -->

		<div class="clear"></div>
	</div> <!-- #minor-publishing -->

	<?php require dirname( __DIR__ ) . '/wordcamp-budgets/major-publishing-actions.php'; ?>

</div> <!-- .submitbox -->
