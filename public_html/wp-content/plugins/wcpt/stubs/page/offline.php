<!-- wp:paragraph {"customBackgroundColor":"#eeeeee"} -->
<p style="background-color:#eeeeee" class="has-background">Organizers note: Update this page with some basic information about your WordCamp. It will be stored in site visitor's browsers, and automatically shown if they try to visit the site without an internet connection.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>This page couldn't be loaded because you appear to be offline. Please try again once you have a network connection.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Location & Date</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html( get_wordcamp_date_range( $wordcamp ) ); ?><br>
<?php echo nl2br( esc_html( get_wordcamp_location( $wordcamp ) ) ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Schedule</h2>
<!-- /wp:heading -->

<!-- wp:wordcamp/schedule /-->
