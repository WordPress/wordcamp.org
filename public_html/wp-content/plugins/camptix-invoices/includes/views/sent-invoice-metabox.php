<?php

defined( 'WPINC' ) || die();

/** @var array $order */
/** @var array $metas */
/** @var string $invoice_vat_number */
/** @var int $txn_id */

?>

<h3><?php esc_html_e( 'Order details', 'wordcamporg' ); ?></h3>

<table class="widefat">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Title', 'wordcamporg' ); ?></th>
			<th><?php esc_html_e( 'Unit price', 'wordcamporg' ); ?></th>
			<th><?php esc_html_e( 'Quantity', 'wordcamporg' ); ?></th>
		</tr>
	</thead>

	<tbody>

	<?php foreach ( $order['items'] as $k => $item ) : ?>

		<tr>
			<td><?php echo esc_html( $item['name'] ); ?></td><!-- name -->
			<td><?php echo esc_html( number_format_i18n( $item['price'], 2 ) ); ?></td><!-- price -->
			<td><?php echo esc_html( number_format_i18n( $item['quantity'] ) ); ?></td><!-- qty -->
		</tr>

	<?php endforeach; ?>

	</tbody>
</table>

<table class="form-table">
	<tr>
		<th scope="row"><?php esc_html_e( 'Total amount', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( number_format_i18n( $order['total'], 2 ) ); ?></td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Customer', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $metas['name'] ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Contact email', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $metas['email'] ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Customer Address', 'wordcamporg' ); ?></th>
		<td><?php echo wp_kses( nl2br( $metas['address'] ), array( 'br' => true ) ); ?><td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Transaction ID', 'wordcamporg' ); ?></th>
		<td><?php echo esc_html( $txn_id ); ?><td>
	</tr>

	<?php if ( ! empty( $invoice_vat_number ) ) : ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'VAT number', 'wordcamporg' ); ?></th>
			<td><?php echo esc_html( $metas['vat-number'] ); ?><td>
		</tr>
	<?php endif; ?>
</table>
