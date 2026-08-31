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
$excerpt_mode  = ! empty( $attributes['excerpt'] );

// When the heading sits in the header row next to the edit link, the row
// carries the bottom margin; standalone (the create/finish-draft fallback),
// the heading carries it itself.
$wporg_get_heading = static function ( bool $in_header = false ) use ( $heading, $heading_level ): string {
	if ( '' === $heading ) {
		return '';
	}

	return sprintf(
		'<h%1$d class="wp-block-heading has-heading-3-font-size" style="margin-bottom:%2$s">%3$s</h%1$d>',
		$heading_level,
		$in_header ? '0' : 'var(--wp--preset--spacing--30)',
		esc_html( $heading )
	);
};

if ( empty( $slug ) ) {
	return;
}

$target_page = get_page_by_path( $slug );

if ( ! $target_page || 'publish' !== $target_page->post_status ) {
	if ( current_user_can( 'edit_pages' ) ) {
		// An unpublished page (e.g. the draft seeded during site
		// provisioning) gets an edit link; creating a new page here would
		// leave a duplicate slug behind.
		$edit_url = $target_page ? get_edit_post_link( $target_page->ID, 'url' ) : '';

		if ( $edit_url ) {
			$link_url  = $edit_url;
			$link_text = sprintf(
				/* translators: %s: page slug */
				__( 'Finish the draft "%s" page', 'wporg-groups-frontend' ),
				$slug
			);
		} else {
			$link_url  = admin_url( 'post-new.php?post_type=page&post_title=' . urlencode( ucfirst( $slug ) ) );
			$link_text = sprintf(
				/* translators: %s: page slug */
				__( 'Create "%s" page', 'wporg-groups-frontend' ),
				$slug
			);
		}

		printf(
			'<div %1$s>%2$s<p><a href="%3$s" class="wp-element-button">%4$s</a></p></div>',
			get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
			$wporg_get_heading(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the callback.
			esc_url( $link_url ),
			esc_html( $link_text )
		);
	}
	return;
}

$can_edit = current_user_can( 'edit_page', $target_page->ID );

if ( '' === trim( (string) $target_page->post_content ) && ! $can_edit ) {
	return;
}

/*
 * Excerpt mode renders a strict prefix of the page rather than the full
 * content: leading prose blocks up to a small budget, stopped at the first
 * block that isn't prose. A prefix keeps the teaser predictable for the
 * organizer — it always reads as "the beginning of the page", never as
 * paragraphs stitched together around skipped media — and it structurally
 * keeps arbitrary page content (extra headings, galleries, embeds) from
 * reshaping the page embedding this block.
 */
$is_truncated = false;

if ( $excerpt_mode ) {
	$wporg_prose_blocks = array( 'core/paragraph', 'core/list', 'core/quote' );
	$wporg_max_blocks   = 3;
	$wporg_max_chars    = 700;

	// Whether anything renderable exists at or after this index.
	$wporg_has_content_after = static function ( array $blocks, int $index ): bool {
		$remaining = array_slice( $blocks, $index );

		foreach ( $remaining as $block ) {
			if ( null !== $block['blockName'] || '' !== trim( (string) $block['innerHTML'] ) ) {
				return true;
			}
		}

		return false;
	};

	$blocks  = parse_blocks( $target_page->post_content );
	$content = '';
	$count   = 0;
	$chars   = 0;
	$total   = count( $blocks );

	for ( $i = 0; $i < $total; $i++ ) {
		$name = $blocks[ $i ]['blockName'];

		// parse_blocks() yields the whitespace between blocks as empty
		// null-name blocks; classic (non-block) content arrives the same
		// way but non-empty, and falls through to the not-prose stop below.
		if ( null === $name && '' === trim( (string) $blocks[ $i ]['innerHTML'] ) ) {
			continue;
		}

		// An explicit "More" block is the organizer's own cut point and
		// overrides the heuristics.
		if ( 'core/more' === $name ) {
			$is_truncated = $wporg_has_content_after( $blocks, $i + 1 );
			break;
		}

		// A heading before any prose is the page's own title; the frame
		// embedding this block already supplies one.
		if ( 'core/heading' === $name && 0 === $count ) {
			continue;
		}

		if ( ! in_array( $name, $wporg_prose_blocks, true ) ) {
			$is_truncated = true;
			break;
		}

		$rendered = render_block( $blocks[ $i ] );
		$length   = mb_strlen( wp_strip_all_tags( $rendered ) );

		// Cut only at block boundaries so the teaser never ends
		// mid-sentence; the first block always renders regardless of size.
		if ( $count > 0 && $chars + $length > $wporg_max_chars ) {
			$is_truncated = true;
			break;
		}

		$content .= $rendered;
		$chars   += $length;
		++$count;

		if ( $count >= $wporg_max_blocks ) {
			$is_truncated = $wporg_has_content_after( $blocks, $i + 1 );
			break;
		}
	}

	// Nothing prose-y to lead with (e.g. the page opens with an image):
	// fall back to just the heading and the read-more link.
	if ( '' === $content ) {
		$is_truncated = true;
	}
} else {
	$content = apply_filters( 'the_content', $target_page->post_content );
	$content = str_replace( ']]>', ']]&gt;', $content );
}

$wrapper_attributes = get_block_wrapper_attributes();
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( '' !== $heading || $can_edit ) : ?>
		<div class="wporg-page-content__header">
			<?php echo $wporg_get_heading( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the callback. ?>

			<?php if ( $can_edit ) : ?>
				<a class="wporg-page-content__edit" href="<?php echo esc_url( get_edit_post_link( $target_page->ID ) ); ?>">
					&#9998; <?php esc_html_e( 'Edit this content', 'wporg-groups-frontend' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered by the_content. ?>

	<?php if ( $is_truncated ) : ?>
		<p class="wporg-page-content__more has-small-font-size">
			<a href="<?php echo esc_url( get_permalink( $target_page ) ); ?>">
				<?php esc_html_e( 'Read more', 'wporg-groups-frontend' ); ?> &rarr;
			</a>
		</p>
	<?php endif; ?>
</div>
