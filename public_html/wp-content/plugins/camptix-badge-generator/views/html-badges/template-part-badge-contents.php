<?php

namespace CampTix\Badge_Generator\HTML;
use WP_Post;

defined( 'WPINC' ) || die();

/**
 * @var WP_Post $attendee
 * @var array   $allowed_html
 */

?>

<header>
	<?php if ( has_custom_logo() ) : ?>
		<?php the_custom_logo(); ?>

	<?php else : ?>
		<h2 class="wordcamp-name">
			<?php echo esc_html( get_wordcamp_name() ); ?>
		</h2>

	<?php endif; ?>
</header>

<figure class="attendee-identity">
	<img
		class="attendee-avatar"
		src="<?php echo esc_url( $attendee->avatar_url ); ?>"
		alt="<?php echo esc_attr( strip_tags( $attendee->formatted_name ) ); ?>"
	/>

	<figcaption>
		<h1 class="attendee-name">
			<?php echo wp_kses( $attendee->formatted_name, $allowed_html ); ?>
		</h1>
	</figcaption>
</figure>

<div class="attendee-meta">
	<?php // Company goes last, so the person comes before their employer. ?>
	<ul class="attendee-details">
		<?php if ( $attendee->wporg_username ) : ?>
			<li class="attendee-wporg-username">@<?php echo esc_html( $attendee->wporg_username ); ?></li>
		<?php endif; ?>

		<?php if ( $attendee->hometown ) : ?>
			<li class="attendee-hometown"><?php echo esc_html( $attendee->hometown ); ?></li>
		<?php endif; ?>

		<?php if ( $attendee->website_label ) : ?>
			<li class="attendee-website"><?php echo esc_html( $attendee->website_label ); ?></li>
		<?php endif; ?>

		<?php if ( $attendee->company ) : ?>
			<li class="attendee-company"><?php echo esc_html( $attendee->company ); ?></li>
		<?php endif; ?>
	</ul>

	<?php if ( ! empty( $attendee->qr_code_svg ) ) : ?>
		<figure class="attendee-qrcode">
			<?php echo $attendee->qr_code_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<figcaption class="screen-reader-text">
				<?php echo esc_html( sprintf( __( 'QR code linking to %s', 'wordcamporg' ), $attendee->qr_code_target_url ) ); ?>
			</figcaption>
		</figure>
	<?php endif; ?>
</div>

<!-- These are arbitrary elements that you can use for any purpose -->
<div class="badge-design-element-1"></div>
<div class="badge-design-element-2"></div>
<div class="badge-design-element-3"></div>
<div class="badge-design-element-4"></div>
<div class="badge-design-element-5"></div>
