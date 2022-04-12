/**
 * External dependencies
 */
import { get } from 'lodash';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Fetch the details for a session as a human-readable string.
 *
 * @param {Object}  session
 * @param {boolean} allTracks
 * @return {string}
 */
export function getSessionDetails( session, allTracks = false ) {
	const terms = get( session, "_embedded['wp:term']", [] ).flat();
	const hasDate = !! session.session_date_time?.date;
	const hasTracks = !! session.session_track.length;

	if ( ! hasDate && ! hasTracks ) {
		return '';
	}

	if ( ! hasDate && hasTracks ) {
		const tracks = terms.filter( ( term ) => 'wcb_track' === term.taxonomy );

		return sprintf(
			/* translators: %s: Session track(s) */
			__( 'In %s', 'wordcamporg' ),
			allTracks ? tracks.map( ( { name } ) => name.trim() ).join( ', ' ) : tracks[ 0 ].name.trim()
		);
	} else if ( hasTracks ) {
		const tracks = terms.filter( ( term ) => 'wcb_track' === term.taxonomy );

		return sprintf(
			/* translators: 1: A date; 2: A time; 3: Session track(s) */
			__( '%1$s at %2$s in %3$s', 'wordcamporg' ),
			session.session_date_time.date,
			session.session_date_time.time,
			allTracks ? tracks.map( ( { name } ) => name.trim() ).join( ', ' ) : tracks[ 0 ].name.trim()
		);
	}

	return sprintf(
		/* translators: 1: A date; 2: A time; */
		__( '%1$s at %2$s', 'wordcamporg' ),
		session.session_date_time.date,
		session.session_date_time.time
	);
}

/**
 * Sort callback for ordering sessions by time, ascending (past -> future).
 *
 * @param {Object} sessionA
 * @param {Object} sessionB
 * @return {number}
 */
export function sortSessionByTime( sessionA, sessionB ) {
	// If no meta values found, keep the same sort order.
	if ( ! sessionA.meta?._wcpt_session_time || ! sessionB.meta?._wcpt_session_time ) {
		return 0;
	}
	if ( sessionA.meta._wcpt_session_time < sessionB.meta._wcpt_session_time ) {
		return -1;
	}
	return 1;
}
