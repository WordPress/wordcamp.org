<?php

/**
 * @var CampTix_Plugin $camptix
 * @var WP_Post        $post
 */
global $camptix;

?>

<form>
	<?php wp_nonce_field( 'tix_resend_' . $post->ID, 'tix_resend_nonce' ); ?>

	<p>
		<?php echo wp_kses_post(
			sprintf(
				__(
					// translators: 1) URL; 2) email address.
					'<a href="%1$s">The Multiple Purchase template</a> will be sent to %2$s. It typically contains the ticket without the receipt, but can be customized.',
					'wordcamporg'
				),
				admin_url( 'edit.php?post_type=tix_ticket&page=camptix_options&tix_section=email-templates' ),
				esc_html( is_email( $camptix->get_attendee_email( $post->ID ) ) )
			)
		); ?>
	</p>

	<p>
		<button name="tix_resend_email" value="ticket" type="submit">
			<?php esc_html_e( 'Resend Ticket', 'wordcamporg' ); ?>
		</button>
	</p>
</form>
