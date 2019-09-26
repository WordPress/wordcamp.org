/* eslint-disable require-jsdoc */
/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Session from './session';

function renderSession( sessions, key ) {
	return sessions.map( ( trackPair, index ) => {
		const session = trackPair[ key ];
		const track = trackPair.track;
		const sessionKey = `${ session ? session.id : index }-${ track.id }`;

		return (
			<Session
				key={ sessionKey }
				session={ session }
				track={ track }
			/>
		);
	} );
}

export default function( { isFetching, sessions } ) {
	// A session track is "running" if there is a talk either now or next.
	const runningSessions = sessions.filter( ( session ) => ( !! session.now || !! session.next ) );

	if ( ! isFetching && ! runningSessions.length ) {
		return <p>{ __( 'No WordCamp events are scheduled today :(', 'wordcamporg' ) }</p>;
	}

	if ( isFetching ) {
		return <span className="components-spinner" />;
	}

	return (
		<Fragment>
			<h2 className="wordcamp-live-schedule__title">{ _x( 'On Now', 'title', 'wordcamporg' ) }</h2>

			{ renderSession( runningSessions, 'now' ) }

			<h2 className="wordcamp-live-schedule__title">{ _x( 'Up Next', 'title', 'wordcamporg' ) }</h2>

			{ renderSession( runningSessions, 'next' ) }

		</Fragment>
	);
}
