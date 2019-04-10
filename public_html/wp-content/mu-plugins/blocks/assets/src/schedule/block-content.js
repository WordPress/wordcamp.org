/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { Component, Fragment } = wp.element;
const { __ }                  = wp.i18n;

/**
 * Internal dependencies
 */
import './block-content.scss';


/* todo
 *
 * IMPORTANT: refer to https://codepen.io/mrwweb/pen/ZaONLW for lots of critical notes and info, also https://meta.trac.wordpress.org/ticket/3117
 *
 * markup/css needs to have fallback for unsupported browsers? doesn't have to look good, but has to be legible/accessible
 *      will babel add prefix for ie11?
 *      if so, still need to do anything special? if so, enough to just wrap stuff in @supports?
 *
 * test and make compatible w/ personalized schedule builder - maybe by making that add support for this rather than this be back-compat w/ that
 * test all the scenarios listed at https://meta.trac.wordpress.org/ticket/3117#comment:2. lightning talks too, if not already covered there.
 *
 * test other camps, like seattle 2017, berlin 2017, 2018.montreal, wcus, new york, europe, boston, kansas city, miama, tokyo, etc across various years
 * 	    create live posts that mimics all the situations mark covered in the static mockup
 * 	    what will this look lke with 15 tracks ala 2012.nyc? or a slightly-less-unreasonable 8 tracks ala 2009.newyork ? probably don't need to handle those edge cases
 *
 * This should probably output the session categories in addition to the session tracks. it'd be better to do that in actual markup, rather than with CSS content
 * it'd be good to keep #3842 in mind, so that the room names can be included in the responsive view.
 *
 * maybe want a responsive preview button in the toolbar so can preview mobile view?
 *      maybe G should have that feature for all blocks instead?
 *      maybe should just happen naturally? if viewport is small then they'd see the responsive layout anyway, and if bigger they'll see grid?
 *
 * maybe some of these should be shared components? nah, but maybe some should be separate files/components within this folder
 *
 * patch fixes #3117, #3842
 * props mark, mel, others
 */

function ScheduleDay( date, sessions ) {
	const dateSlug   = 'YYYYMMDD'; // todo
	const dateString = 'Saturday, January 21st'; //todo, should pass in a timestamp or something and convert it here

	// "Thinking further about that markup, it would probably make sense to put each day’s sessions into a section element which would then need that heading."
		// done, ask mark what he thinks

	return (
		<Fragment>
			<h2 className="wordcamp-schedule-date">{dateString}</h2>   {/* this needs to be editable, should also be a separate Heading block. so when inserting a schedule block,  */}
			{/*We can make the text editable, though, with a reasonable default. If they remove the text, then we can automatically remove the corresponding h2 tag, to avoid leaving an artifact behind that affects margins/etc.*/}

			<section id={ `wordcamp-schedule-day-${dateSlug}` } className="wordcamp-schedule-day">    {/* todo any other classes? */}
				<GridColumnHeaders />
				<Sessions />
			</section>
		</Fragment>
	);
}

function GridColumnHeaders() {
	// same for Sessions()

	// were there any html updates in the 4.diff that need to be synced here?

	return (
		<Fragment>
			{/* should these have a more semantic thing equivalent to <th> ?
			maybe rename wordcamp-schedule-track-slot to wordcamp-schedule-track-heading?
			 */}
			<span className="wordcamp-schedule-column-header" aria-hidden="true" style={ { gridColumn: 'times' } }>Time</span>    {/* maybe different name because needs to be unique to avoid conflicting styles? */}
			<span className="wordcamp-schedule-column-header" aria-hidden="true" style={ { gridColumn: 'wordcamp-schedule-track-1' } }>Auditorium</span>
			<span className="wordcamp-schedule-column-header" aria-hidden="true" style={ { gridColumn: 'wordcamp-schedule-track-2' } }>Ballroom</span>
			<span className="wordcamp-schedule-column-header" aria-hidden="true" style={ { gridColumn: 'wordcamp-schedule-track-3' } }>Balcony</span>
			<span className="wordcamp-schedule-column-header" aria-hidden="true" style={ { gridColumn: 'wordcamp-schedule-track-4' } }>Trampoline</span>

			{/* need js to toggle aria-hidden when css changes display:none? is aria-hidden needed here? */}
		</Fragment>
	);
}

function Sessions() {
	return (
		<Fragment>
			<h3 className="wordcamp-schedule-time-slot-header" style={ { gridRow: 'time-0800 / time-0830' } }>8:00am</h3>
			{/* calculating end-time for ^ might be a bit tricky? or maybe fairly simple? needed to visual gaps between the h3s */}

			{/* should wrap these in sections? ask mark what he thinks */}

			<Session />

			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular wordcamp-schedule-session-2 wordcamp-schedule-track-2" style={ { gridColumn: 'wordcamp-schedule-track-2-start', gridRow: 'time-0800 / time-0900' } }>
				<h4 className="wordcamp-schedule-session-title"><a href="">Modern Leadership is About Empowerment, not Control</a></h4>
				<span className="wordcamp-schedule-session-track">Track: 2</span>
				<span className="wordcamp-schedule-session-speaker">Jane Doe</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>

			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular wordcamp-schedule-session-3 wordcamp-schedule-track-3" style={ { gridColumn: 'wordcamp-schedule-track-3-start', gridRow: 'time-0800 / time-0830' } }>
				<h4 className="wordcamp-schedule-session-title"><a href="">Modern Leadership is About Empowerment, not Control</a></h4>
				<span className="wordcamp-schedule-session-track">Track: 3</span>
				<span className="wordcamp-schedule-session-speaker">Jane Doe</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>

			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular wordcamp-schedule-session-4 wordcamp-schedule-track-4" style={ { gridColumn: 'wordcamp-schedule-track-4-start', gridRow: 'time-0800 / time-1000' } }>
				<h4 className="wordcamp-schedule-session-title"><a href="">Modern Leadership is About Empowerment, not Control</a></h4>
				<span className="wordcamp-schedule-session-track">Track: 4</span>
				<span className="wordcamp-schedule-session-speaker">Jane Doe</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>

			<h3 className="wordcamp-schedule-time-slot-header" style={ { gridRow: 'time-0830' } }>8:30am</h3>

			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular wordcamp-schedule-session-5 wordcamp-schedule-track-3" style={ { gridColumn: 'wordcamp-schedule-track-3-start', gridRow: 'time-0830 / time-1000' } }>
				<h4 className="wordcamp-schedule-session-title"><a href="">Modern Leadership is About Empowerment, not Control</a></h4>
				<span className="wordcamp-schedule-session-track">Track: 1</span>
				<span className="wordcamp-schedule-session-speaker">Jane Doe</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>

			<h3 className="wordcamp-schedule-time-slot-header" style={ { gridRow: 'time-0900 / time-1000' } }>9:00am</h3>

			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular wordcamp-schedule-session-6 wordcamp-schedule-track-1" style={ { gridColumn: 'wordcamp-schedule-track-1-start / wordcamp-schedule-track-2-end', gridRow: 'time-0900 / time-1000' } }>
				<h4 className="wordcamp-schedule-session-title"><a href="">Modern Leadership is About Empowerment, not Control</a></h4>
				<span className="wordcamp-schedule-session-track">Track: 1</span>
				<span className="wordcamp-schedule-session-speaker">Jane Doe</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>

			<h3 className="wordcamp-schedule-time-slot-header" style={ { gridRow: 'time-1000' } }>10:00am</h3>

			<div className="wordcamp-schedule-session wordcamp-schedule-session-7 wordcamp-schedule-session-type-custom" style={ { gridColumn: 'wordcamp-schedule-track-1-start / wordcamp-schedule-track-4-end', gridRow: 'time-1000 / time-1030' } }>
				<h4 className="wordcamp-schedule-session-title">Take a break!</h4>
			</div>

			<h3 className="wordcamp-schedule-time-slot-header" style={ { gridRow: 'time-1030' } }>10:30am</h3>

			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular wordcamp-schedule-session-8 wordcamp-schedule-track-1" style={ { gridColumn: 'wordcamp-schedule-track-1-start', gridRow: 'time-1030 / time-1130' } }>
				<h4 className="wordcamp-schedule-session-title"><a href="">Modern Leadership is About Empowerment, not Control</a></h4>
				<span className="wordcamp-schedule-session-track">Track: 1</span>
				<span className="wordcamp-schedule-session-speaker">Jane Doe</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>

			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular wordcamp-schedule-session-9 wordcamp-schedule-track-2" style={ { gridColumn: 'wordcamp-schedule-track-2-start', gridRow: 'time-1030 / time-1230' } }>
				<h4 className="wordcamp-schedule-session-title"><a href="">Modern Leadership is About Empowerment, not Control</a></h4>
				<span className="wordcamp-schedule-session-track">Track: 2</span>
				<span className="wordcamp-schedule-session-speaker">Jane Doe</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>

			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular  wordcamp-schedule-session-10 wordcamp-schedule-track-4" style={ { gridColumn: 'wordcamp-schedule-track-4-start', gridRow: 'time-1030 / time-1100' } }>
				<h4 className="wordcamp-schedule-session-title"><a href="">Modern Leadership is About Empowerment, not Control</a></h4>
				<span className="wordcamp-schedule-session-track">Track: 4</span>
				<span className="wordcamp-schedule-session-speaker">Jane Doe</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>

			<h3 className="wordcamp-schedule-time-slot-header" style={ { gridRow: 'time-1100' } }>11:00am</h3>

			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular wordcamp-schedule-session-11 wordcamp-schedule-track-3" style={ { gridColumn: 'wordcamp-schedule-track-3-start', gridRow: 'time-1100 / time-1130' } }>
				<h4 className="wordcamp-schedule-session-title"><a href="">Modern Leadership is About Empowerment, not Control</a></h4>
				<span className="wordcamp-schedule-session-track">Track: 3</span>
				<span className="wordcamp-schedule-session-speaker">Jane Doe</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>

			<h3 className="wordcamp-schedule-time-slot-header" style={ { gridRow: 'time-1130 / time-1230' } }>11:30am</h3>

			<div className="wordcamp-schedule-session wordcamp-schedule-track-1 wordcamp-schedule-session-type-custom" style={ { gridColumn: 'wordcamp-schedule-track-1-start', gridRow: 'time-1130 / time-1230' } }>
				<h4 className="wordcamp-schedule-session-title">Lunch!</h4>
			</div>

			<div className="wordcamp-schedule-session wordcamp-schedule-track-3 wordcamp-schedule-track-4 wordcamp-schedule-session-type-custom" style={ { gridColumn: 'wordcamp-schedule-track-3-start / wordcamp-schedule-track-4-end', gridRow: 'time-1130 / time-1230' } }>
				<h4 className="wordcamp-schedule-session-title">Lunch!</h4>
			</div>

			{/* todo on mobile view, the two lunch tracks show up together, even though we really only want 1. not sure if there's a good way around that. mark might have some ideas. */}
		</Fragment>
	);
}


function Session( session ) {
	const { id, slug, track } = session;
	const sessionUrl = 'sadf'; // todo pull from these session above when switch to real data. title/speaker too
	const speakerUrl = 'sadf';

	/*
	 * These are only opened in new tabs because this is in the editor. They should _not_ do that on the front-end,
	 * because that's an anti-pattern that hurts UX by taking control away from the user.
	 */
	const renderedTitle   = sessionUrl ? <a href="" target="_blank">Modern Leadership is About Empowerment, not Control</a>  : 'Modern Leadership is About Empowerment, not Control';
	const renderedSpeaker = speakerUrl ? <a href="" target="_blank">Jane Doe</a> : 'Jane Doe';

	const classes = classnames(
		'wordcamp-schedule-session',
		`wordcamp-schedule-session-id-${id}`,
		`wordcamp-schedule-session-slug-${slug}`,   // maybe data-attr instad?
		`wordcamp-schedule-session-track-${track}`, // do this for each track. maybe data- attr instead
		`wordcamp-schedule-session-type-regular` // or "custom" for lunches, breaks, etc

		// change all class names everywhere in this file, but wait until make dynamic, since it'll take a lot less time then
	);

	// if lunch etc then
	//      apply wordcamp-schedule-session-type-custom class instead of wordcamp-schedule-track-{id}. maybe apply all of the `track-{id}` classes? probably.
	//          only apply if crosses all of them? no, probably even if just crosses 2 or more
	//      no author, category, etc. those will maybe happen automatically b/c only showning if they're assigned? might want category in some cases anyway

	return (
		<Fragment>
			<div className="wordcamp-schedule-session wordcamp-schedule-session-type-regular wordcamp-schedule-session-1 wordcamp-schedule-track-1" style={ { gridColumn: 'wordcamp-schedule-track-1', gridRow: 'time-0800 / time-0900' } }>
				<h4 className="wordcamp-schedule-session-title">{ renderedTitle }</h4>

				<span className="wordcamp-schedule-session-track">Track: 1</span>
					{/* class name is gonna conflict with the wordcamp-schedule-track-{id} ? or good enough to have the extra "session" in here? or should add it to other? no, but confusing both ways */}
				<span className="wordcamp-schedule-session-speaker">{ renderedSpeaker }</span>
				<span className="wordcamp-schedule-session-category">Business & Freelancing</span>
			</div>
		</Fragment>
	)
}

class ScheduleBlockContent extends Component {
	render() {
		const { attributes, organizerPosts }                                     = this.props;
		const { show_avatars, avatar_size, avatar_align, content, excerpt_more } = attributes;

		// if no sessions return early? or taken care of by chosendays.map(...) ?

		return (
			<div className="wordcamp-schedule"> {/* todo better class name. don't need this if only have 1 day. maybe don't even need it then?*/}
				<Fragment>
					<ScheduleDay
						date={ 'Saturday' }
						sessions={ [] }
					/>

					{/*<ScheduleDay*/}
						{/*date={ 'Sunday' }*/}
						{/*sessions={ [] }*/}
					{/*/>*/}
				</Fragment>
			</div>
		);
	}
}

export default ScheduleBlockContent;
