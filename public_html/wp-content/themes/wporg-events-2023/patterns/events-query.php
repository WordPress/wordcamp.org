<?php
/**
 * Title: Events Query
 * Slug: wporg-events-2023/events-query
 * Inserter: no
 */

?>

<!-- wp:query {"queryId":0,"query":{"perPage":500,"pages":0,"offset":0,"postType":"wporg_events","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false,"parents":[]},"className":"wporg-events-query"} -->
<div class="wp-block-query wporg-events-query">
	<!-- wp:pattern {"slug":"wporg-events-2023/events-list-filters"} /-->

	<!-- wp:post-template {"className":"wporg-marker-list__container"} -->
		<!-- wp:wporg/event-list {"groupByMonth":true} /-->
	<!-- /wp:post-template -->

	<!-- wp:query-pagination -->
		<!-- wp:query-pagination-previous /-->
		<!-- wp:query-pagination-numbers /-->
		<!-- wp:query-pagination-next /-->
	<!-- /wp:query-pagination -->

	<!-- wp:query-no-results -->
		<!-- wp:pattern {"slug":"wporg-events-2023/events-query-no-results"} /-->
	<!-- /wp:query-no-results -->
</div> <!-- /wp:query -->
