<!-- wp:paragraph -->
<p><?php echo esc_html( get_wordcamp_date_range( $wordcamp ) ); ?><br>
<?php echo nl2br( esc_html( get_wordcamp_location( $wordcamp ) ) ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2><?php esc_html_e( 'Schedule', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:shortcode -->
[schedule]
<!-- /wp:shortcode -->

<!-- wp:heading -->
<h2><?php esc_html_e( 'Latest Posts', 'wordcamporg' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:latest-posts {"displayPostDate":true} /-->
