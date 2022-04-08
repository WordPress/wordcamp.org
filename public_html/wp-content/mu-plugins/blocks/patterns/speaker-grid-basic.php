<?php
/**
 * Title: Basic speaker grid
 * Slug: wordcamp/speaker-grid-basic
 * Categories: wordcamp
 */

?>
<!-- wp:query {"query":{"perPage":30,"pages":0,"offset":0,"postType":"wcb_speaker","order":"asc","orderBy":"title","author":"","search":"","exclude":[],"sticky":"","inherit":false},"displayLayout":{"type":"flex","columns":2},"namespace":"wordcamp/speakers-query","align":"wide","layout":{"inherit":true,"type":"constrained"}} -->
<div class="wp-block-query alignwide"><!-- wp:post-template {"align":"wide"} -->
<!-- wp:columns {"style":{"spacing":{"padding":{"top":"2em","right":"2em","bottom":"2em","left":"2em"}}},"backgroundColor":"tertiary"} -->
<div class="wp-block-columns has-tertiary-background-color has-background" style="padding-top:2em;padding-right:2em;padding-bottom:2em;padding-left:2em"><!-- wp:column {"verticalAlignment":"center","width":"112px"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:112px"><!-- wp:wordcamp/avatar {"className":"is-style-default"} /--></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"66.66%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:66.66%"><!-- wp:post-title {"textAlign":"left","isLink":true,"fontSize":"x-large"} /-->

<!-- wp:read-more {"content":"<?php esc_attr_e( 'Read More', 'wordcamp' ); ?>"} /--></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
