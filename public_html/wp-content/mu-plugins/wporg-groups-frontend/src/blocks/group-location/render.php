<?php
/**
 * Server-side rendering for the wporg/group-location block.
 *
 * @package WordCamp\Groups\Frontend
 */

use function WordCamp\Groups\Frontend\Group_Location\get_location_label;

$location_label = get_location_label();

if ( '' === $location_label ) {
	return;
}
?>
<p <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core. ?>>
	<svg class="wporg-group-location__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Z" fill="none" stroke="currentColor" stroke-width="2"/>
		<circle cx="12" cy="9" r="2.25"/>
	</svg>
	<?php echo esc_html( $location_label ); ?>
</p>
