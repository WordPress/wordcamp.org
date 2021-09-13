/**
 * External dependencies
 */
import { get } from 'lodash';

/**
 * WordPress dependencies
 */
import { __, _n } from '@wordpress/i18n';
import { Component } from '@wordpress/element';

/**
 * Internal dependencies
 */
import {
	AvatarImage,
	DangerousItemHTMLContent,
	ItemTitle,
	NoContent,
	PostList,
} from '../../components';
import { filterEntities } from '../../data';
import { arrayTokenReplace, tokenSplit } from '../../i18n';
import { sortSessionByTime } from '../sessions/utils';

/**
 * Component for the section of each speaker post that displays information about relevant sessions.
 *
 * @param {Object} props
 * @param {Object} props.speaker
 * @param {Array}  props.tracks
 * @return {Element}
 */
function SpeakerSessions( { speaker, tracks } ) {
	const sessions = get( speaker, '_embedded.sessions', [] );

	if ( ! sessions.length ) {
		return null;
	}

	sessions.sort( sortSessionByTime );

	return (
		<div className="wordcamp-speakers__sessions">
			<h4 className="wordcamp-speakers__sessions-heading">
				{ _n( 'Session', 'Sessions', sessions.length, 'wordcamporg' ) }
			</h4>

			<ul className="wordcamp-speakers__sessions-list">
				{ sessions.map( ( session ) => (
					<li key={ session.id } className="wordcamp-speakers__sessions-list-item">
						<a
							className="wordcamp-speakers__session-link"
							href={ session.link }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ session.title.rendered.trim() || __( '(Untitled)', 'wordcamporg' ) }
						</a>
						<span className="wordcamp-speakers__session-info">
							{ session.session_track.length && Array.isArray( tracks )
								? arrayTokenReplace(
									/* translators: 1: A date; 2: A time; 3: A location; */
									tokenSplit( __( '%1$s at %2$s in %3$s', 'wordcamporg' ) ),
									[
										session.session_date_time.date,
										session.session_date_time.time,
										get(
											tracks.find( ( value ) => {
												const [ firstTrackId ] = session.session_track;
												return parseInt( value.id ) === firstTrackId;
											} ),
											'name'
										),
									]
								)
								: arrayTokenReplace(
									/* translators: 1: A date; 2: A time; */
									tokenSplit( __( '%1$s at %2$s', 'wordcamporg' ) ),
									[ session.session_date_time.date, session.session_date_time.time ]
								) }
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Component for displaying the block content.
 */
class SpeakerList extends Component {
	/**
	 * Run additional operations during component initialization.
	 *
	 * @param {Object} props
	 */
	constructor( props ) {
		super( props );

		this.getFilteredPosts = this.getFilteredPosts.bind( this );
	}

	/**
	 * Filter and sort the content that will be rendered.
	 *
	 * @return {Array}
	 */
	getFilteredPosts() {
		const { attributes, entities } = this.props;
		const { wcb_speaker: posts } = entities;
		const { mode, item_ids, sort } = attributes;

		const args = {};

		if ( Array.isArray( item_ids ) && item_ids.length > 0 ) {
			args.filter = [
				{
					fieldName: mode === 'wcb_speaker' ? 'id' : 'speaker_group',
					fieldValue: item_ids,
				},
			];
		}

		args.sort = sort;

		return filterEntities( posts, args );
	}

	/**
	 * Render the block content.
	 *
	 * @return {Element}
	 */
	render() {
		const { attributes, entities } = this.props;
		const { wcb_track: tracks } = entities;
		const { avatar_align, avatar_size, content, headingAlign, show_avatars, show_session } = attributes;

		const posts = this.getFilteredPosts();
		const isLoading = ! Array.isArray( posts );
		const hasPosts = ! isLoading && posts.length > 0;

		if ( isLoading || ! hasPosts ) {
			return <NoContent loading={ isLoading } />;
		}

		return (
			<PostList attributes={ attributes } className="wordcamp-speakers">
				{ posts.map( ( post ) => (
					<div key={ post.slug } className={ `wordcamp-speakers__post slug-${ post.slug }` }>
						<ItemTitle
							className="wordcamp-speakers__title"
							align={ headingAlign }
							headingLevel={ 3 }
							title={ post.title.rendered.trim() }
							link={ post.link }
						/>

						{ show_avatars && (
							<AvatarImage
								className={ `align-${ avatar_align }` }
								name={ post.title.rendered.trim() || '' }
								size={ avatar_size }
								url={ post.avatar_urls[ '24' ] }
								imageLink={ post.link }
							/>
						) }

						{ 'none' !== content && (
							<DangerousItemHTMLContent
								className={ `wordcamp-speakers__content is-${ content }` }
								content={ 'full' === content ? post.content.rendered.trim() : post.excerpt.rendered.trim() }
							/>
						) }

						{ true === show_session && <SpeakerSessions speaker={ post } tracks={ tracks } /> }
					</div>
				) ) }
			</PostList>
		);
	}
}

export default SpeakerList;
