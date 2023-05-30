<!-- wp:group {"style":{"color":{"background":"#eeeeee"},"spacing":{"padding":{"top":"1em","right":"1em","bottom":"1em","left":"1em"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#eeeeee;padding-top:1em;padding-right:1em;padding-bottom:1em;padding-left:1em"><!-- wp:paragraph -->
<p><strong><em>Organizers notes:</em></strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Multi-event sponsors have been automatically created in the Sponsors menu, but you'll need to remove the ones that don't apply to your specific event. To find out which ones apply, please visit the <a href="https://make.wordpress.org/community/handbook/wordcamp-organizer/planning-details/fundraising/global-community-sponsorship-for-event-organizers/">Global Community Sponsorship</a> handbook page. After that, you should add the sponsors that are specific to your event. For non-English sites, make sure the URL below matches the Call for Sponsors page.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>The "Call for Sponsors" post was created as a draft, but there is a link to it below. Please update the content of that post, and publish it, before you turn off Coming Soon mode.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Our Sponsors</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Blurb thanking sponsors</p>
<!-- /wp:paragraph -->

<!-- wp:wordcamp/sponsors {"mode":"all"} /-->

<!-- wp:heading -->
<h2 class="wp-block-heading">Interested in sponsoring WordCamp this year?</h2>
<!-- /wp:heading -->

<?php
/*
 * Update the slug in `get_stub_posts()` if the slug below ever changes.
 *
 * This can't use `get_permalink()`, because this file is executed in a `switch_to_blog()` context. `home_url()`
 * works because we can assume the permastruct is just `%postname%`.
 */
?>
<!-- wp:paragraph -->
<p>Check out our <a href="<?php echo esc_url( home_url( '/call-for-sponsors/' ) ); ?>">Call for Sponsors</a> post for details on how you can help make this year's WordCamp the best it can be!</p>
<!-- /wp:paragraph -->
