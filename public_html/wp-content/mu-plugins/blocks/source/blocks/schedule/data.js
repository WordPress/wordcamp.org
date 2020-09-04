/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { dateI18n } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { WC_BLOCKS_STORE } from '../../data';


/*
 * Create an implicit "0" track when none formally exist.
 *
 * Camps with a single track may neglect to create a formal one, but the Grid still has to
 * have a track to lay sessions onto.
 */
export const implicitTrack = { id: 0 };

// Used when indexing array items by date, etc.
export const DATE_SLUG_FORMAT = 'Y-m-d';

/**
 * Prepares the data for a Schedule Block.
 *
 * @param {Array} attributes
 *
 * @return {Object}
 */
export function useScheduleData( attributes ) {
	const editorContext = attributes.__isStylesPreview ? 'example' : 'live';

	const scheduleData = useSelect(
		( select ) => fetchScheduleData( select, editorContext ),
		[ attributes.__isStylesPreview ]
	);

	scheduleData.loading = false;

	for ( const datum of Object.values( scheduleData ) ) {
		if ( datum === null ) {
			scheduleData.loading = true;
			break;
		}
	}

	// Prepare the data so it's more convenient to work with.
	if ( ! scheduleData.loading ) {
		const { allCategories, allSessions, allTracks } = scheduleData;
		const derivedSessions = getDerivedSessions( allSessions, allCategories, allTracks, attributes );

		scheduleData.allSessions = derivedSessions.allSessions;
		scheduleData.chosenSessions = derivedSessions.chosenSessions;
	}

	return scheduleData;
}

/**
 * Query for all the data that's needed to build the block.
 *
 * Tracks and categories are being queried for separately here, instead of being embedded into session objects.
 * The primary reason for that is because all tracks need to be displayed in `ScheduleInspectorControls()`, not
 * just the ones that have been assigned to sessions.
 *
 * Another reason to not `_embed` is that Core doesn't support restricting `_embedded` fields yet (see
 * https://core.trac.wordpress.org/ticket/49538), so the response would be slower to generate and download. 5.4
 * will let filter top-level fields -- e.g., `_embed=author`, see
 * https://make.wordpress.org/core/2020/02/29/rest-api-changes-in-5-4/ -- but that only partially solves the
 * problem.
 *
 * If embedded fields could be filtered, then we could embed categories, since we only need the ones that are
 * assigned to the displayed sessions. It's probably simpler to keep their usage consistent with tracks, though.
 *
 * All sessions are being queried for, regardless of `attributes.chosenDays/Tracks`. That takes a little bit
 * longer up front, but allows for instant re-renders when changing attributes, which is a much better UX than
 * having to wait for slow HTTP requests to return new data.
 *
 * This is only used in the editor; the front end has the data bundled in the initial HTTP response for
 * performance/UX. See `pass_global_data_to_front_end()`.
 *
 * @param {Function} select
 * @param {boolean}  editorContext 'example' for the Block Styles preview in the inspector controls; 'live' for
 *                                 the actual block in the post editor content area.
 *
 * @return {Object}
 */
const fetchScheduleData = ( select, editorContext ) => {
	const { getEntities, getSiteSettings } = select( WC_BLOCKS_STORE );

	if ( 'example' === editorContext ) {
		return getExampleData();
	}

	// These must be kept in sync with `get_all_sessions()`.
	const sessionArgs = {
		/*
		 * This doesn't include `session_cats_rendered` because we already need the category id/slug in other
		 * places, so it's simpler to have single source of truth.
		 */
		_fields: [
			'id',
			'link',
			'meta._wcpt_session_time',
			'meta._wcpt_session_duration',
			'meta._wcpt_session_type',
			'session_category',
			'session_speakers',
			'session_track',
			'slug',
			'title',
		],
	};

	// These must be kept in sync with `get_all_tracks()`.
	const trackArgs = {
		_fields: [ 'id', 'name', 'slug' ],

		/*
		 * It's important that the order here match `getDisplayedTracks()`. The tracks must be sorted in a
		 * predictable order, so that track spanning can be reliably detected; see
		 * `sessionSpansNonContiguousTracks()`. They must be sorted consistently throughout the application. For
		 * organizers, alphabetical is the most obvious/intuitive way to do that. The slug is used, rather than
		 * name, though, so that they can change the sorting order without having to rename their tracks. Renaming
		 * slugs isn't ideal, because it could break archive pages, but it's the least-bad choice, especially for
		 * v1. We can build a more robust solution in the future, if there's a need.
		 */
		orderby: 'slug',
	};

	// These must be kept in sync with `get_all_categories()`.
	const categoryArgs = {
		_fields: [ 'id', 'name', 'slug' ],
	};

	const allSessions = getEntities( 'postType', 'wcb_session', sessionArgs );
	const allTracks = getEntities( 'taxonomy', 'wcb_track', trackArgs );
	const allCategories = getEntities( 'taxonomy', 'wcb_session_category', categoryArgs );
	const settings = getSiteSettings();

	return { allSessions, allTracks, allCategories, settings };
};

/**
 * Get mock data for the preview in the Style inspector control.
 *
 * @todo Preview only shows some of the sessions at https://2017.testing.wordcamp.org/wp-admin/post.php?post=13245&action=edit.
 *
 * return {Object}
 */
function getExampleData() {
	const hourInSeconds = 60 * 60;
	const yearInSeconds = 60 * 60 * 24 * 365;

	/*
	 * Start at 0:00, so that adding a few hours never pushes the date over to tomorrow. The preview would be
	 * distorted if that happened.
	 */
	const todayZeroHour = new Date( new Date().toDateString() );

	/*
	 * `_wcpt_session_time` must not be the same day as any real sessions, or the grid for the actual block will
	 * collapse when the preview is shown.
	 */
	const todayNextYear = ( todayZeroHour.valueOf() / 1000 ) + yearInSeconds;

	return {
		allSessions: [
			{
				id: 1,
				slug: 'post-1',
				link: 'https://2020.seattle.wordcamp.test/session/post-1/',

				title: {
					rendered: 'Session 1',
				},

				meta: {
					_wcpt_session_time: todayNextYear + ( 2 * hourInSeconds ),
					_wcpt_session_duration: 1800,
					_wcpt_session_type: 'custom',
				},

				session_track: [
					38,
					748,
					46,
				],

				session_category: [],
				session_speakers: [],
			},

			{
				id: 2,
				slug: 'post-2',
				link: 'https://2020.seattle.wordcamp.test/session/session-2',

				title: {
					rendered: 'Session 2',
				},

				meta: {
					_wcpt_session_time: todayNextYear + hourInSeconds,
					_wcpt_session_duration: 3600,
					_wcpt_session_type: 'session',
				},

				session_track: [
					38,
					748,
				],

				session_category: [],
				session_speakers: [],
			},

			{
				id: 3,
				slug: 'post-3',
				link: 'https://2020.seattle.wordcamp.test/session/post-3/',

				title: {
					rendered: 'Session 3',
				},

				meta: {
					_wcpt_session_time: todayNextYear + ( hourInSeconds / 2 ),
					_wcpt_session_duration: 5400,
					_wcpt_session_type: 'session',
				},

				session_track: [
					46,
				],

				session_category: [],
				session_speakers: [],
			},

			{
				id: 4,
				slug: 'post-4',
				link: 'https://2020.seattle.wordcamp.test/session/post-4/',

				title: {
					rendered: 'Session 4',
				},

				meta: {
					_wcpt_session_time: todayNextYear,
					_wcpt_session_duration: 1800,
					_wcpt_session_type: 'session',
				},

				session_track: [
					46,
				],

				session_category: [],
				session_speakers: [],
			},

			{
				id: 5,
				slug: 'post-5',
				link: 'https://2020.seattle.wordcamp.test/session/post-5/',

				title: {
					rendered: 'Session 5',
				},

				meta: {
					_wcpt_session_time: todayNextYear,
					_wcpt_session_duration: 3600,
					_wcpt_session_type: 'session',
				},

				session_track: [
					748,
				],

				session_category: [],
				session_speakers: [],
			},

			{
				id: 6,
				slug: 'post-6',
				link: 'https://2020.seattle.wordcamp.test/session/post-6/',

				title: {
					rendered: 'Session 6',
				},

				meta: {
					_wcpt_session_time: todayNextYear,
					_wcpt_session_duration: 3600,
					_wcpt_session_type: 'session',
				},

				session_track: [
					38,
				],

				session_category: [],
				session_speakers: [],
			},
		],

		allTracks: [
			{
				id: 38,
				name: 'Bar',
				slug: 'bar',
			},

			{
				id: 748,
				name: 'Bax',
				slug: 'bax',
			},

			{
				id: 46,
				name: 'Foo',
				slug: 'foo',
			},

			{
				id: 747,
				name: 'Quix',
				slug: 'quix',
			},
		],

		allCategories: [],

		settings: {
			timezone: 'America/Vancouver',
			date_format: 'F j, Y',
			time_format: 'g:i a',
			language: '',
		},
	};
}

/**
 * Generate extra data on each session post.
 *
 * @param {Array}  allSessions
 * @param {Array}  allCategories
 * @param {Array}  allTracks
 * @param {Object} attributes
 *
 * @return {Object}
 */
export function getDerivedSessions( allSessions, allCategories, allTracks, attributes ) {
	const { chooseSpecificDays, chooseSpecificTracks, chosenDays, chosenTrackIds } = attributes;

	allSessions = deriveSessionStartEndTimes( allSessions );
	allSessions = deriveSessionTerms( allSessions, allCategories, allTracks );

	let chosenSessions = Array.from( allSessions );

	if ( chooseSpecificDays ) {
		chosenSessions = filterSessionsByChosenDays( chosenSessions, chosenDays );
	}

	if ( chooseSpecificTracks ) {
		chosenSessions = filterSessionsByChosenTracks( chosenSessions, chosenTrackIds );
	}

	return { allSessions, chosenSessions };
}

/**
 * Replace raw session timestamp with local timezone start/end times.
 *
 * @param {Array} sessions
 *
 * @return {Array}
 */
function deriveSessionStartEndTimes( sessions ) {
	return sessions.map( ( session ) => {
		const durationInMs = parseInt( session.meta._wcpt_session_duration ) * 1000; // Convert to milliseconds.

		session.derived = session.derived || {};
		session.derived.startTime = parseInt( session.meta._wcpt_session_time ) * 1000;
		session.derived.endTime = session.derived.startTime + durationInMs;

		return session;
	} );
}

/**
 * Populate full track objects for each session's assigned tracks and categories.
 *
 * This is necessary since they're not _embedded -- see notes in `scheduleSelect()` -- and makes the session
 * objects more convenient to work with, since it always has all the data that will be needed.
 *
 * @param {Array} allSessions
 * @param {Array} allCategories
 * @param {Array} allTracks
 *
 * @return {Array}
 */
function deriveSessionTerms( allSessions, allCategories, allTracks ) {
	return allSessions.map( ( session ) => {
		/*
		 * This is the only place that should reference session.session_track. Everything else should use
		 * derived.assignedTracks as the canonical data, to make sure that any modifications to the raw
		 * data are always used consistently.
		 *
		 * todo delete session.session_track to enforce ^ ?
		 *      if do, will that mess up session objects for other blocks, or future pieces of core/plugins that
		 *      might reference it?
		 */
		session.derived.assignedTracks = allTracks.filter( ( track ) =>
			session.session_track.includes( track.id )
		);

		/*
		 * This sort determines the display order of the tracks. That order is also what's used to determine
		 * whether or not two sessions are overlapping within a track. This needs to match `getdisplayedTracks()`,
		 * so that sessions line up properly on the grid. Organizers can modify the sort order by renaming slugs;
		 * that's far from ideal, but the least-bad choice for v1. See also `sessionSpansNonContiguousTracks()`,
		 */
		session.derived.assignedTracks.sort( sortBySlug );

		/*
		 * There must always be a track to lay sessions on to, so add the implicit one if there aren't any real
		 * ones assigned.
		 */
		if ( 0 === session.derived.assignedTracks.length ) {
			session.derived.assignedTracks[ 0 ] = implicitTrack;
		}

		session.derived.assignedCategories = allCategories.filter( ( category ) =>
			session.session_category.includes( category.id )
		);

		return session;
	} );
}

/**
 * Determine the sorting order of the given tracks.
 *
 * @param {Object} first
 * @param {Object} second
 *
 * @return {number}
 */
export function sortBySlug( first, second ) {
	if ( first.slug === second.slug ) {
		return 0;
	}

	return first.slug > second.slug ? 1 : -1;
}

/**
 * Remove sessions that aren't on the chosen days.
 *
 * @param {Array} sessions
 * @param {Array} chosenDays
 *
 * @return {Array}
 */
function filterSessionsByChosenDays( sessions, chosenDays ) {
	// Choosing 0 days is treated the same as choosing all days, because otherwise there'd be nothing to display.
	if ( chosenDays.length === 0 ) {
		return sessions;
	}

	return sessions.filter( ( session ) => {
		const date = dateI18n( DATE_SLUG_FORMAT, session.derived.startTime );
		return chosenDays.includes( date );
	} );

	/*
	 todo kinda bad UX b/c don't really see the changes happening, below/above fold.
	 and/or it happens so quick that kind of jarring. maybe add some jumpToBlah() and/or smoothed animation
	 iirc G has some animation stuff built in for moving blocks around, might be reusable
	 */
}

/**
 * Remove sessions that aren't assigned to the tracks chosen in `ScheduleInspectorControls()`.
 *
 * @param {Array} sessions
 * @param {Array} chosenTrackIds
 *
 * @return {Array}
 */
function filterSessionsByChosenTracks( sessions, chosenTrackIds ) {
	// Choosing 0 days is treated the same as choosing all days, because otherwise there'd be nothing to display.
	if ( chosenTrackIds.length === 0 ) {
		return sessions;
	}

	return sessions.filter( ( session ) => {
		const assignedTrackIds = session.derived.assignedTracks.map(
			( track ) => track.id
		);

		const intersection = chosenTrackIds.filter(
			( trackID ) => assignedTrackIds.includes( trackID )
		);

		return intersection.length > 0;
	} );
}
