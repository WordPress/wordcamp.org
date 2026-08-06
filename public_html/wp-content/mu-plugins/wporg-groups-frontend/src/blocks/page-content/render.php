<?php
/**
 * Server-side rendering for the wporg/page-content block.
 *
 * Renders a page's content by slug, with an edit link for organisers.
 *
 * The optional `heading` attribute renders a section heading *inside* the
 * block rather than beside it in the template. The block renders nothing at
 * all when the target page is missing and the viewer can't create it, so a
 * heading placed in the template would be left stranded above empty space —
 * which is what happened on the front page's "About this group" section for
 * every group that hadn't written an about page yet.
 *
 * @package WordCamp\Groups\Frontend
 */

$slug          = $attributes['slug'] ?? '';
$heading       = trim( (string) ( $attributes['heading'] ?? '' ) );
$heading_level = (int) ( $attributes['headingLevel'] ?? 2 );
$heading_level = min( 6, max( 1, $heading_level ) );

/**
 * Build the optional section heading.
 *
 * Called only from branches that are about to emit content, so the heading
 * never appears on its own.
 *
 * @return string Escaped heading HTML, or an empty string when unset.
 */
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
	// If the page doesn't exist yet, show a prompt for organisers. Rendered
	// inside the block wrapper so the section keeps the layout classes the
	// template gave it — without it the prompt loses the front page's section
	// spacing and collides with whatever precedes it.
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

// A published-but-empty page is the same dead end as a missing one for a
// visitor: render nothing rather than a heading over blank space. Organisers
// still get the block so they have somewhere to click through and write it.
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
