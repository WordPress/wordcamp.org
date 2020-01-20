/**
 * External dependencies
 */
import { reverse, sortBy } from 'lodash';

/**
 * WordPress dependencies
 */
import { __experimentalGetSettings } from '@wordpress/date';
import { addQueryArgs } from '@wordpress/url';
import apiFetch from '@wordpress/api-fetch';

/**
 * Fetch data from REST API for sessions and tracks.
 *
 * @return {Promise} A promise that will resolve when both sessions and tracks are fetched.
 */
function fetchFromAPI() {
	const sessionPath = addQueryArgs( `wp/v2/sessions`, {
		per_page: -1,
		status: 'publish',
		_fields: [
			'id',
			'title',
			'link',
			'meta',
			'session_track',
			'session_date_time',
			'session_cats_rendered',
			'session_speakers',
		],
	} );
	const trackPath = addQueryArgs( `wp/v2/session_track`, {
		per_page: -1,
		status: 'publish',
		_fields: [
			'id',
			'slug',
			'name',
		],
	} );

	return Promise.all( [
		apiFetch( { path: sessionPath } ),
		apiFetch( { path: trackPath } ),
	] );
}

/**
 * Given sessions, tracks, and the current time, find out which tracks are currently running, and which are next.
 *
 * @return {Array} A list of objects, `{track, now, next}`.
 */
export function getCurrentSessions( { sessions, tracks } ) {
	const tzOffset = __experimentalGetSettings().timezone.offset * ( 60 * 60 * 1000 );
	const nowTimestamp = window.WordCampBlocks[ 'live-schedule' ].nowOverride || Date.now();

	return tracks.map( ( track ) => {
		// Reverse the sorted array so that the first found index is the one that starts closest to "now". This is
		// intended to catch sessions that don't set a duration, but are shorter than the default.
		const sessionsInTrack = reverse( sortBy(
			sessions.filter( ( session ) => session.session_track.includes( track.id ) ),
			( sessionInTrack ) => sessionInTrack.meta._wcpt_session_time
		) );

		const index = sessionsInTrack.findIndex( ( { meta } ) => {
			const duration = ( meta._wcpt_session_duration || window.WordCampBlocks[ 'live-schedule' ].fallbackDuration ) * 1000;
			const startTimestamp = ( meta._wcpt_session_time * 1000 ) - tzOffset;
			const endTimestamp = startTimestamp + duration;

			// Start time before now, end time after now.
			return ( startTimestamp < nowTimestamp ) && ( nowTimestamp < endTimestamp );
		} );

		// `index` will be -1 if nothing found.
		if ( index < 0 ) {
			return {};
		}

		return {
			track: track,
			now: sessionsInTrack[ index ],
			// since we revered the sorted array, the "next" session is actually behind the found index.
			next: sessionsInTrack[ index - 1 ],
		};
	} ).filter( ( record ) => ( !! record.now || !! record.next ) );
}

/**
 * Async function to get session and tracks data from the REST API, formatted into currently running sessions
 * per track.
 *
 * @return {Promise} The promise will resolve with a list of objects, `{track, now, next}`.
 */
export async function getDataFromAPI() {
	const [ sessions, tracks ] = await fetchFromAPI();

	return getCurrentSessions( { sessions, tracks } );
}
