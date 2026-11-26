<?php
/**
 * Payment page layout. Variables: $layout (page) and $args (screen).
 * Themes can override it in {theme}/shashura-mobile-money-for-camptix/layout.php.
 *
 * @package CampTix_Shashura
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title><?php echo esc_html( $layout['title'] ); ?></title>
<?php wp_print_styles( array( $layout['style_handle'] ) ); ?>
</head>
<body class="sc sc-step-<?php echo esc_attr( (string) (int) $layout['step'] ); ?>">
<div class="sc-app">

	<header class="sc-hero">
		<div class="sc-hero-top">
			<span class="sc-merchant">
				<?php if ( ! empty( $layout['logo'] ) ) : ?>
					<img class="sc-avatar sc-avatar--img" src="<?php echo esc_url( $layout['logo'] ); ?>" alt="" width="28" height="28">
				<?php else : ?>
					<span class="sc-avatar" aria-hidden="true"><?php echo esc_html( function_exists( 'mb_substr' ) ? mb_substr( $layout['site_name'], 0, 1 ) : substr( $layout['site_name'], 0, 1 ) ); ?></span>
				<?php endif; ?>
				<span class="sc-merchant-name"><?php echo esc_html( $layout['site_name'] ); ?></span>
			</span>
			<span class="sc-secure">
				<svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5Zm-3 8V7a3 3 0 1 1 6 0v3H9Z"/></svg>
				<?php esc_html_e( 'Secure', 'shashura-mobile-money-for-camptix' ); ?>
			</span>
		</div>

		<?php if ( $layout['summary'] ) : ?>
			<p class="sc-hero-label"><?php esc_html_e( 'Total', 'shashura-mobile-money-for-camptix' ); ?></p>
			<p class="sc-hero-amount"><?php echo esc_html( $layout['summary']['total'] ); ?></p>

			<?php if ( $layout['summary']['items'] ) : ?>
				<details class="sc-hero-items">
					<summary>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of tickets */
								_n( '%d ticket · View order', '%d tickets · View order', $layout['summary']['count'], 'shashura-mobile-money-for-camptix' ),
								$layout['summary']['count']
							)
						);
						?>
					</summary>
					<ul class="sc-lines">
						<?php foreach ( $layout['summary']['items'] as $shasmomo_item ) : ?>
							<li>
								<span><?php echo esc_html( $shasmomo_item['name'] ); ?> <small><?php echo esc_html( '× ' . $shasmomo_item['quantity'] ); ?></small></span>
								<span><?php echo esc_html( $shasmomo_item['price'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $layout['step'] ) : ?>
			<ol class="sc-dots" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: current step, 2: total steps */ __( 'Step %1$d of %2$d', 'shashura-mobile-money-for-camptix' ), $layout['step'], count( $layout['steps'] ) ) ); ?>">
				<?php foreach ( $layout['steps'] as $shasmomo_n => $shasmomo_label ) : ?>
					<li class="<?php echo esc_attr( $shasmomo_n < $layout['step'] ? 'is-done' : ( $shasmomo_n === $layout['step'] ? 'is-current' : '' ) ); ?>"<?php echo $shasmomo_n === $layout['step'] ? ' aria-current="step"' : ''; ?>>
						<span class="sc-dots-label"><?php echo esc_html( $shasmomo_label ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</header>

	<main class="sc-sheet">
		<section class="sc-body">
			<?php require $layout['screen']; ?>
		</section>

		<?php if ( ! empty( $layout['cancel_url'] ) ) : ?>
			<a class="sc-cancel" href="<?php echo esc_url( $layout['cancel_url'] ); ?>">
				<?php esc_html_e( 'Cancel', 'shashura-mobile-money-for-camptix' ); ?>
			</a>
		<?php endif; ?>
	</main>


</div>

<?php if ( $layout['show_credit'] ) : ?>
	<footer class="sc-foot poweredby">
		<?php esc_html_e( 'Powered by', 'shashura-mobile-money-for-camptix' ); ?> <a href="<?php echo esc_url( 'https://shashura.com' ); ?>" target="_blank" rel="noopener noreferrer">Shashura</a>
	</footer>
<?php endif; ?>
<?php wp_print_scripts( array( $layout['script_handle'] ) ); ?>
</body>
</html>
