<?php
/**
 * Title: Basic speaker list
 * Slug: wordcamp/speaker-list-basic
 * Categories: wordcamp
 * Block Types: core/query
 */

?>
<!-- wp:query {"queryId":4,"query":{"perPage":99,"pages":0,"offset":0,"postType":"wcb_speaker","order":"asc","orderBy":"title","author":"","search":"","exclude":[],"sticky":"","inherit":false},"namespace":"wordcamp/speakers-query","layout":{"inherit":false}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:wordcamp/avatar {"align":"center","className":"is-style-rounded"} /-->

<!-- wp:post-title {"textAlign":"center"} /-->

<!-- wp:group {"layout":{"inherit":true,"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:read-more {"content":"<?php esc_attr_e( 'Read More', 'wordcamp' ); ?>"} /--></div>
<!-- /wp:group -->

<!-- wp:spacer {"height":"50px"} -->
<div style="height:50px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:separator {"opacity":"css"} -->
<hr class="wp-block-separator has-css-opacity"/>
<!-- /wp:separator -->

<!-- wp:spacer {"height":"50px"} -->
<div style="height:50px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
