<?php
/**
 * Title: Events Query: No Results
 * Slug: wporg-events-2023/events-query-no-results
 * Inserter: no
 */

?>

<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"textAlign":"center","level":1,"fontSize":"heading-2"} -->
	<h1 class="wp-block-heading has-text-align-center has-heading-2-font-size">
		<?php esc_html_e( 'No events found', 'wporg' ); ?>
	</h1>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">
		<?php
		printf(
			wp_kses_data(
				/* translators: %s is url of the event archives. */
				__( 'View <a href="%s">upcoming events</a> or try a different search.', 'wporg' )
			),
			esc_url( home_url( '/upcoming-events/' ) )
		);
		?>
	</p>
	<!-- /wp:paragraph -->
</div> <!-- /wp:group -->
