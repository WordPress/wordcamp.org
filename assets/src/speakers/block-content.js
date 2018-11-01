import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { Disabled } = wp.components;
const { Component, RawHTML } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;
const { addQueryArgs } = wp.url;

import AvatarImage from '../shared/avatar';

class SpeakerBlockContent extends Component {
	render() {
		const { attributes, speakerPosts } = this.props;
		const { speaker_link, show_avatars, avatar_size } = attributes;

		return (
			<div className={ 'wcorg-speakers' }>
				{ speakerPosts.map( ( post, i ) =>
					<div
						key={ i }
						id={ 'wcorg-speaker-' + post.slug }
						className={ classnames( 'wcorg-speaker', 'wcorg-speaker-' + post.slug ) }
					>
						<h2>
							{ speaker_link ?
								<Disabled>
									<a href={ post.link }>
										{
											decodeEntities( post.title.rendered.trim() ) ||
											__( '(Untitled)', 'wordcamporg' )
										}
									</a>
								</Disabled> :
								decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' )
							}
						</h2>
						<div className={ 'wcorg-speaker-description' }>
							{ show_avatars &&
								<AvatarImage
									name={ decodeEntities( post.title.rendered.trim() ) || '' }
									size={ avatar_size }
									url={ post[ 'avatar_urls' ][ '24' ] }
								/>
							}
							<Disabled>
								<RawHTML>
									{ post.content.rendered.trim() }
								</RawHTML>
							</Disabled>
						</div>
					</div>,
				) }
			</div>
		);
	}
}

export default SpeakerBlockContent;
