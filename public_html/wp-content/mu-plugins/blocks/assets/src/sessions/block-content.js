/**
 * External dependencies
 */
import { get } from 'lodash';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { Disabled } = wp.components;
const { Component, RawHTML } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import { tokenSplit, arrayTokenReplace, intersperse, listify } from "../shared/block-content";

function SessionSpeakers( { session } ) {
	let speakers;
	let speakerData = get( session, '_embedded.speakers', [] );

	speakerData = speakerData.map( ( speaker ) => {
		let { link = '', title = {} } = speaker;
		title = title.rendered || __( 'Unnamed', 'wordcamporg' );

		if ( ! link ) {
			return decodeEntities( title.trim() );
		}

		return ( <a href={ link }>{ decodeEntities( title.trim() ) }</a> );
	} );

	speakers = arrayTokenReplace(
		/* translators: %s is a list of names. */
		tokenSplit( __( 'Presented by %s', 'wordcamporg' ) ),
		[ listify( speakerData ) ]
	);

	return (
		<div className="wordcamp-session-speakers">
			{ speakers }
		</div>
	);
}

function SessionImage( { session } ) {
	let image;

	const url = get( session, '_embedded[\'wp:featuredmedia\'].media_details.sizes.thumbnail.source_url', '' );

	if ( url ) {
		image = (
			<img
				src={ url }
				alt={ decodeEntities( session.title.rendered.trim() ) }
				className={ classnames( 'wordcamp-session-image' ) }
			/>
		);
	} else {
		image = (
			<div className="wordcamp-session-default-image"/>
		);
	}

	return image;
}

function SessionDetails( { session, show_meta, show_category } ) {
	let meta, metaContent, category;
	const terms = get( session, '_embedded[\'wp:term\']', [] ).flat();

	if ( show_meta ) {
		if ( session.session_track.length ) {
			const [ firstTrack ] = terms.filter( ( term ) => {
				return 'wcb_track' === term.taxonomy;
			} );

			metaContent = arrayTokenReplace(
				/* translators: 1: A date; 2: A time; 3: A location; */
				tokenSplit( __( '%1$s at %2$s in %3$s', 'wordcamporg' ) ),
				[
					decodeEntities( session.session_date_time.date ),
					decodeEntities( session.session_date_time.time ),
					(
						<span className={ classnames( 'wordcamp-session-track', 'wordcamp-session-track-' + decodeEntities( firstTrack.slug.trim() ) ) }>
							{ decodeEntities( firstTrack.name.trim() ) }
						</span>
					)
				]
			);
		} else {
			metaContent = arrayTokenReplace(
				/* translators: 1: A date; 2: A time; */
				tokenSplit( __( '%1$s at %2$s', 'wordcamporg' ) ),
				[
					decodeEntities( session.session_date_time.date ),
					decodeEntities( session.session_date_time.time ),
				]
			);
		}

		meta = (
			<div className="wordcamp-session-details-meta">
				{ metaContent }
			</div>
		);
	}

	if ( show_category && session.session_category.length ) {
		/* translators: used between list items, there is a space after the comma */
		const separator = __( ', ', 'wordcamporg' );
		const categories = terms
			.filter( ( term ) => {
				return 'wcb_session_category' === term.taxonomy;
			} )
			.map( ( term ) => {
				return (
					<span
						key={ term.slug }
						className={ classnames( 'wordcamp-session-category', 'wordcamp-session-category-' + decodeEntities( term.slug ) ) }
					>
						{ decodeEntities( term.name.trim() ) }
					</span>
				);
			} );

		category = (
			<div className="wordcamp-session-details-categories">
				{ intersperse( categories, separator ) }
			</div>
		);
	}

	return (
		<div className="wordcamp-session-details">
			{ meta }
			{ category }
		</div>
	);
}

class SessionsBlockContent extends Component {
	hasSpeaker( session ) {
		return get( session, '_embedded.speakers', [] ).length > 0;
	}

	render() {
		const { attributes, sessionPosts } = this.props;
		const { className, show_speaker, show_images, image_align, image_size, content, excerpt_more, show_meta, show_category } = attributes;

		const containerClasses = [
			'wordcamp-sessions-block',
			className
		];

		return (
			<ul className={ classnames( containerClasses ) }>
				{ sessionPosts.map( ( post ) =>
					<li
						key={ post.slug }
						className={ classnames(
							'wordcamp-session',
							'wordcamp-session-' + decodeEntities( post.slug ),
							'wordcamp-clearfix'
						) }
					>
						<h3 className="wordcamp-session-title-heading">
							<Disabled>
								<a href={ post.link }>
									{ decodeEntities( post.title.rendered.trim() ) || __( '(Untitled)', 'wordcamporg' ) }
								</a>
							</Disabled>
						</h3>

						{ show_speaker && this.hasSpeaker( post ) &&
							<SessionSpeakers session={ post }/>
						}

						{ show_images && get( post, '_embedded[\'wp:featuredmedia\'].media_details.sizes.thumbnail.source_url', '' ) &&
							<div className={ classnames( 'wordcamp-session-image-container', 'align-' + decodeEntities( image_align ) ) }>
								<Disabled>
									<a href={ post.link } className="wordcamp-session-image-link">
										<SessionImage
											session={ post }
										/>
									</a>
								</Disabled>
							</div>
						}

						{ 'none' !== content &&
							<div className={ classnames( 'wordcamp-session-content', 'wordcamp-session-content-' + decodeEntities( content ) ) }>
								{ 'full' === content &&
									<Disabled>
										<RawHTML children={ post.content.rendered.trim() } />
										<p className="wordcamp-session-permalink">
											<a href={ post.link }>
												{ __( 'Visit session page', 'wordcamporg' ) }
											</a>
										</p>
									</Disabled>
								}
								{ 'excerpt' === content &&
									<Disabled>
										<RawHTML children={ post.excerpt.rendered.trim() } />
										{ excerpt_more &&
										<p className="wordcamp-session-permalink">
											<a href={ post.link }>
												{ __( 'Read more', 'wordcamporg' ) }
											</a>
										</p>
										}
									</Disabled>
								}
							</div>
						}

						{ ( show_meta || show_category ) &&
							<SessionDetails
								session={ post }
								show_meta={ show_meta }
								show_category={ show_category }
							/>
						}
					</li>
				) }
			</ul>
		);
	}
}

export default SessionsBlockContent;
