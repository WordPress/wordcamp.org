/**
 * External dependencies
 */
import { get } from 'lodash';

/**
 * WordPress dependencies
 */
import { stripTagsAndEncodeText } from '@wordpress/sanitize';

/**
 * Component
 *
 * @returns {Component}
 */
export default function( { session, track } ) {
	if ( ! session ) {
		return null;
	}

	const { link, terms = {} } = session;
	const title = get( session, 'title.rendered', '' );
	const type = get( session, 'meta._wcpt_session_type', '' );
	const categories = session.session_category || [];
	const time = get( session, 'session_date_time.time', '' );

	const speakers = get( session, '_embedded.speakers', [] );
	const validSpeakers = speakers.filter( ( speaker ) => !! speaker.id );

	return (
		<div className={ `wordcamp-live-schedule__session session-${ type }` }>
			<span className={ `wordcamp-live-schedule__session-track track-${ track.slug }` }>{ track.name }</span>

			<div className="wordcamp-live-schedule__session-details">
				<h4 className="wordcamp-live-schedule__session-title">
					{ !! link ? (
						<a href={ link }>{ stripTagsAndEncodeText( title ) }</a>
					) : (
						stripTagsAndEncodeText( title )
					) }
				</h4>

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
									{ stripTagsAndEncodeText( name ) }
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
