<?php
/**
 * The deputies' notes thread on the budget page (#1023).
 *
 * Only rendered for users who pass WordCamp_Budget_Tool::can_manage_notes().
 *
 * @var string $view
 */

defined( 'WPINC' ) || die();

$notes         = WordCamp_Budget_Tool::get_notes();
$status_labels = array(
	'draft'    => __( 'Draft', 'wordcamporg' ),
	'pending'  => __( 'Submitted', 'wordcamporg' ),
	'approved' => __( 'Approved', 'wordcamporg' ),
);

?>

<div class="wcb-budget-notes">
	<h2><?php esc_html_e( 'Notes', 'wordcamporg' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Visible to deputies and program managers only. Adding a note does not change the budget.', 'wordcamporg' ); ?></p>

	<?php if ( isset( $_GET['wcb-note-added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only. ?>
		<?php if ( '1' === $_GET['wcb-note-added'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Note added.', 'wordcamporg' ); ?></p></div>
		<?php else : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The note was empty, so nothing was added.', 'wordcamporg' ); ?></p></div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( empty( $notes ) ) : ?>
		<p><?php esc_html_e( 'There are no notes yet.', 'wordcamporg' ); ?></p>
	<?php else : ?>
		<ol class="wcb-budget-notes-list">
			<?php foreach ( $notes as $note ) : ?>
				<li class="wcb-budget-note">
					<span class="wcb-budget-note-meta">
						<?php echo esc_html( wp_date( 'Y-m-d H:i', $note['timestamp'] ) ); ?>
						&middot;
						<?php echo esc_html( WordCamp_Budgets::get_requester_name( $note['author_id'] ) ); ?>
						&middot;
						<?php echo esc_html( $status_labels[ $note['status'] ] ?? $note['status'] ); ?>
					</span>
					<div class="wcb-budget-note-message"><?php echo nl2br( esc_html( $note['message'] ) ); ?></div>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<form class="wcb-budget-notes-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="wcb_budget_add_note" />
		<input type="hidden" name="wcb-view" value="<?php echo esc_attr( $view ); ?>" />
		<?php // Not the default `_wpnonce`: the budget form's heartbeat refresh targets `#_wpnonce`. ?>
		<?php wp_nonce_field( 'wcb_budget_add_note', 'wcb_budget_note_nonce' ); ?>

		<label for="wcb_budget_note" class="screen-reader-text"><?php esc_html_e( 'Add a note', 'wordcamporg' ); ?></label>
		<textarea id="wcb_budget_note" name="wcb_budget_note" class="large-text" rows="4"></textarea>

		<?php submit_button( __( 'Add Note', 'wordcamporg' ), 'secondary', 'wcb-budget-add-note' ); ?>
	</form>
</div>
