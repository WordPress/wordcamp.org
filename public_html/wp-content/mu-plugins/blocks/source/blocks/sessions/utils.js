/**
 * External dependencies
 */
import { get } from 'lodash';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { arrayTokenReplace, tokenSplit } from '../../i18n';

/**
 * Fetch the details for a session as a human-readable string, in array-parts from `arrayTokenReplace`. This can be
 * passed to any react component and renders as text in the element on the browser.
 *
 * @param {Object} session
 * @return {Array}
 */
export function getSessionDetails( session ) {
	const terms = get( session, '_embedded[\'wp:term\']', [] ).flat();

	if ( session.session_track.length ) {
		const [ firstTrack ] = terms.filter( ( term ) => 'wcb_track' === term.taxonomy );

		return arrayTokenReplace(
			/* translators: 1: A date; 2: A time; 3: A location; */
			tokenSplit( __( '%1$s at %2$s in %3$s', 'wordcamporg' ) ),
			[
				session.session_date_time.date,
				session.session_date_time.time,
				firstTrack.name.trim(),
			]
		);
	}

	return arrayTokenReplace(
		/* translators: 1: A date; 2: A time; */
		tokenSplit( __( '%1$s at %2$s', 'wordcamporg' ) ),
		[
			session.session_date_time.date,
			session.session_date_time.time,
		]
	);
}
