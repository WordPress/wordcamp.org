<?php
/**
 * Title: Events List Filters Archive
 * Slug: wporg-events-2023/event-list-filters-archive
 * Inserter: no
 */

?>

<!-- wp:group {"align":"wide","style":{"spacing":{"margin":{"top":"40px","bottom":"40px"}}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group alignwide" style="margin-top:40px;margin-bottom:40px">
	<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} -->
	<div class="wp-block-group">
		<!-- wp:search {"showLabel":false,"placeholder":"Search events...","width":100,"widthUnit":"%","buttonText":"Search","buttonPosition":"button-inside","buttonUseIcon":true,"className":"is-style-secondary-search-control"} /-->
        <!-- wp:query {"queryId":0,"query":{"perPage":500,"pages":0,"offset":0,"postType":"wporg_events","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false,"parents":[]},"className":"wporg-events-query"} -->
            <!-- wp:wporg/query-total /-->
        <!-- /wp:query -->
    </div> <!-- /wp:group -->

	<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"className":"wporg-query-filters","layout":{"type":"flex","flexWrap":"nowrap"}} -->
	<div class="wp-block-group wporg-query-filters">
		<!-- wp:wporg/query-filter {"key":"format_type","multiple":false} /-->
		<!-- wp:wporg/query-filter {"key":"event_type","multiple":false} /-->
		<!-- wp:wporg/query-filter {"key":"month","multiple":false} /-->
		<!-- wp:wporg/query-filter {"key":"country","multiple":false} /-->
	</div> <!-- /wp:group -->

</div> <!-- /wp:group -->
