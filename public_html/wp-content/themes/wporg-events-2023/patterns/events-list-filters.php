<?php
/**
 * Title: Events List Filters
 * Slug: wporg-events-2023/event-list-filters
 * Inserter: no
 */

?>

<!-- wp:group {"align":"wide","className":"wporg-events__filters","style":{"spacing":{"margin":{"top":"40px","bottom":"40px"}}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group alignwide wporg-events__filters" style="margin-top:40px;margin-bottom:40px">
	<!-- wp:group {"className":"wporg-events__filters__search","layout":{"type":"flex","flexWrap":"wrap"}} -->
	<div class="wp-block-group wporg-events__filters__search">
		<!-- wp:search {"showLabel":false,"placeholder":"Search events...","width":100,"widthUnit":"%","buttonText":"Search","buttonPosition":"button-inside","buttonUseIcon":true,"className":"is-style-secondary-search-control"} /-->
	</div> <!-- /wp:group -->

	<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"className":"wporg-query-filters","layout":{"type":"flex","flexWrap":"nowrap"}} -->
	<div class="wp-block-group wporg-query-filters">
		<!-- wp:wporg/query-filter {"key":"format_type","multiple":false} /-->
		<!-- wp:wporg/query-filter {"key":"event_type","multiple":false} /-->
		<!-- wp:wporg/query-filter {"key":"month","multiple":false} /-->
		<!-- wp:wporg/query-filter {"key":"country","multiple":false} /-->
	</div> <!-- /wp:group -->

</div> <!-- /wp:group -->
