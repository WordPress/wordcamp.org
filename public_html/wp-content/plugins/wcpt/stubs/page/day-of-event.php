<!-- wp:paragraph -->
<p><?php echo esc_html( get_wordcamp_date_range( $wordcamp ) ); ?><br>
<?php echo nl2br( esc_html( get_wordcamp_location( $wordcamp ) ) ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2><?php esc_html_e( 'Schedule', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:wordcamp/live-schedule {"level":3} -->
<div data-now="<?php esc_html_e( 'On Now', 'wordcamporg' ); ?>" data-next="<?php esc_html_e( 'Next Up', 'wordcamporg' ); ?>" data-level="3" class="wp-block-wordcamp-live-schedule"></div>
<!-- /wp:wordcamp/live-schedule -->

<!-- wp:heading -->
<h2><?php esc_html_e( 'Latest Posts', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:latest-posts {"displayPostDate":true,"liveUpdateEnabled":true} /-->
