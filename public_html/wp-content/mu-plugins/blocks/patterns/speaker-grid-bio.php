<?php
/**
 * Title: Speaker grid with bio
 * Slug: wordcamp/speaker-grid-bio
 * Categories: wordcamp
 * Block Types: core/query
 */

?>
<!-- wp:query {"query":{"perPage":50,"pages":0,"offset":0,"postType":"wcb_speaker","order":"asc","orderBy":"title","author":"","search":"","exclude":[],"sticky":"","inherit":false},"displayLayout":{"type":"flex","columns":2},"namespace":"wordcamp/speakers-query","align":"wide"} -->
<div class="wp-block-query alignwide"><!-- wp:post-template -->
<!-- wp:group {"style":{"spacing":{"blockGap":"1rem","padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"},"margin":{"top":"20px","bottom":"20px"}},"border":{"style":"solid","width":"1px"}}} -->
<div class="wp-block-group" style="border-style:solid;border-width:1px;margin-top:20px;margin-bottom:20px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px"><!-- wp:wordcamp/avatar {"size":128} /-->

<!-- wp:post-title {"isLink":true} /-->

<!-- wp:post-excerpt /-->

<!-- wp:separator {"opacity":"css","className":"is-style-dots"} -->
<hr class="wp-block-separator has-css-opacity is-style-dots"/>
<!-- /wp:separator --></div>
<!-- /wp:group -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
