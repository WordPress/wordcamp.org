<?php

/*
 * todo intro
 *
 *  note that report is in wordcamp-reports plugin
 *
 *
 *
 * ### primary todo
 *
 * Mockup email and report, get feedback from hugh
 *
 * Decide where script should live. probably wordcamp.org so has access to organizer email (via meetup post type), and reports plugin. probably need to add wporg_events to hyperdb
 *
 *
 *
 * Setup form for answering questions. gets event id via url parameter, so no human error. include date and title of the event, to avoid them accidentally mistaking with dfferent event; can pull from wporg_events where id=wporg_events_id. stores answers in wporg_events table? anywhere better? maybe a new hidden CPT, better than custom db table. don't really love the idea of extending the wporg_events table. xref to it using wporg_events_id field as primary key (or just a CPT meta field)? which plugin does this live in? meetup post type? new one? field for how many should be an int so we can chart it in future. maybe provide an "other" field for notes.
 *
 * Create report that people can run themselves to see the data. probably use reports plugin, but maybe see if you can streamline it a bit? talk to corey about how he feels about the OOP, abstractions, etc. report should be able to be restricted to date range, so will have to store the dates? maybe not, b/c can query against wporg_events to get dates, then use those ids to lookup the wporg_events_id field in the CPT. will want that field to be fast query though, which post meta isn't. can maybe use taxonomy, or repurpose some wp_posts field that is indexed?
 *
 *
 *
 * ### other todo:
 *
 *
 */



add_action( 'plugins_loaded', function() {
	/*

	how to get event organizer email b/c that's api - maybe through post type b/c have their username
		maybe can map meetup.com organizer list to meetup post type organizer usernames to emails associated w/ those usernames?


	Setup (daily? weekly?) cron job that queries for finished events since the last job.
	 * email the organizers the questions and link to answer them.
	 * include event id in the link. how to track which ones have already been sent the email?
	 * will need to account for fact that first time the job runs, none of the events will have been sent it, so it should only do ones in the past day.
	 *  that might happen naturally depending on how it's structured
	 * - make sure that don't have same problems as before w/ sending emails from crons. email should probably come from Central, so only run jbo on central.
	 *      this plugin only active on central though, so no problem there?
	 *
	 * use HTML email. can abstract some stuff from camptix as needed. make it easy to use that for all our emails
		 update footer line to be customizeable - "You are receiving this e-mail because you are a WordPress meetup organizer."
		 also header to community team not wordcamp central
	 *
	 */


	if ( ! is_main_site() || ! is_admin() ) {
		return;
	}

	/** @var CampTix_Plugin  $camptix */
	global $camptix;

	// send to _event_ organizer, not _lead_ organizer

	$message = "
		<p>Howdy!</p>

		<p>We'd love to gather some feedback on yesterday's event, to help the community something something something.</p>

		<p>
			<strong>
				WordPress Fundamentals (ONLINE)<br />
				Wednesday, April 15, 2020
			</strong>
		</p>

		<ul>
			<li>How many people attended?</li>
			<li>Do you feel like the event was successful?</li>svn in
		</ul>

		<p>
			<a href=\"\" style=\"text-decoration: none;\">
				Please let us know!
			</a>
		</p>
	";
	// best way to translate?
		// how to send in their language?

	$headers = array(
		'From' => 'WordPress Community Team <'. EMAIL_CENTRAL_SUPPORT .'>',    // maybe need to use default addr here to avoid spoofing penalty? if so, then add reply-to as support@
	);

	var_dump(
		$camptix->wp_mail( 'foo@example.org', "How was yesterday's meetup?", $message, $headers )
	);
	wp_die();
} );
