/**
 * External dependencies
 */
import { get } from 'lodash';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ItemTitle, DangerousItemHTMLContent, ItemPermalink, BlockNoContent } from '../../components/block-content';
import { FeaturedImage } from '../../components/image';
import { PostList } from '../../components/post-list';
import { filterEntities } from '../../data';
import { tokenSplit, arrayTokenReplace, intersperse, listify } from '../../i18n';

/**
 * Component for the section of each session post that displays information about the session's speakers.
 *
 * @param {Object} session
 *
 * @return {Element}
 */
function SessionSpeakers( { session } ) {
	let speakerData = get( session, '_embedded.speakers', [] );

	if ( speakerData.length === 0 ) {
		return null;
	}

	speakerData = speakerData.map( ( speaker ) => {
		if ( speaker.hasOwnProperty( 'code' ) ) {
			// The wporg username given for this speaker returned an error.
			return null;
		}

		const { link } = speaker;
		let { title = {} } = speaker;

		title = title.rendered.trim() || __( 'Unnamed', 'wordcamporg' );

		if ( ! link ) {
			return title;
		}

		return (
			<a key={ link } href={ link } target="_blank" rel="noopener noreferrer">
				{ title }
			</a>
		);
	} );

	const speakers = arrayTokenReplace(
		/* translators: %s is a list of names. */
		tokenSplit( __( 'Presented by %s', 'wordcamporg' ) ),
		[ listify( speakerData ) ]
	);

	return (
		<p className="wordcamp-sessions__speakers">
			{ speakers }
		</p>
	);
}

/**
 * Component for the section of each session post that displays a session's assigned categories.
 *
 * @param {Object} session
 *
 * @return {Element}
 */
function SessionCategory( { session } ) {
	let categoryContent;
	const terms = get( session, '_embedded[\'wp:term\']', [] ).flat();

	if ( session.session_category.length ) {
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
						className={ classnames( 'wordcamp-sessions__category', `slug-${ term.slug }` ) }
					>
						{ term.name.trim() }
					</span>
				);
			} );

		categoryContent = intersperse( categories, separator );
	}

	return (
		<div className="wordcamp-sessions__categories">
			{ categoryContent }
		</div>
	);
}

/**
 * Component for displaying the block content.
 */
export class BlockContent extends Component {
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
	 * @returns {Array}
	 */
	getFilteredPosts() {
		const { attributes, entities } = this.props;
		const { wcb_session: posts } = entities;
		const { mode, item_ids, sort } = attributes;

		const args = {};

		if ( Array.isArray( item_ids ) && item_ids.length > 0 ) {
			let fieldName;

			switch ( mode ) {
				case 'wcb_session':
					fieldName = 'id';
					break;
				case 'wcb_track':
					fieldName = 'session_track';
					break;
				case 'wcb_session_category':
					fieldName = 'session_category';
					break;
			}

			args.filter = [
				{
					fieldName: fieldName,
					fieldValue: item_ids,
				},
			];
		}

		if ( 'session_time' !== sort ) {
			args.sort = sort;
		}

		let filtered = filterEntities( posts, args );

		if ( Array.isArray( filtered ) && 'session_time' === sort ) {
			filtered = filtered.sort( ( a, b ) => {
				return Number( a.meta._wcpt_session_time ) - Number( b.meta._wcpt_session_time );
			} );
		}

		return filtered;
	}

	/**
	 * Render the block content.
	 *
	 * @return {Element}
	 */
	render() {
		const { attributes } = this.props;
		const {
			show_speaker,
			show_images,
			image_align,
			featured_image_width,
			content,
			show_meta,
			show_category,
		} = attributes;

		const posts = this.getFilteredPosts();
		const isLoading = ! Array.isArray( posts );
		const hasPosts = ! isLoading && posts.length > 0;

		if ( isLoading || ! hasPosts ) {
			return (
				<BlockNoContent loading={ isLoading } />
			);
		}

		return (
			<PostList
				{ ...this.props }
				className="wordcamp-sessions"
			>
				{ posts.map( ( post ) =>
					<div
						key={ post.slug }
						className={ classnames( 'wordcamp-sessions__post', `slug-${ post.slug }` ) }
					>
						<ItemTitle
							className="wordcamp-sessions__title"
							headingLevel={ 3 }
							title={ post.title.rendered.trim() }
							link={ post.link }
						/>

						{ show_speaker && <SessionSpeakers session={ post } /> }

						{ show_images &&
							<FeaturedImage
								imageData={ get( post, '_embedded.wp:featuredmedia[0]', {} ) }
								width={ Number( featured_image_width ) }
								className={ classnames( 'wordcamp-sessions__featured-image', 'align-' + image_align ) }
								imageLink={ post.link }
							/>
						}

						{ 'none' !== content &&
							<DangerousItemHTMLContent
								className={ classnames( 'wordcamp-sessions__content', 'is-' + content ) }
								content={ 'full' === content ? post.content.rendered.trim() : post.excerpt.rendered.trim() }
							/>
						}

						{ ( show_meta || show_category ) && (
							<div className="wordcamp-sessions__details">
								{ show_meta && (
									<div className="wordcamp-sessions__time-location">
										{ post.details }
									</div>
								) }
								{ show_category &&
									<SessionCategory session={ post } />
								}
							</div>
						) }

						{ ( 'full' === content ) &&
							<ItemPermalink
								link={ post.link }
								linkText={ __( 'Visit session page', 'wordcamporg' ) }
								className="wordcamp-sessions__permalink"
							/>
						}
					</div>
				) }
			</PostList>
		);
	}
}
