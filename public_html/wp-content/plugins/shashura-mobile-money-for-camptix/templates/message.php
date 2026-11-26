<?php
/**
 * Message screen. $args: heading, text, tone (info|error), home_url.
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sc-message sc-message--<?php echo esc_attr( 'error' === $args['tone'] ? 'error' : 'info' ); ?>">
	<span class="sc-message-icon" aria-hidden="true">
		<svg viewBox="0 0 24 24" width="28" height="28" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z"/></svg>
	</span>
	<h1 class="sc-title sc-center"><?php echo esc_html( $args['heading'] ); ?></h1>
	<p class="sc-lead sc-center"><?php echo esc_html( $args['text'] ); ?></p>
	<?php if ( $args['home_url'] ) : ?>
		<a class="sc-btn" href="<?php echo esc_url( $args['home_url'] ); ?>"><?php esc_html_e( 'Back to tickets', 'shashura-mobile-money-for-camptix' ); ?></a>
	<?php endif; ?>
</div>
