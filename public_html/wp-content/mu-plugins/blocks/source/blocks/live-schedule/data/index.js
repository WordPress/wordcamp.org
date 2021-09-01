/**
 * External dependencies
 */
import { findLastIndex, reverse, sortBy } from 'lodash';

/**
 * WordPress dependencies
 */
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
 * @param  root0
 * @param  root0.sessions
 * @param  root0.tracks
 * @return {Array} A list of objects, `{track, now, next}`.
 */
export function getCurrentSessions( { sessions, tracks } ) {
	const nowTimestamp = window.WordCampBlocks[ 'live-schedule' ].nowOverride || Date.now();

	const trackListWithSessions = tracks.length
		? tracks.map( ( track ) => {
			// Reverse the sorted array so that the first found index is the one that starts closest to "now".
			// This is intended to catch sessions that don't set a duration, but are shorter than the default.
			const sessionsInTrack = reverse( sortBy(
				sessions.filter( ( session ) => session.session_track.includes( track.id ) ),
				'meta._wcpt_session_time'
			) );
			return {
				track: track,
				sessions: sessionsInTrack,
			};
		} )
		// Fall back to one track with all sessions, if no tracks are found.
		: [ {
			track: {},
			sessions: reverse( sortBy( sessions, 'meta._wcpt_session_time' ) ),
		} ];

	return trackListWithSessions.map( ( { track, sessions: sessionsInTrack } ) => {
		if ( ! sessionsInTrack.length ) {
			return {};
		}

		// Check if we're more than a day out from the earliest session (so that we don't show "up next" before
		// the WordCamp starts).
		const firstSession = sessionsInTrack[ sessionsInTrack.length - 1 ];
		const firstSessionTimestamp = firstSession.meta._wcpt_session_time * 1000;
		const dayInMiliseconds = 24 * 60 * 60 * 1000;
		if ( nowTimestamp < ( firstSessionTimestamp - dayInMiliseconds ) ) {
			return {};
		}

		const index = sessionsInTrack.findIndex( ( { meta } ) => {
			const duration = ( meta._wcpt_session_duration || window.WordCampBlocks[ 'live-schedule' ].fallbackDuration ) * 1000;
			const startTimestamp = meta._wcpt_session_time * 1000;
			const endTimestamp = startTimestamp + duration;

			// Start time before now, end time after now.
			return ( startTimestamp < nowTimestamp ) && ( nowTimestamp < endTimestamp );
		} );
		let nextIndex = index - 1;

		// `index` will be -1 if nothing found.
		if ( index < 0 ) {
			// If nothing is found for "now", see if anything is coming up next by looking for the earliest thing
			// that's later than now.
			nextIndex = findLastIndex( sessionsInTrack, ( { meta } ) => {
				const startTimestamp = meta._wcpt_session_time * 1000;
				return ( startTimestamp > nowTimestamp );
			} );
		}

		return {
			track: track,
			now: sessionsInTrack[ index ],
			next: sessionsInTrack[ nextIndex ],
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
