<?php
/**
 * Title: Events Query
 * Slug: wporg-events-2023/events-query
 * Inserter: no
 */

?>

<!-- wp:query {"queryId":0,"query":{"perPage":500,"pages":0,"offset":0,"postType":"wporg_events","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false,"parents":[]},"className":"wporg-events-query"} -->
<div class="wp-block-query wporg-events-query">
	<?php
	// This has to be `require`d, because using `<!-- wp:pattern ...` won't pass the query ID context to the
	// query-total block, and the number of events won't match.
	require __DIR__ . '/events-list-filters.php';
	?>

	<!-- wp:post-template {"className":"wporg-marker-list__container"} -->

		<li class="wporg-marker-list-item">
			<h3 class="wporg-marker-list-item__title">
				<a class="external-link" href=" $event->url ">
			        <!-- wp:post-title {"isLink":true,"level":2,"style":{"typography":{"fontStyle":"normal","fontWeight":"400","lineHeight":"inherit"}},"fontSize":"normal","fontFamily":"inter"} /-->
			    </a>
			</h3>

			<div class="wporg-marker-list-item__location">
				<!-- wp:wporg/post-meta {"key":"location"} /-->
			</div>

			<?php // todo have to create a block for this, and anything else that uses post context ?>
			<time
				class="wporg-marker-list-item__date-time"
				date-time="gmdate( 'c', esc_html( $event->timestamp ) )"
				title="gmdate( 'c', esc_html( $event->timestamp ) )"
			>
				<span class="wporg-google-map__date">
					<!-- wp:wporg/post-meta {"key":"timestamp"} /-->
					<?php // gmdate( 'l, M j', esc_html( $event->timestamp ) ) ?>
				</span>

				<span class="wporg-google-map__time">
					<!-- wp:wporg/post-meta {"key":"timestamp"} /-->
					<?php // esc_html( gmdate('H:i', $event->timestamp) . ' UTC' ) ?>
				</span>
			</time>
		</li>
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
