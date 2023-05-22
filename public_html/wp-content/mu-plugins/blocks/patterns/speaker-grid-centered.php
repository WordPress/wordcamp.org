<?php
/**
 * Title: Speaker grid, centered
 * Slug: wordcamp/speaker-grid-centered
 * Categories: wordcamp
 * Block Types: core/query
 */

?>
<!-- wp:query {"query":{"perPage":99,"pages":0,"offset":0,"postType":"wcb_speaker","order":"asc","orderBy":"title","author":"","search":"","exclude":[],"sticky":"","inherit":false},"displayLayout":{"type":"flex","columns":3},"namespace":"wordcamp/speakers-query","align":"wide","layout":{"inherit":true,"type":"constrained"}} -->
<div class="wp-block-query alignwide"><!-- wp:post-template {"align":"wide"} -->
<!-- wp:wordcamp/avatar {"align":"center","className":"is-style-rounded"} /-->

<!-- wp:post-title {"textAlign":"center","isLink":true,"fontSize":"x-large"} /-->

<!-- wp:group {"layout":{"inherit":true,"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:read-more {"content":"<?php esc_attr_e( 'Read More', 'wordcamp' ); ?>"} /--></div>
<!-- /wp:group -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
