<?php
/**
 * Server-side rendering for the wporg/sponsors block.
 *
 * Renders the network's global sponsor list (see `inc/sponsors.php`). The
 * same markup renders on every group site, since the data comes from one
 * network-level store rather than from the current site.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Sponsors\get_sponsors;

$wporg_sponsors = get_sponsors();

// No sponsors — render nothing at all rather than an empty card. The block
// sits in the theme's templates on every group site, so an empty state would
// otherwise show up on sites that have simply never had a sponsor.
if ( empty( $wporg_sponsors ) ) {
	return;
}

$wporg_limit    = max( 0, (int) ( $attributes['limit'] ?? 4 ) );
$wporg_level    = min( 6, max( 1, (int) ( $attributes['level'] ?? 2 ) ) );
$wporg_heading  = 'h' . $wporg_level;
$wporg_has_more = $wporg_limit > 0 && count( $wporg_sponsors ) > $wporg_limit;

$wporg_wrapper_attributes = get_block_wrapper_attributes(
	array( 'class' => 'wporg-sponsors' )
);
?>
<div
	<?php echo $wporg_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-interactive="wporg/sponsors"
	<?php echo wp_interactivity_data_wp_context( array( 'isExpanded' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
>
	<div class="wporg-sponsors__header">
		<<?php echo esc_html( $wporg_heading ); ?> class="wporg-section-heading wporg-sponsors__heading">
			<?php esc_html_e( 'Sponsors', 'wporg-groups-frontend' ); ?>
		</<?php echo esc_html( $wporg_heading ); ?>>

		<?php if ( $wporg_has_more ) : ?>
			<?php
			/*
			 * Rendered hidden and revealed by the Interactivity API on
			 * hydration: without JavaScript the button couldn't expand
			 * anything, so it shouldn't be offered.
			 */
			?>
			<button
				type="button"
				class="wporg-sponsors__show-all"
				hidden
				data-wp-bind--hidden="context.isExpanded"
				data-wp-on--click="actions.expand"
			>
				<?php esc_html_e( 'Show all', 'wporg-groups-frontend' ); ?>
			</button>
		<?php endif; ?>
	</div>

	<ul class="wporg-sponsors__list">
		<?php foreach ( $wporg_sponsors as $wporg_index => $wporg_sponsor ) :
			$wporg_is_overflow = $wporg_has_more && $wporg_index >= $wporg_limit;
			$wporg_tag         = $wporg_sponsor['url'] ? 'a' : 'div';
			?>
			<li
				class="wporg-sponsors__item"
				<?php if ( $wporg_is_overflow ) : ?>
					hidden data-wp-bind--hidden="!context.isExpanded"
				<?php endif; ?>
			>
				<<?php echo esc_html( $wporg_tag ); ?>
					class="wporg-sponsors__link"
					<?php if ( $wporg_sponsor['url'] ) : ?>
						href="<?php echo esc_url( $wporg_sponsor['url'] ); ?>"
						target="_blank"
						rel="noopener nofollow sponsor"
					<?php endif; ?>
				>
					<span class="wporg-sponsors__logo">
						<?php if ( $wporg_sponsor['logo'] ) : ?>
							<img
								src="<?php echo esc_url( $wporg_sponsor['logo'] ); ?>"
								alt=""
								<?php if ( $wporg_sponsor['logo_width'] && $wporg_sponsor['logo_height'] ) : ?>
									width="<?php echo esc_attr( (string) $wporg_sponsor['logo_width'] ); ?>"
									height="<?php echo esc_attr( (string) $wporg_sponsor['logo_height'] ); ?>"
								<?php endif; ?>
								loading="lazy"
								decoding="async"
							/>
						<?php endif; ?>
					</span>

					<span class="wporg-sponsors__info">
						<span class="wporg-sponsors__name"><?php echo esc_html( $wporg_sponsor['name'] ); ?></span>
						<?php if ( $wporg_sponsor['description'] ) : ?>
							<span class="wporg-sponsors__description"><?php echo esc_html( $wporg_sponsor['description'] ); ?></span>
						<?php endif; ?>
					</span>

					<?php if ( $wporg_sponsor['url'] ) : ?>
						<span class="screen-reader-text">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: sponsor name. */
									__( 'Visit %s (opens in a new tab)', 'wporg-groups-frontend' ),
									$wporg_sponsor['name']
								)
							);
							?>
						</span>
						<span class="wporg-sponsors__external" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
								<path d="M14 5h5v5M19 5l-8 8M17 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</span>
					<?php endif; ?>
				</<?php echo esc_html( $wporg_tag ); ?>>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
