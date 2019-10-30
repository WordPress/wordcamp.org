/**
 * External dependencies
 */
import { get } from 'lodash';

/**
 * WordPress dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { stripTags } from '@wordpress/sanitize';

/**
 * Component
 *
 * @returns {Component}
 */
export default function( { headingLevel = 3, session, track } ) {
	if ( ! session ) {
		return null;
	}
	const Heading = `h${ headingLevel }`;

	// Pull details out of the session object.
	const { link, terms = {} } = session;
	const title = get( session, 'title.rendered', '' );
	const type = get( session, 'meta._wcpt_session_type', '' );
	const categories = session.session_category || [];
	const time = get( session, 'session_date_time.time', '' );

	const speakers = get( session, '_embedded.speakers', [] );
	const validSpeakers = speakers.filter( ( speaker ) => !! speaker.id );

	const cleanTitle = decodeEntities( stripTags( title ) );

	return (
		<div className={ `wordcamp-live-schedule__session type-${ type }` }>
			<span className={ `wordcamp-live-schedule__session-track track-${ track.slug }` }>{ track.name }</span>

			<div className="wordcamp-live-schedule__session-details">
				<Heading className="wordcamp-live-schedule__session-title">
					{ !! link ? (
						<a href={ link }>{ cleanTitle }</a>
					) : (
						cleanTitle
					) }
				</Heading>

				<span className="wordcamp-live-schedule__session-time">{ time }</span>

				<span className="wordcamp-live-schedule__session-speaker">
					{ !! validSpeakers.length &&
						validSpeakers.map( ( speaker ) => {
							const {
								id,
								title: { rendered: name },
								link: speakerLink,
							} = speaker;

							return (
								<a key={ id } href={ speakerLink }>
									{ decodeEntities( stripTags( name ) ) }
								</a>
							);
						} ) }
				</span>

				{ categories.map( ( catId ) => {
					const name = terms[ catId ].name;
					const slug = terms[ catId ].slug;

					return (
						<span key={ catId } className={ `wordcamp-live-schedule__session-cat category-${ slug }` }>
							{ name }
						</span>
					);
				} ) }
			</div>
		</div>
	);
}
