<?php

namespace WordCamp\Budgets\Sponsor_Invoices;
use WP_Post;

defined( 'WPINC' ) or die();

/**
 * @var WP_Post $post
 * @var string  $delete_text
 * @var string  $invoice_url
 * @var array   $allowed_edit_statuses
 * @var bool    $current_user_can_edit_request
 * @var bool    $current_user_can_submit_request
 */

?>

<div id="submitpost" class="wcbsi submitbox">
	<div id="minor-publishing">
		<?php if ( in_array( $post->post_status, array( 'auto-draft', 'draft' ), true ) ) : ?>
			<div id="minor-publishing-actions">
				<div id="save-action">
					<?php submit_button( esc_html__( 'Save Draft' ), 'secondary', 'wcb-save-draft', false ); ?>
				</div>
			</div>
		<?php endif; ?>

		<div id="misc-publishing-actions">
			<div class="misc-pub-section misc-pub-post-status">
				<label for="post_status"><?php _e( 'Status:' ) ?></label>

				<span id="post-status-display">
					<?php if ( in_array( $post->post_status, array( 'auto-draft', 'draft' ), true ) ) : ?>
						<?php _e( 'Draft', 'wordcamporg' ); ?>
					<?php elseif ( 'wcbsi_submitted' == $post->post_status ) : ?>
						<?php _e( 'Submitted', 'wordcamporg' ); ?>
					<?php elseif ( 'wcbsi_approved' == $post->post_status ) : ?>
						<?php _e( 'Sent', 'wordcamporg' ); ?>
					<?php elseif ( 'wcbsi_paid' == $post->post_status ) : ?>
						<?php _e( 'Paid', 'wordcamporg' ); ?>
					<?php elseif ( 'wcbsi_uncollectible' == $post->post_status ) : ?>
						<?php _e( 'Uncollectible', 'wordcamporg' ); ?>
					<?php elseif ( 'wcbsi_refunded' == $post->post_status ) : ?>
						<?php _e( 'Refunded', 'wordcamporg' ); ?>
					<?php endif; ?>
				</span>

				<?php if ( $invoice_url ) : ?>
					(<a href="<?php echo esc_url( $invoice_url ); ?>">View QBO Invoice</a>)
				<?php endif; ?>
			</div> <!-- .misc-pub-section -->

			<div class="clear"></div>
		</div> <!-- #misc-publishing-actions -->

		<div class="clear"></div>
	</div> <!-- #minor-publishing -->


	<div id="major-publishing-actions">
		<?php if ( $current_user_can_edit_request && $current_user_can_submit_request ) : ?>

			<div id="delete-action">
				<?php if ( current_user_can( 'delete_post', $post->ID ) ) : ?>
					<a class="submitdelete deletion" href="<?php echo get_delete_post_link( $post->ID ); ?>">
						<?php echo $delete_text; ?>
					</a>
				<?php endif; ?>
			</div>

			<div id="publishing-action">
				<input name="original_publish" type="hidden" id="original_publish" value="<?php esc_attr( esc_html__( 'Send Invoice', 'wordcamporg' ) ) ?>" />
				<?php submit_button(
					esc_html__( 'Send Invoice', 'wordcamporg' ),
					'primary button-large',
					'send-invoice',
					false,
					array( 'accesskey' => 'p' )
				); ?>
			</div>

			<div class="clear"></div>

		<?php elseif ( ! $current_user_can_submit_request ) : ?>

			<p>
				<?php _e( "Invoices can't be submitted until your venue contract has been signed.", 'wordcamporg' ); ?>
			</p>

		<?php else : ?>

			<p>
				<?php _e( "Invoices can't be edited after they've been submitted.", 'wordcamporg' ); ?>
			</p>

		<?php endif; ?>
	</div> <!-- #major-publishing-actions -->

</div> <!-- .submitbox -->
