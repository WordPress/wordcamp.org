<!-- wp:paragraph {"backgroundColor":"accent","textColor":"background","className":"has-accent-background-color has-background"} -->
<p class="has-accent-background-color has-background has-background-color has-text-color"><em>Organizers Note:</em>
	Submissions to this form will automatically create <code>draft</code>
	<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wcb_volunteer' ) ); ?>">Volunteer posts</a>.
	You can use those to manage your volunteers, by publishing the ones that you accept, and deleting the ones that you don't.
	Changing the <code>name</code>, <code>email</code>, <code>username</code>, or <code>first time volunteering</code> questions can break the automation, though.
</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Blurb with information for potential volunteers.</p>
<!-- /wp:paragraph -->

<!-- wp:jetpack/contact-form {"subject":"WordCamp Volunteer Application","hasFormSettingsSet":"yes"} -->
<!-- wp:jetpack/field-name {"label":"Name","required":true} /-->
<!-- wp:jetpack/field-email {"label":"Email","required":true,"requiredText":"(required)","id":"volunteer-email"} /-->

<!-- wp:jetpack/field-text {"label":"WordPress.org Username","requiredText":"(required)","id":"volunteer-username"} /-->

<!-- wp:jetpack/field-textarea {"label":"Skills / Interests / Experience (not necessary to volunteer)","required":true} /-->

<!-- wp:jetpack/field-text {"label":"Number of Hours Available","required":true} /-->

<!-- wp:jetpack/field-radio {"label":"Is this the first time you have volunteered at a WordPress event?","requiredText":"(required)","options":["Yes","No","Unsure"],"id":"first-time-volunteer"} /-->

<!-- wp:jetpack/field-textarea {"label":"Questions / Comments"} /-->
<!-- /wp:jetpack/contact-form -->
