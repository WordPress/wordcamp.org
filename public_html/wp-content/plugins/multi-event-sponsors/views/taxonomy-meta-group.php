<?php
/**
 * The camera kit wrangler field on the Sponsor Group add and edit screens.
 *
 * @var WP_Term|null $term                  The group being edited, or null on the add screen.
 * @var string       $camera_wrangler_email The stored address, or ''.
 */

defined( 'WPINC' ) || die();

if ( $term ) : ?>
	<?php wp_nonce_field( 'mes_group_camera_wrangler_' . $term->term_id, 'mes_group_camera_wrangler_nonce' ); ?>

	<tr class="form-field term-camera-wrangler-email-wrap">
		<th scope="row">
			<label for="camera-wrangler-email"><?php esc_html_e( 'Camera Wrangler E-mail Address', 'wordcamporg' ); ?></label>
		</th>

		<td>
			<input name="camera-wrangler-email" id="camera-wrangler-email" type="email" value="<?php echo esc_attr( $camera_wrangler_email ); ?>" size="40" />
			<p class="description"><?php esc_html_e( 'Organizer reminders addressed to the camera kit wrangler go here for camps in this group.', 'wordcamporg' ); ?></p>
		</td>
	</tr>

<?php else : ?>

	<?php wp_nonce_field( 'mes_group_camera_wrangler_new', 'mes_group_camera_wrangler_nonce' ); ?>

	<div class="form-field term-camera-wrangler-email-wrap">
		<label for="tag-camera-wrangler-email"><?php esc_html_e( 'Camera Wrangler E-mail Address', 'wordcamporg' ); ?></label>
		<input name="camera-wrangler-email" id="tag-camera-wrangler-email" type="email" value="" size="40" />
		<p><?php esc_html_e( 'Organizer reminders addressed to the camera kit wrangler go here for camps in this group.', 'wordcamporg' ); ?></p>
	</div>

<?php endif;
