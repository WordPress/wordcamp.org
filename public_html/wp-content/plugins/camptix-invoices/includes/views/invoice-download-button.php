<?php

defined( 'WPINC' ) || die();

/** @var string $invoice_number */
/** @var string $invoice_url */

?>

<div class="misc-pub-section">
	<p>
		<?php echo esc_html__( 'Invoice number', 'invoices-camptix' ); ?> <strong><?php echo esc_html( $invoice_number ); ?></strong>
	</p>
	<?php if ( ! empty( $invoice_url ) ) { ?>
		<a
			href="<?php echo esc_attr( $invoice_url ); ?>"
			class="button button-secondary"
			target="_blank"
		>
			<?php echo esc_html__( 'Download invoice', 'invoices-camptix' ); ?>
		</a>
	<?php } ?>
</div>
