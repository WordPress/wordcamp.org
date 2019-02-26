/**
 * External dependencies
 */
import { get } from 'lodash';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { Disabled } = wp.components;
const { Component, Fragment, RawHTML } = wp.element;
const { decodeEntities } = wp.htmlEntities;
const { __, sprintf } = wp.i18n;

/**
 * Internal dependencies
 */
import { arrayToHumanReadableList } from "../shared/block-content";

function SessionSpeakers( { session } ) {
	let speakers;
	let speakerData = get( session, '_embedded.speakers', [] );

	if ( speakerData.length ) {
		speakerData = speakerData.map( ( speaker ) => {
			let { link = '', title = {} } = speaker;
			title = title.rendered || __( 'Unnamed', 'wordcamporg' );

			return sprintf(
				'<a href="%s">%s</a>',
				link,
				decodeEntities( title.trim() )
			);
		} );

		speakers = sprintf(
			/* translators: %s is a list of names. */
			__( 'Presented by %s', 'wordcamporg' ),
			arrayToHumanReadableList( speakerData )
		);
	}

	return (
		<Fragment>
			{ speakers &&
				<div className="wordcamp-session-speakers">
					<Disabled>
						<RawHTML>
							{ speakers }
						</RawHTML>
					</Disabled>
				</div>
			}
		</Fragment>
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

			metaContent = sprintf(
				/* translators: 1: A date; 2: A time; 3: A location; */
				__( '%1$s at %2$s in %3$s', 'wordcamporg' ),
				decodeEntities( session.session_date_time.date ),
				decodeEntities( session.session_date_time.time ),
				sprintf(
					'<span class="wordcamp-session-track wordcamp-session-track-%s">%s</span>',
					decodeEntities( firstTrack.slug.trim() ),
					decodeEntities( firstTrack.name.trim() )
				)
			);
		} else {
			metaContent = sprintf(
				/* translators: 1: A date; 2: A time; */
				__( '%1$s at %2$s', 'wordcamporg' ),
				decodeEntities( session.session_date_time.date ),
				decodeEntities( session.session_date_time.time ),
			);
		}

		meta = (
			<div className="wordcamp-session-details-meta">
				<RawHTML>
					{ metaContent }
				</RawHTML>
			</div>
		);
	}

	if ( show_category && session.session_category.length ) {
		/* translators: used between list items, there is a space after the comma */
		const item_separator = esc_html__( ', ', 'wordcamporg' );
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
				{ categories.join( item_separator ) }
			</div>
		);
	}

	return (
		<div className="wordcamp-session-details">
			{ show_meta && meta }
			{ show_category && category }
		</div>
	);
}

class SessionsBlockContent extends Component {
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

						{ show_speaker && get( post, '_embedded.speakers', [] ).length &&
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
