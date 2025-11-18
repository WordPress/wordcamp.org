<?php

defined('WPINC') || die();

/**
 * @var array $camptix_opts
 * @var string $invoice_number
 * @var string $invoice_date
 * @var array $invoice_metas
 * @var array $invoice_order
 * @var string $logo
 */

?>

<html>
<head>
	<meta charset="UTF-8">
	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This is rendered by wkhtmltopdf, so additional libraries are included directly. ?>
	<link href="http://fonts.googleapis.com/css?family=Open+Sans:300,600,700" rel="stylesheet" type="text/css"/>
	<style type="text/css">
		#camptix-invoice-page {
			margin: 5em;
			font-family: 'Open Sans', sans-serif;
			font-size: 14px;
			font-weight: 300;
			color: #222;
			padding-top: 3em;
		}

		#camptix-invoice-page strong,
		#camptix-invoice-page h1 {
			font-weight: 700;
		}

		#camptix-invoice-page p {
			padding: 0;
			margin: 0;
			line-height: 1em;
		}

		#camptix-invoice-page .text-left {
			text-align: left;
		}

		#camptix-invoice-page .text-center {
			text-align: center;
		}

		#camptix-invoice-page .text-right {
			text-align: right;
		}

		#camptix-invoice-page .camptix-invoice-logo-holder {
			display: block;
			margin: 3em 0;
		}

		#camptix-invoice-page .camptix-invoice-logo-holder img {
			max-width: 50%;
		}

		#camptix-invoice-page .camptix-inovice-header {
			padding-bottom: 1em;
			overflow: hidden;
			border-bottom: 1px solid #999;
		}

		#camptix-invoice-page .camptix-inovice-header .camptix-invoice-from-box {
			width: 48%;
			float: left;
		}

		#camptix-invoice-page .camptix-inovice-header .camptix-invoice-to-box {
			width: 48%;
			float: right;
		}

		#camptix-invoice-page .camptix-inovice-header .camptix-invoice-from-box p,
		#camptix-invoice-page .camptix-inovice-header .camptix-invoice-to-box p {
			line-height: 2em;
		}

		#camptix-invoice-page .camptix-inovice-header .camptix-invoice-to-box p {
			margin-bottom: 2em;
		}

		#camptix-invoice-page .camptix-invoice-data {
			padding: 2.5em 0;
		}

		#camptix-invoice-page .camptix-invoice-data p {
			line-height: 1.5em;
		}

		#camptix-invoice-page .camptix-invoice-order table,
		#camptix-invoice-page .camptix-invoice-order th,
		#camptix-invoice-page .camptix-invoice-order td {
			border: 1px solid #999;
		}

		#camptix-invoice-page .camptix-invoice-order th,
		#camptix-invoice-page .camptix-invoice-order td {
			line-height: 2.5em;
			padding: 0 0.5em;
		}

		#camptix-invoice-page .camptix-invoice-order table {
			width: 100%;
			border-collapse: collapse;
		}

		#camptix-invoice-page .camptix-invoice-order th {
			background: #BBBBBB;
		}

		#camptix-invoice-page .camptix-invoice-payment-status {
			padding: 3em 0;
		}

		#camptix-invoice-page .camptix-invoice-payment-status p {
			font-weight: 700;
		}

	</style>
</head>
<body>
<div id="camptix-invoice-page">
	<?php if ( ! empty( $camptix_opts['invoice-heading'] ) ) : ?>
		<h1><?php echo esc_html( $camptix_opts['invoice-heading'] ); ?></h1>
	<?php endif; ?>
	<div class="camptix-invoice-logo-holder">
		<img src="<?php echo esc_url($logo); ?>">
	</div>
	<div class="camptix-inovice-header">
		<div class="camptix-invoice-from-box text-left">
			<strong><?php esc_html_e( 'From', 'wordcamporg' ); ?>:</strong>
			<p class="text-left">
				<?php echo nl2br( esc_html( $camptix_opts['invoice-company'] ) ); ?>
			</p>
		</div>
		<div class="camptix-invoice-to-box text-right">
			<strong><?php esc_html_e('To', 'wordcamporg'); ?>:</strong>
			<p class="text-right">
				<?php echo esc_html( $invoice_metas['name'] ); ?><br/>
				<?php echo nl2br( esc_html( $invoice_metas['address'] ) ); ?><br/>
			</p>
			<?php if ( ! empty( $invoice_metas['vat-number'] ) ) { ?>
				<strong><?php esc_html_e('VAT no', 'wordcamporg'); ?>:</strong>
				<?php echo esc_html( $invoice_metas['vat-number'] ); ?>
			<?php } ?>
		</div>
	</div>
	<div class="camptix-invoice-data text-right">
		<p class="text-right">
			<strong><?php esc_html_e( 'Invoice no', 'wordcamporg' ); ?>:</strong>
				<?php echo esc_html( $invoice_number ); ?>
		</p>
		<p class="text-right">
			<strong><?php esc_html_e( 'Invoice Date', 'wordcamporg' ); ?>:</strong>
				<?php echo esc_html( $invoice_date ); ?>
		</p>
	</div>
	<div class="camptix-invoice-order">
		<?php
		$vat_rate = (float) ( $camptix_opts['invoice-vat-percent'] ?? 0 );
		$show_vat = (
			! empty( $invoice_metas['vat-number'] ) ||
			! empty( $camptix_opts['invoice-vat-number'] ) ||
			$vat_rate
		);
		?>
		<table>
			<colgroup>
				<col style="width: 48%"/>
				<col style="width: 8%"/>
				<col/>
				<?php if ( $vat_rate ) { ?><col/><?php } ?>
				<col style="width: 22%"/>
			</colgroup>
			<tr>
				<th class="text-left"><?php echo esc_html( $camptix_opts['event_name'] ); ?></th>
				<th class="text-center"><?php esc_html_e( 'Qty', 'wordcamporg' ); ?></th>
				<th class="text-right"><?php esc_html_e( 'Unit Price', 'wordcamporg' ); ?></th>
				<?php if ( $vat_rate ) : ?>
					<th class="text-right"><?php esc_html_e( 'VAT', 'wordcamporg' ); ?></th>
				<?php endif; ?>
				<th class="text-right"><?php esc_html_e( 'Total Price', 'wordcamporg'); ?></th>
			</tr>
				<?php foreach ( $invoice_order['items'] as $item ) : ?>
					<tr>
						<td class="text-left"><?php echo esc_html( $item['name'] ); ?></td>
						<td class="text-center"><?php echo esc_html( $item['quantity'] ); ?></td>
						<td class="text-right">
							<?php
							$unit_price = $item['price'];
							if ( $vat_rate ) {
								$unit_price = $item['price'] / ( 1 + ( $vat_rate / 100 ) );
							}
							echo esc_html( CampTix_Addon_Invoices::format_currency( $unit_price, $camptix_opts['currency'] ) ); ?>
						</td>
						<?php if ( $vat_rate ) : ?>
						<td class="text-right">
							<?php
							$vat_amount = ( $item['price'] ) - ( ( $item['price'] ) / ( 1 + ( $vat_rate / 100 ) ) );
							echo esc_html( CampTix_Addon_Invoices::format_currency( $vat_amount, $camptix_opts['currency'] ) );
							?>
						</td>
						<?php endif; ?>

						<td class="text-right">
							<?php echo esc_html( CampTix_Addon_Invoices::format_currency( $item['price'] * $item['quantity'], $camptix_opts['currency'] ) ); ?>
						</td>
					</tr>
				<?php endforeach ?>

			<?php if ( $show_vat ) : ?>
				<tr>
					<td class="text-right" colspan="<?php echo $vat_rate ? 4 : 3 ?>">
						<?php esc_html_e('VAT', 'wordcamporg'); ?>
					</td>
					<td class="text-right">
						<?php
						$vat_amount = 0;
						if ( $vat_rate ) {
							$vat_amount = $invoice_order['total'] - ( $invoice_order['total'] / ( 1 + ( $vat_rate / 100 ) ) );
						}

						echo esc_html( CampTix_Addon_Invoices::format_currency( $vat_amount, $camptix_opts['currency'] ) );
						?>
					</td>
				</tr>
			<?php endif; // VAT field. ?>

			<tr>
				<td class="text-right" colspan="<?php echo $vat_rate ? 4 : 3 ?>">
					<?php $vat_rate ? esc_html_e( 'TOTAL including VAT', 'wordcamporg' ) : esc_html_e( 'TOTAL', 'wordcamporg' ); ?>
				</td>
				<td class="text-right">
					<?php echo esc_html( CampTix_Addon_Invoices::format_currency( $invoice_order['total'], $camptix_opts['currency'] ) ); ?>
				</td>
			</tr>
		</table>
	</div>
	<div class="camptix-invoice-payment-status">
		<p>
			<?php esc_html_e( 'Paid in full.', 'wordcamporg' ); ?>
		</p>
	</div>
	<div class="camptix-invoice-note">
		<?php echo nl2br( esc_html( $camptix_opts['invoice-thankyou'] ) ); ?>
	</div>
</div>
</body>
</html>
