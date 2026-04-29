<?php
/**
 * Server-side rendering for the wporg/page-content block.
 *
 * Renders a page's content by slug, with an edit link for organisers.
 *
 * @package WordCamp\Groups\Frontend
 */

$slug = $attributes['slug'] ?? '';

if ( empty( $slug ) ) {
	return;
}

$page = get_page_by_path( $slug );

if ( ! $page || 'publish' !== $page->post_status ) {
	// If the page doesn't exist yet, show a prompt for organisers.
	if ( current_user_can( 'edit_pages' ) ) {
		$create_url = admin_url( 'post-new.php?post_type=page&post_title=' . urlencode( ucfirst( $slug ) ) );
		printf(
			'<p><a href="%s" class="wp-element-button">%s</a></p>',
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

$content = apply_filters( 'the_content', $page->post_content );
$content = str_replace( ']]>', ']]&gt;', $content );

$wrapper_attributes = get_block_wrapper_attributes();
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered by the_content. ?>

	<?php if ( current_user_can( 'edit_page', $page->ID ) ) : ?>
		<p class="wporg-page-content__edit">
			<a href="<?php echo esc_url( get_edit_post_link( $page->ID ) ); ?>">
				&#9998; <?php esc_html_e( 'Edit this content', 'wporg-groups-frontend' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
