<?php
// phpcs:ignoreFile
/**
 * Template: Single Speaker
 */
?>

<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|50"}}}} -->
<main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--50)"><!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:group {"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"left"}} -->
<div class="wp-block-group" style="margin-bottom:var(--wp--preset--spacing--40)"><!-- wp:wordcamp/avatar {"className":"is-style-rounded"} /-->

<!-- wp:post-title {"level":1} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<!-- wp:post-content {"layout":{"type":"constrained"}} /-->

<!-- wp:group {"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="margin-bottom:var(--wp--preset--spacing--70)"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php esc_html_e( 'Sessions', 'wordcamporg' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:wordcamp/speaker-sessions {"hasSessionDetails":true,"isLink":true,"style":{"spacing":{"padding":{"top":"0","right":"0","bottom":"0","left":"0"}}}} /--></div>
<!-- /wp:group --></main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
