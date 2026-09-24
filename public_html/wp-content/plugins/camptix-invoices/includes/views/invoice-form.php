<?php

defined( 'WPINC' ) || die();

/** @var string $invoice_vat_number */

// Preserve field values after failed server-side validation.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified by CampTix core before rendering.
$need_invoice    = ! empty( $_POST['camptix-need-invoice'] );
$saved_email     = sanitize_email( wp_unslash( $_POST['invoice-email'] ?? '' ) );
$saved_name      = sanitize_text_field( wp_unslash( $_POST['invoice-name'] ?? '' ) );
$saved_address   = sanitize_textarea_field( wp_unslash( $_POST['invoice-address'] ?? '' ) );
$saved_vat       = sanitize_text_field( wp_unslash( $_POST['invoice-vat-number'] ?? '' ) );
// phpcs:enable WordPress.Security.NonceVerification.Missing

?>

<div class="camptix-invoice-toggle-wrapper">

	<input type="checkbox" value="1" name="camptix-need-invoice" id="camptix-need-invoice" <?php checked( $need_invoice ); ?> />
	<label for="camptix-need-invoice">
		<?php echo esc_html__( 'I need an invoice', 'wordcamporg' ); ?>
	</label>

	<table class="camptix-invoice-details tix_tickets_table tix_invoice_table">
		<tbody>

			<tr>
				<td class="tix-left">
					<label for="invoice-email">
						<?php echo esc_html__( 'Recipient email', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="text" name="invoice-email" id="invoice-email" value="<?php echo esc_attr( $saved_email ); ?>" pattern="^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+.[a-zA-Z0-9-.]+$" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="invoice-name">
						<?php echo esc_html__( 'Recipient name or organisation', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<input type="text" name="invoice-name" id="invoice-name" value="<?php echo esc_attr( $saved_name ); ?>" />
				</td>
			</tr>

			<tr>
				<td class="tix-left">
					<label for="invoice-address">
						<?php echo esc_html__( 'Recipient street address', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
					</label>
				</td>
				<td class="tix-right">
					<textarea name="invoice-address" id="invoice-address" rows="2"><?php echo esc_textarea( $saved_address ); ?></textarea>
				</td>
			</tr>

			<?php if ( ! empty( $invoice_vat_number ) ) : ?>
				<tr>
					<td class="tix-left">
						<label for="invoice-vat-number">
							<?php echo esc_html__( 'VAT number', 'wordcamporg' ); ?><span class="tix-required-star">*</span>
						</label>
					</td>
					<td class="tix-right">
						<input type="text" name="invoice-vat-number" id="invoice-vat-number" value="<?php echo esc_attr( $saved_vat ); ?>" />
					</td>
				</tr>
			<?php endif; ?>

		</tbody>
	</table>
</div>
