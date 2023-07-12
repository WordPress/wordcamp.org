<!-- wp:paragraph {"backgroundColor":"accent","textColor":"background","className":"has-accent-background-color has-background"} -->
<p class="has-accent-background-color has-background has-background-color has-text-color"><em>Organizers Note:</em>
	Submissions to this form will automatically create <code>draft</code>
	<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wcb_sponsor' ) ); ?>">Sponsor posts</a>.
	You can use those to manage your sponsors, by publishing the ones that you accept, and deleting the ones that you don't.
	Changing the <code>name</code>, <code>email</code>, <code>username</code>, or <code>first time sponsoring</code> questions can break the automation, though.
</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Blurb with information for potential sponsors.</p>
<!-- /wp:paragraph -->

<!-- wp:jetpack/contact-form {"subject":"WordCamp Sponsor Request","hasFormSettingsSet":"yes"} -->
<!-- wp:jetpack/field-text {"label":"Contact Name","required":true} /-->

<!-- wp:jetpack/field-name {"label":"Company Name","required":true} /-->

<!-- wp:jetpack/field-url {"label":"Company Website","required":true} /-->

<!-- wp:jetpack/field-email {"label":"Email","required":true,"requiredText":"(required)","id":"sponsor-email"} /-->

<!-- wp:jetpack/field-telephone {"label":"Phone Number"} /-->

<!-- wp:jetpack/field-select {"label":"Sponsorship Level","options":["Bronze","Silver","Gold"]} /-->

<!-- wp:jetpack/field-textarea {"label":"Why Would you Like to Sponsor WordCamp?","required":true} /-->

<!-- wp:jetpack/field-radio {"label":"Is this your first time being a sponsor at a WordPress event?","requiredText":"(required)","options":["Yes","No","Unsure"],"id":"first-time-sponsor"} /-->

<!-- wp:jetpack/field-textarea {"label":"Questions / Comments"} /-->
<!-- /wp:jetpack/contact-form -->
