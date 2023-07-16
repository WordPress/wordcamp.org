/**
 * External dependencies
 */
import { get } from 'lodash';

/**
 * WordPress dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
const { stripTags } = wp.sanitize;

export default function( { headingLevel = 3, session, track } ) {
	if ( ! session ) {
		return null;
	}
	const Heading = `h${ headingLevel }`;

	// Pull details out of the session object.
	const title = get( session, 'title.rendered', '' );
	const link = get( session, 'link', '' );
	const categories = get( session, 'session_cats_rendered', '' );
	const type = get( session, 'meta._wcpt_session_type', '' );
	const time = parseInt( session.meta._wcpt_session_time ) * 1000;
	const date = new Date( time ).toLocaleTimeString( [], { timeZoneName: 'short', hour: 'numeric', minute: '2-digit' } );

	const speakers = get( session, 'session_speakers', [] );
	const validSpeakers = speakers.filter( ( speaker ) => !! speaker.id );

	const cleanTitle = decodeEntities( stripTags( title ) );

	return (
		<div className={ `wordcamp-live-schedule__session type-${ type }` }>
			{ !! track.slug && (
				<span className={ `wordcamp-live-schedule__session-track track-${ track.slug }` }>
					{ track.name }
				</span>
			) }

			<div className="wordcamp-live-schedule__session-details">
				<Heading className="wordcamp-live-schedule__session-title">
					{ !! link ? <a href={ link }>{ cleanTitle }</a> : cleanTitle }
				</Heading>

				<span className="wordcamp-live-schedule__session-time">{ date }</span>

				<span className="wordcamp-live-schedule__session-speaker">
					{ !! validSpeakers.length &&
						validSpeakers.map( ( { id, name, link: speakerLink } ) => (
							<a key={ id } href={ speakerLink }>
								{ decodeEntities( stripTags( name ) ) }
							</a>
						) ) }
				</span>

				<span className="wordcamp-live-schedule__session-cats">{ categories }</span>
			</div>
		</div>
	);
}
