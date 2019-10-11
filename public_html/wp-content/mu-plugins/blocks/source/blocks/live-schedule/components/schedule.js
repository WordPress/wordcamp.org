/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Session from './session';

export default function( { attributes, isFetching, sessions } ) {
	// A session track is "running" if there is a talk either now or next.
	const runningSessions = sessions.filter( ( session ) => ( !! session.now || !! session.next ) );

	if ( ! isFetching && ! runningSessions.length ) {
		return <p>{ __( 'No WordCamp events are scheduled today :(', 'wordcamporg' ) }</p>;
	}

	if ( isFetching ) {
		return <span className="components-spinner" />;
	}

	const { level = 2 } = attributes;
	const Heading = `h${ level }`;

	return (
		<Fragment>
			<Heading className="wordcamp-live-schedule__title">{ attributes.now }</Heading>

			{ sessions.map( ( trackPair, index ) => {
				const session = trackPair.now;
				const track = trackPair.track;
				const sessionKey = `${ session ? session.id : index }-${ track.id }`;

				return (
					<Session
						key={ sessionKey }
						headingLevel={ parseInt( level ) + 1 }
						session={ session }
						track={ track }
					/>
				);
			} ) }

			<Heading className="wordcamp-live-schedule__title">{ attributes.next }</Heading>

			{ sessions.map( ( trackPair, index ) => {
				const session = trackPair.next;
				const track = trackPair.track;
				const sessionKey = `${ session ? session.id : index }-${ track.id }`;

				return (
					<Session
						key={ sessionKey }
						headingLevel={ parseInt( level ) + 1 }
						session={ session }
						track={ track }
					/>
				);
			} ) }

		</Fragment>
	);
}
