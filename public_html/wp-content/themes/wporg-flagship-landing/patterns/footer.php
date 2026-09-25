<?php

/**
 * Title: Footer
 * Slug: wporg-flagship-landing/footer
 * Inserter: no
 */

namespace WordPressdotorg\Flagship_Landing;

?>

<!-- wp:group {"tagName":"footer","style":{"spacing":{"padding":{"right":"var:preset|spacing|edge-space","left":"var:preset|spacing|edge-space","top":"var:preset|spacing|edge-space","bottom":"var:preset|spacing|edge-space"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<footer class="wp-block-group" style="padding-top:var(--wp--preset--spacing--edge-space);padding-right:var(--wp--preset--spacing--edge-space);padding-bottom:var(--wp--preset--spacing--edge-space);padding-left:var(--wp--preset--spacing--edge-space)">
	<!-- wp:image {"sizeSlug":"full","linkDestination":"custom"} -->
	<figure class="wp-block-image size-full">
		<a href="https://wordpress.org/">
			<img src="<?php echo esc_url( get_stylesheet_directory_uri() ); ?>/images/w-mark.svg" alt="" />
		</a>
	</figure>
	<!-- /wp:image -->

	<!-- wp:image {"sizeSlug":"full","linkDestination":"none"} -->
	<figure class="wp-block-image size-full">
		<img
			src="<?php echo esc_url( get_stylesheet_directory_uri() ); ?>/images/code-is-poetry-for-light-bg.svg"
			alt=""
		/>
	</figure>
	<!-- /wp:image -->

	<!-- wp:social-links {"iconColor":"charcoal-1","iconColorValue":"#1e1e1e","className":"is-style-logos-only"} -->
	<ul class="wp-block-social-links has-icon-color is-style-logos-only">
		<!-- wp:social-link {"url":"https://www.facebook.com/WordPress/","service":"facebook"} /-->
		<!-- wp:social-link {"url":"https://twitter.com/wordpress","service":"x"} /-->
	</ul>
	<!-- /wp:social-links -->
</footer><!-- /wp:group -->
