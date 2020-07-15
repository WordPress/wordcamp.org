<!-- wp:list {"className":"has-background"} -->
<ul class="has-background"><li><span class="has-inline-color has-accent-color"><strong><em>Organizers notes:</em> </strong></span></li><li><span class="has-inline-color has-accent-color">Multi-event sponsors have been automatically created in the Sponsors menu, but you'll need to remove the ones that don't apply to your specific event. To find out which ones apply, please visit the <a href="https://make.wordpress.org/community/handbook/wordcamp-organizer/planning-details/fundraising/global-community-sponsorship-for-event-organizers/">Global Community Sponsorship</a> handbook page. After that, you should add the sponsors that are specific to your event. For non-English sites, make sure the URL below matches the Call for Sponsors page.</span></li><li><span class="has-inline-color has-accent-color">The "Call for Sponsors" post was created as a draft, but there is a link to it below. Please update the content of that post, and publish it, before you turn off Coming Soon mode. </span></li></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>Our Sponsors</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Blurb thanking sponsors</p>
<!-- /wp:paragraph -->

<!-- wp:wordcamp/sponsors {"mode":"all"} /-->

<!-- wp:heading -->
<h2>Interested in sponsoring WordCamp this year?</h2>
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
<p>Check out our <a href="<?php echo home_url( '/call-for-sponsors/' ); ?>">Call for Sponsors</a> post for details on how you can help make this year's WordCamp the best it can be!</p>
<!-- /wp:paragraph -->
