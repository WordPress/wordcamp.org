<!-- wp:paragraph -->
<p><?php echo esc_html( get_wordcamp_date_range( $wordcamp ) ); ?><br>
<?php echo nl2br( esc_html( get_wordcamp_location( $wordcamp ) ) ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Schedule</h2>
<!-- /wp:heading -->

<!-- wp:wordcamp/live-schedule {"level":3} -->
<div data-now="On Now" data-next="Next Up" data-level="3" class="wp-block-wordcamp-live-schedule"></div>
<!-- /wp:wordcamp/live-schedule -->

<!-- wp:heading -->
<h2>Latest Posts</h2>
<!-- /wp:heading -->

<!-- wp:latest-posts {"displayPostDate":true,"liveUpdateEnabled":true} /-->
