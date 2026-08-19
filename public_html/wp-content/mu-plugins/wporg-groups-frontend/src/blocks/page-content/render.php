<?php
/**
 * Server-side rendering for the wporg/page-content block.
 *
 * Renders a page's content by slug, with an edit link for organizers.
 *
 * @package WordCamp\Groups\Frontend
 */

$slug          = $attributes['slug'] ?? '';
$heading       = trim( (string) ( $attributes['heading'] ?? '' ) );
$heading_level = (int) ( $attributes['headingLevel'] ?? 2 );
$heading_level = min( 6, max( 1, $heading_level ) );

$wporg_get_heading = static function () use ( $heading, $heading_level ): string {
	if ( '' === $heading ) {
		return '';
	}

	return sprintf(
		'<h%1$d class="wp-block-heading has-heading-3-font-size" style="margin-bottom:var(--wp--preset--spacing--30)">%2$s</h%1$d>',
		$heading_level,
		esc_html( $heading )
	);
};

if ( empty( $slug ) ) {
	return;
}

$target_page = get_page_by_path( $slug );

if ( ! $target_page || 'publish' !== $target_page->post_status ) {
	if ( current_user_can( 'edit_pages' ) ) {
		$create_url = admin_url( 'post-new.php?post_type=page&post_title=' . urlencode( ucfirst( $slug ) ) );

		printf(
			'<div %1$s>%2$s<p><a href="%3$s" class="wp-element-button">%4$s</a></p></div>',
			get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
			$wporg_get_heading(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the callback.
			esc_url( $create_url ),
			esc_html(
				sprintf(
					/* translators: %s: page slug */
					__( 'Create "%s" page', 'wporg-groups-frontend' ),
					$slug
				)
			)
		);
	}
	return;
}

$can_edit = current_user_can( 'edit_page', $target_page->ID );

if ( '' === trim( (string) $target_page->post_content ) && ! $can_edit ) {
	return;
}

$content = apply_filters( 'the_content', $target_page->post_content );
$content = str_replace( ']]>', ']]&gt;', $content );

$wrapper_attributes = get_block_wrapper_attributes();
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $wporg_get_heading(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the callback. ?>

	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered by the_content. ?>

	<?php if ( $can_edit ) : ?>
		<p class="wporg-page-content__edit">
			<a href="<?php echo esc_url( get_edit_post_link( $target_page->ID ) ); ?>">
				&#9998; <?php esc_html_e( 'Edit this content', 'wporg-groups-frontend' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
