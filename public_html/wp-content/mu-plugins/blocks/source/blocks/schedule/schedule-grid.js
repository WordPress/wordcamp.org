/**
 * WordPress dependencies
 */
import { gmdate } from '@wordpress/date';
import { Fragment } from '@wordpress/element';
import { _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NoContent } from '../../components/';
import { Sessions } from './sessions';

/* todo
 *
 * rebased this against prod, so make sure that this doesn't introduce any artifcats
 *  (git diff master and check each line)
 * diff against other blocks for consistency
 * php/js lint everything
 * kelly's feedback
 *
 *
 * test under ie11 - should just see mobile/fallback schedule
 * test other camps, like seattle 2017, berlin 2017, 2018.montreal, wcus, new york, europe, boston, kansas city,
 * miama, tokyo, etc across various years
 *      test 2019.europe b/c matt span some but not all tracks - should be fine b/c alphabetical
 * 	    what will this look lke with 15 tracks ala 2012.nyc? or a slightly-less-unreasonable 8 tracks ala
 * 	    2009.newyork ? probably don't need to handle those edge cases
 *
 * commit msg - length fixes 3623, others see/fixes 3117, #3842
 * props mark, mel, others
 *
 * before deploy, assign `flat_session_tracks` flag to existing sites
 */

/**
 * Render the schedule for a specific day.
 *
 * @param {string} date In a format acceptable by `wp.date.gmdate()`.
 * @param {Array} sessions
 * @param {Array} tracks
 * @param {string} dateFormat
 * @param {string} timeFormat
 *
 * @return {Element}
 */
function ScheduleDay( { date, sessions, tracks, dateFormat, timeFormat } ) {
	return (
		<Fragment>
			<h2 className="wordcamp-schedule__date">
				{ gmdate( dateFormat, date ) }
			</h2>
			{ /* todo this needs to be editable, should also be a separate Heading block. so when inserting a schedule
			 block, We can make the text editable, though, with a reasonable default. If they remove the text,
			then we can automatically remove the corresponding h2 tag, to avoid leaving an artifact behind that
			 affects margins/etc. */ }

			<section id={ `wordcamp-schedule__day-${ gmdate( 'Y-m-d', date ) }` } className="wordcamp-schedule__day">
				<GridColumnHeaders tracks={ tracks } />

				<Sessions
					allTracks={ tracks }
					sessions={ sessions }
					timeFormat={ timeFormat }
				/>
			</section>
		</Fragment>
	);
}

/**
 * Render the column headers for a schedule.
 *
 * @param {Array} tracks
 *
 * @return {Element}
 */
function GridColumnHeaders( { tracks } ) {
	/*
	 * If we're using an implicit track, then there won't be any track names printed, so there's not much point
	 * in printing the "Time" header either. It's obvious enough, and it'd looks odd on its own without the track
	 * names.
	 */
	if ( ! tracks.length ) {
		return null;
	}

	return (
		<Fragment>
			{ /*
			  * Columns headings are hidden from screen readers because the time/track info is already displayed
			  * in each session, and screen readers couldn't make sense of these headers; they're only an extra
			  * visual aid for sighted users.
			  */ }
			<span className="wordcamp-schedule__column-header" aria-hidden="true" style={ { gridColumn: 'times' } }>
				{ _x( 'Time', 'table column heading', 'wordcamporg' ) }
			</span>

			{ tracks.map( ( track ) => (
				<span
					key={ track.id }
					className="wordcamp-schedule__column-header"
					aria-hidden="true" // See note above about aria-hidden.
					style={ { gridColumn: `wordcamp-schedule-track-${ track.id }` } }
				>
					{ track.name }
				</span>
			) ) }
		</Fragment>
	);
}

/**
 * Replace raw session timestamp with local timezone start/end times.
 *
 * @param {Array} sessions
 *
 * @return {Array}
 */
function deriveSessionStartEndTimes( sessions ) {
	/*
	 * This can't run twice because it deletes the data that would be needed for the subsequent runs.
	 *
	 * Only the first array item needs to be checked, because only sessions that have start times will appear
	 * on the schedule anyway.
	 */
	if ( sessions[ 0 ].derived && sessions[ 0 ].derived.startTime ) {
		return sessions;
	}

	return sessions.map( session => {
		session.derived = session.derived || {};
		session.derived.startTime = parseInt( session.meta._wcpt_session_time ) * 1000; // Convert to milliseconds.

		const hoursInMs = parseInt( session.meta._wcpt_session_length_hours ) * 60 * 60 * 1000;
		const minutesInMs = parseInt( session.meta._wcpt_session_length_minutes ) * 60 * 1000;

		session.derived.endTime = session.derived.startTime + hoursInMs + minutesInMs;

		/*
		 * The raw session time provided by the API should not be used because it does not account for the
		 * timezone. Instead, the new derived `startTime` and `endTime` should be used.
		 */
		delete session.meta._wcpt_session_time;

		return session;
	} );
}

/**
 * Derive the tracks so that they're easily accessible.
 *
 * The relevant terms are buried in the REST API response and not easily accessible, so this is just a convenience
 * function so that they're easy to use.
 *
 * @param {Array} sessions
 *
 * @return {Array}
 */
function deriveSessionTerms( sessions ) {
	return sessions.map( session => {
		/*
		 * This closure can't run twice because the first run deletes the data that would be needed for the
		 * subsequent runs.
		 *
		 * Some sessions won't have any tracks, though, so each session needs to be checked individually.
		 */
		if ( session.derived && session.derived.tracks ) {
			return session;
		}

		session._embedded = session._embedded || [];
		const taxonomies = session._embedded[ 'wp:term' ] || [];
		const tracks = taxonomies.filter( terms => terms.length && 'wcb_track' === terms[ 0 ].taxonomy );
		const categories = taxonomies.filter( terms => terms.length && 'wcb_session_category' === terms[ 0 ].taxonomy );

		tracks.sort( sortBySlug );
		categories.sort( sortBySlug );

		session.derived.tracks = tracks.length ? tracks[ 0 ] : [];
		session.derived.categories = categories.length ? categories[ 0 ] : [];

		// Remove raw tracks so that the derived data is canonical and DRY.
		delete session._embedded[ 'wp:term' ];
		// todo deleting this breaks the sessions block, since it uses the same data i guess?
			// maybe update it to use the derived tracks too?
			// or maybe just have your own copy of the data for this block?

		return session;
	} );
}

/**
 * Get the tracks that the given Sessions are assigned to.
 *
 * In general, it's more useful for this block to know which tracks are assigned, rather than which ones exist,
 * because e.g., you don't want to print all tracks in `GridColumnHeaders`, only the ones being used.
 *
 * @param {Array} sessions
 *
 * @return {Array}
 */
function getPopulatedTracks( sessions ) {
	const tracks = sessions.reduce( ( tracks, session ) => {
		if ( ! session.derived.tracks ) {
			return tracks;
		}

		for ( const track of session.derived.tracks ) {
			tracks[ track.id ] = track;
		}

		return tracks;
	}, [] );

	/*
	 * They must be sorted in a predictable order so that track spanning can be reliably detected, and alphabetical
	 * is the simplest way.
	 */
	tracks.sort( sortBySlug );

	return tracks.filter( track => track ); // Convert to a dense array, to make it easier to work with.
}

/**
 * Determine the sorting order of the given tracks.
 *
 * @param {object} first
 * @param {object} second
 *
 * @return {number}
 */
function sortBySlug( first, second ) {
	if ( first.slug === second.slug ) {
		return 0;
	}

	return first.slug > second.slug ? 1 : -1;
}

/**
 * Render the schedule.
 *
 * @param {object} attributes
 * @param {object} entities
 *
 * @return {Element}
 */
export function ScheduleGrid( { attributes, entities } ) {
	const { sessions, settings } = entities;
	const isLoading = ! Array.isArray( sessions );
	const hasSessions = ! isLoading && sessions.length > 0;

	if ( isLoading || ! hasSessions || ! settings ) {
		return (
			<NoContent loading={ isLoading } />
		);
	}

	const derivedSessions = deriveSessionTerms( deriveSessionStartEndTimes( sessions ) );
	const { date_format, time_format } = settings;
	const scheduleDays = [];

	// todo should probably have teh API return them already grouped, b/c will need that for front-end, so might
	// as well reuse it here
		// er, well, no, because then would have to make an extra http request instead of reusing data that's
		// already fetched
		// would it be fetched already? probably not unless there's an existing sessions block on the page

	const chosenSessionsGroupedByDate = derivedSessions.reduce( ( groups, session ) => {
		if ( 0 === session.derived.startTime ) {
			// todo it'd be more efficient to change withSelect() params to that you only query for sessions that
			// have a time assigned in the first place
				// except that already have all the sessions cached from the sessions block query? but that
				// doesn't normally happen on the same page as the block right?
			return groups;
		}

		const date = gmdate( 'Ymd', session.derived.startTime );

		if ( date ) {
			groups[ date ] = groups[ date ] || [];
			groups[ date ].push( session );
		}

		return groups;
	}, {} );
	// todo doing this same thing on the PHP side, maybe should setup API endpoint for this
	// b/c want to make sure the data is consistent between 1) php styles loop, 2) php template, 3) js template

	Object.keys( chosenSessionsGroupedByDate ).forEach( date => {
		const sessionsGroup = chosenSessionsGroupedByDate[ date ];

		scheduleDays.push(
			<ScheduleDay
				key={ date }
				date={ date }
				sessions={ sessionsGroup }
				tracks={ getPopulatedTracks( sessionsGroup ) }
				dateFormat={ date_format }
				timeFormat={ time_format }
			/>
		);
	} );

	return (
		<div className="wordcamp-schedule">
			{ scheduleDays }
		</div>
	);
}
