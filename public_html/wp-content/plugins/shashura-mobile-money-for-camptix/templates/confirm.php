<?php
/**
 * Account confirmation. $args: name, phone, network, amount, error, action_url, nonce_action, nonce_name, change_url.
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;

$shasmomo_network = $args['network'];
?>
<h1 class="sc-sr"><?php esc_html_e( 'Confirm payment details', 'shashura-mobile-money-for-camptix' ); ?></h1>

<div class="sc-payer">
	<p class="sc-payer-q"><?php esc_html_e( 'Pay from', 'shashura-mobile-money-for-camptix' ); ?></p>
	<span class="sc-verified-logo sc-net--<?php echo esc_attr( $shasmomo_network['key'] ); ?>">
		<?php if ( ! empty( $shasmomo_network['logo'] ) ) : ?>
			<img class="sc-net-logo" src="<?php echo esc_url( $shasmomo_network['logo'] ); ?>" alt="" width="64" height="64">
		<?php else : ?>
			<span class="sc-net-mark" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="M4 6.5A2.5 2.5 0 0 1 6.5 4H17a1 1 0 1 1 0 2H6.5a.5.5 0 0 0 0 1H18a2 2 0 0 1 2 2v8.5a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Zm12 7a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5Z"/></svg></span>
			<span class="sc-verified-dot" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="M9.5 16.2 5.3 12l-1.4 1.4 5.6 5.6L20.1 8.4 18.7 7z"/></svg></span>
		<?php endif; ?>
	</span>

	<?php if ( '' !== $args['name'] ) : ?>
		<p class="sc-payer-name"><?php echo esc_html( $args['name'] ); ?></p>
	<?php endif; ?>
	<p class="sc-payer-number"><?php echo esc_html( $args['phone'] ); ?></p>
	<p class="sc-payer-net">
		<?php echo esc_html( $shasmomo_network['label'] ); ?>
		<span class="sc-verified-text"><?php esc_html_e( 'Verified', 'shashura-mobile-money-for-camptix' ); ?></span>
	</p>
</div>

<p class="sc-help sc-center sc-hide-compact"><?php esc_html_e( 'Is this your account?', 'shashura-mobile-money-for-camptix' ); ?></p>

<?php if ( $args['error'] ) : ?>
	<div class="sc-alert sc-alert--error" role="alert"><?php echo esc_html( $args['error'] ); ?></div>
<?php endif; ?>

<form class="sc-form" method="post" action="<?php echo esc_url( $args['action_url'] ); ?>">
	<?php wp_nonce_field( $args['nonce_action'], $args['nonce_name'], false ); ?>
	<input type="hidden" name="step" value="confirm">
	<button class="sc-btn" type="submit">
		<span>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: amount, e.g. UGX 50,000 */
					__( 'Pay %s', 'shashura-mobile-money-for-camptix' ),
					$args['amount']
				)
			);
			?>
		</span>
	</button>
</form>
<a class="sc-link" href="<?php echo esc_url( $args['change_url'] ); ?>"><?php esc_html_e( 'Use another number', 'shashura-mobile-money-for-camptix' ); ?></a>
