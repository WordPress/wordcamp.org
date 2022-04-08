<?php
/**
 * Title: Session list with description
 * Slug: wordcamp/session-list-basic
 * Categories: wordcamp
 */

?>
<!-- wp:query {"query":{"perPage":99,"pages":0,"offset":0,"postType":"wcb_session","order":"asc","orderBy":"session_date","author":"","search":"","exclude":[],"sticky":"","inherit":false,"wc_meta_key":"_wcpt_session_type","wc_meta_value":"session"},"displayLayout":{"type":"list"},"namespace":"wordcamp/sessions-query","layout":{"inherit":true,"type":"constrained"}} -->
<div class="wp-block-query"><!-- wp:post-template {"align":"wide"} -->
<!-- wp:group {"style":{"visualizers":{"padding":{"top":true,"right":true,"bottom":true,"left":true}}},"layout":{"inherit":true,"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:post-title {"isLink":true,"align":"wide","fontSize":"var(\u002d\u002dwp\u002d\u002dcustom\u002d\u002dtypography\u002d\u002dfont-size\u002d\u002dhuge, clamp(2.25rem, 4vw, 2.75rem))"} /-->

<!-- wp:wordcamp/session-date /-->

<!-- wp:post-content /-->

<!-- wp:post-terms {"term":"wcb_track","fontSize":"small"} /-->

<!-- wp:spacer {"height":"16px"} -->
<div style="height:16px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:separator {"opacity":"css","className":"alignwide is-style-wide"} -->
<hr class="wp-block-separator has-css-opacity alignwide is-style-wide"/>
<!-- /wp:separator -->

<!-- wp:spacer {"height":"16px"} -->
<div style="height:16px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
