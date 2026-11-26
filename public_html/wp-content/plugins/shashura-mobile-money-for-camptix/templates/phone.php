<?php
/**
 * Number entry. $args: notice, error, prefill, networks, action_url, nonce_action, nonce_name.
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 class="sc-sr"><?php esc_html_e( 'Pay with Mobile Money', 'shashura-mobile-money-for-camptix' ); ?></h1>

<?php if ( $args['notice'] ) : ?>
	<div class="sc-alert sc-alert--warn" role="status"><?php echo esc_html( $args['notice'] ); ?></div>
<?php endif; ?>

<form class="sc-form" method="post" action="<?php echo esc_url( $args['action_url'] ); ?>" novalidate>
	<?php wp_nonce_field( $args['nonce_action'], $args['nonce_name'], false ); ?>
	<input type="hidden" name="step" value="lookup">

	<label class="sc-label" for="sc-phone"><?php esc_html_e( 'Mobile Money number', 'shashura-mobile-money-for-camptix' ); ?></label>
	<div class="sc-phone<?php echo esc_attr( $args['error'] ? ' is-invalid' : '' ); ?>">
		<span class="sc-phone-cc" aria-hidden="true">+256</span>
		<input id="sc-phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="13" placeholder="772 123 456" value="<?php echo esc_attr( $args['prefill'] ); ?>" required aria-describedby="<?php echo esc_attr( $args['error'] ? 'sc-phone-error' : 'sc-phone-help' ); ?>"<?php echo $args['error'] ? ' aria-invalid="true"' : ''; ?>>
		<span class="sc-phone-detected" id="sc-detected">
			<?php foreach ( $args['networks'] as $shasmomo_network ) : ?>
				<?php if ( ! empty( $shasmomo_network['logo'] ) ) : ?>
					<img src="<?php echo esc_url( $shasmomo_network['logo'] ); ?>" alt="" width="28" height="28" data-network="<?php echo esc_attr( $shasmomo_network['key'] ); ?>" hidden>
				<?php endif; ?>
			<?php endforeach; ?>
			<span class="sc-sr" id="sc-detected-text" aria-live="polite"></span>
		</span>
	</div>

	<?php if ( $args['error'] ) : ?>
		<p class="sc-field-error" id="sc-phone-error" role="alert">
			<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z"/></svg>
			<span><?php echo esc_html( $args['error'] ); ?></span>
		</p>
	<?php else : ?>
		<p class="sc-help" id="sc-phone-help"><?php esc_html_e( 'We verify the account before you pay.', 'shashura-mobile-money-for-camptix' ); ?></p>
	<?php endif; ?>

	<button class="sc-btn" type="submit"><?php esc_html_e( 'Continue', 'shashura-mobile-money-for-camptix' ); ?></button>
</form>
