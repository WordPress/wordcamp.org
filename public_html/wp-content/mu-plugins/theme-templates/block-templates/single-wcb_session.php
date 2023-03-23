<?php
// phpcs:ignoreFile
/**
 * Template: Single Session
 */
?>

<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|50"}}}} -->
<main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--50)"><!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:post-title {"level":1,"style":{"spacing":{"margin":{"bottom":"var:preset|spacing|40"}}}} /--></div>
<!-- /wp:group -->

<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:wordcamp/session-speakers {"byline":"<?php esc_html_e( 'Presented by', 'wordcamporg' ); ?>","isLink":true} /-->

<!-- wp:wordcamp/session-date {"format":"l g:i A"} /-->

<!-- wp:wordcamp/meta-link {"key":"_wcpt_session_video","text":"<?php esc_html_e( 'Play Session Video', 'wordcamporg' ); ?>"} /-->

<!-- wp:wordcamp/meta-link {"key":"_wcpt_session_slides","text":"<?php esc_html_e( 'View Session Slides', 'wordcamporg' ); ?>"} /--></div>
<!-- /wp:group -->

<!-- wp:post-content {"layout":{"type":"constrained"}} /-->

<!-- wp:spacer {"height":"0"} -->
<div style="height:0" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:group {"style":{"spacing":{"margin":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="margin-top:var(--wp--preset--spacing--70);margin-bottom:var(--wp--preset--spacing--70)"><!-- wp:separator {"opacity":"css","align":"wide","className":"is-style-wide"} -->
<hr class="wp-block-separator alignwide has-css-opacity is-style-wide"/>
<!-- /wp:separator -->

<!-- wp:columns {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|30"},"blockGap":"var:preset|spacing|30"}},"fontSize":"small"} -->
<div class="wp-block-columns alignwide has-small-font-size" style="margin-top:var(--wp--preset--spacing--30)"><!-- wp:column {"style":{"spacing":{"blockGap":"0px"}}} -->
<div class="wp-block-column"><!-- wp:group {"style":{"spacing":{"blockGap":"0.5ch"}},"layout":{"type":"flex"}} -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p><?php esc_html_e( 'Categories:', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:post-terms {"term":"wcb_session_category"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column {"style":{"spacing":{"blockGap":"0px"}}} -->
<div class="wp-block-column"><!-- wp:group {"style":{"spacing":{"blockGap":"0.5ch"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p><?php esc_html_e( 'Tracks:', 'wordcamporg' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:post-terms {"term":"wcb_track"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group --></main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
