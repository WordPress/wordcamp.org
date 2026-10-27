<?php
/**
 * Dry-run preview screen for the sponsor-group commands.
 *
 * @var array       $tasks  Task slug => label.
 * @var array|false $last   The last preview this user ran, if any.
 * @var string      $action The `admin_post` action to submit to.
 */

defined( 'WPINC' ) || die();

?>

<div class="wrap">
	<h1><?php esc_html_e( 'Sponsor Groups Dry Run', 'wordcamporg' ); ?></h1>

	<p>
		<?php esc_html_e( 'Each button reports what the matching command would change, without changing anything. Nothing on this screen writes.', 'wordcamporg' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'To apply a change for real, run the command over WP-CLI. That is deliberate: a real run has no request timeout there, and propagation adds posts on every camp\'s own site.', 'wordcamporg' ); ?>
	</p>

	<?php if ( $last ) : ?>
		<?php if ( $last['error'] ) : ?>
			<div class="notice notice-error">
				<p><?php echo esc_html( $last['result'] ); ?></p>
			</div>
		<?php else : ?>
			<h2>
				<?php
				printf(
					/* translators: %s: the name of the preview that was run. */
					esc_html__( 'Preview: %s', 'wordcamporg' ),
					esc_html( $tasks[ $last['task'] ] ?? $last['task'] )
				);
				?>
			</h2>

			<table class="widefat striped" style="max-width: 30em;">
				<tbody>
					<?php foreach ( $last['result'] as $label => $value ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( str_replace( '_', ' ', $label ) ); ?></th>
							<td>
								<?php foreach ( MES_Dry_Run_Page::format_value( $value ) as $line ) : ?>
									<?php echo esc_html( $line ); ?><br />
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'This is what a real run would produce. Nothing was written.', 'wordcamporg' ); ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Available previews', 'wordcamporg' ); ?></h2>

	<?php foreach ( $tasks as $slug => $label ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 1em;">
			<?php wp_nonce_field( $action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="mes_task" value="<?php echo esc_attr( $slug ); ?>" />
			<?php submit_button( $label, 'secondary', 'submit', false ); ?>
		</form>
	<?php endforeach; ?>
</div>
