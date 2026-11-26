<?php
/**
 * Approval. assets/js/checkout.js polls the payment status. $args: network, phone, amount, return_url, retry_url.
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;

$shasmomo_network = $args['network'];
?>
<div id="sc-wait">
	<div class="sc-timer" aria-hidden="true">
		<span class="sc-ripple"></span>
		<span class="sc-ripple sc-ripple--2"></span>
		<svg viewBox="0 0 120 120" width="136" height="136">
			<circle class="sc-timer-track" cx="60" cy="60" r="52"/>
			<circle class="sc-timer-bar" id="sc-timer-bar" cx="60" cy="60" r="52" pathLength="100"/>
		</svg>
		<div class="sc-timer-inner">
			<?php if ( ! empty( $shasmomo_network['logo'] ) ) : ?>
				<img class="sc-timer-logo" src="<?php echo esc_url( $shasmomo_network['logo'] ); ?>" alt="" width="56" height="56">
			<?php else : ?>
				<svg viewBox="0 0 24 24" width="26" height="26" focusable="false"><path fill="currentColor" d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 3v13h10V5H7Zm5 14.2a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z"/></svg>
			<?php endif; ?>
			<span class="sc-timer-text" id="sc-timer-text"></span>
		</div>
	</div>

	<h1 class="sc-title sc-center"><?php esc_html_e( 'Approve on your phone', 'shashura-mobile-money-for-camptix' ); ?></h1>
	<p class="sc-lead sc-center">
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: masked phone number */
				esc_html__( 'Enter your PIN on the prompt sent to %s.', 'shashura-mobile-money-for-camptix' ),
				'<strong>' . esc_html( $args['phone'] ) . '</strong>'
			),
			array( 'strong' => array() )
		);
		?>
	</p>

	<p class="sc-status" id="sc-msg" aria-live="polite">
		<span class="sc-pulse" aria-hidden="true"></span>
		<span id="sc-msg-text"><?php esc_html_e( 'Waiting for approval…', 'shashura-mobile-money-for-camptix' ); ?></span>
	</p>

	<noscript>
		<a class="sc-btn" href="<?php echo esc_url( $args['return_url'] ); ?>"><?php esc_html_e( 'I’ve approved', 'shashura-mobile-money-for-camptix' ); ?></a>
	</noscript>
	<a class="sc-btn sc-btn--ghost" id="sc-retry" hidden href="<?php echo esc_url( $args['retry_url'] ); ?>"><?php esc_html_e( 'Resend prompt', 'shashura-mobile-money-for-camptix' ); ?></a>
</div>

<div id="sc-done" class="sc-done" hidden>
	<div class="sc-check" aria-hidden="true">
		<svg viewBox="0 0 52 52" width="72" height="72"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-15"/></svg>
	</div>
	<h1 class="sc-title sc-center"><?php esc_html_e( 'Payment received', 'shashura-mobile-money-for-camptix' ); ?></h1>
	<p class="sc-lead sc-center"><?php esc_html_e( 'Taking you to your tickets…', 'shashura-mobile-money-for-camptix' ); ?></p>
	<dl class="sc-rows sc-rows--boxed" id="sc-det"></dl>
	<a class="sc-btn" id="sc-next" href="<?php echo esc_url( $args['return_url'] ); ?>"><?php esc_html_e( 'View tickets', 'shashura-mobile-money-for-camptix' ); ?></a>
</div>
