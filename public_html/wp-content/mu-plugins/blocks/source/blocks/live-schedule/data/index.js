/**
 * External dependencies
 */
import { flatten, get, keyBy, sortBy } from 'lodash';

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
		_embed: true,
	} );
	const trackPath = addQueryArgs( `wp/v2/session_track`, {
		per_page: -1,
		status: 'publish',
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
function getSessions( { sessions, tracks } ) {
	const tzOffset = __experimentalGetSettings().timezone.offset * ( 60 * 60 * 1000 );
	const nowUTC = window.WordCampBlocks[ 'live-schedule' ].nowOverride || Date.now();
	const nowLocal = new Date( nowUTC );

	return tracks.map( ( track ) => {
		const sessionsInTrack = sortBy(
			sessions.filter( ( session ) => session.session_track.includes( track.id ) ),
			( sessionInTrack ) => sessionInTrack.meta._wcpt_session_time
		);

		const indexOfNextSession = sessionsInTrack.findIndex( ( session ) => {
			const sessionTimeUTC = ( session.meta._wcpt_session_time * 1000 ) - tzOffset;
			const sessionTimeLocal = new Date( sessionTimeUTC );

			// Return first session today where "now" is before the the start time.
			return nowUTC < sessionTimeUTC && sessionTimeLocal.getDate() === nowLocal.getDate();
		} );

		return {
			track: track,
			next: sessionsInTrack[ indexOfNextSession ],
			now: sessionsInTrack[ indexOfNextSession - 1 ],
		};
	} );
}

/**
 * Async function to get session and tracks data from the REST API, formatted into currently running sessions
 * per track.
 *
 * @return {Promise}
 */
export async function getDataFromAPI() {
	const data = await fetchFromAPI();
	const sessions = data[ 0 ].map( ( session ) => {
		const terms = flatten( get( session, '_embedded[wp:term]', [] ) );
		return {
			...session,
			terms: keyBy( terms, 'id' ),
		};
	} );

	const tracks = data[ 1 ];

	return getSessions( { sessions, tracks } );
}
