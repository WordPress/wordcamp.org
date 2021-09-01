/**
 * External dependencies
 */
import { get } from 'lodash';

/**
 * WordPress dependencies
 */
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	DangerousItemHTMLContent,
	FeaturedImage,
	ItemTitle,
	NoContent,
	PostList,
} from '../../components';
import { filterEntities } from '../../data';
import { arrayTokenReplace, intersperse, listify, tokenSplit } from '../../i18n';

/**
 * Component for the section of each session post that displays information about the session's speakers.
 *
 * @param          session.session
 * @param {Object} session
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

	return <p className="wordcamp-sessions__speakers">{ speakers }</p>;
}

/**
 * Component for the section of each session post that displays a session's assigned categories.
 *
 * @param          session.session
 * @param {Object} session
 * @return {Element}
 */
function SessionCategory( { session } ) {
	let categoryContent;
	const terms = get( session, "_embedded['wp:term']", [] ).flat();

	if ( session.session_category.length ) {
		/* translators: used between list items, there is a space after the comma */
		const separator = __( ', ', 'wordcamporg' );
		const categories = terms
			.filter( ( term ) => {
				return 'wcb_session_category' === term.taxonomy;
			} )
			.map( ( term ) => {
				return (
					<span key={ term.slug } className={ `wordcamp-sessions__category slug-${ term.slug }` }>
						{ term.name.trim() }
					</span>
				);
			} );

		categoryContent = intersperse( categories, separator );
	}

	return <div className="wordcamp-sessions__categories">{ categoryContent }</div>;
}

/**
 * Component for displaying the block content.
 */
class SessionList extends Component {
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
				if ( Number( a.meta._wcpt_session_time ) === Number( b.meta._wcpt_session_time ) ) {
					const title = get( a, 'title.rendered', '' );
					return title.localeCompare( b.title.rendered );
				}

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
			content,
			featured_image_width,
			headingAlign,
			image_align,
			show_category,
			show_images,
			show_meta,
			show_speaker,
		} = attributes;

		const posts = this.getFilteredPosts();
		const isLoading = ! Array.isArray( posts );
		const hasPosts = ! isLoading && posts.length > 0;

		if ( isLoading || ! hasPosts ) {
			return <NoContent loading={ isLoading } />;
		}

		return (
			<PostList attributes={ attributes } className="wordcamp-sessions">
				{ posts.map( ( post ) => (
					<div key={ post.slug } className={ `wordcamp-sessions__post slug-${ post.slug }` }>
						<ItemTitle
							className="wordcamp-sessions__title"
							align={ headingAlign }
							headingLevel={ 3 }
							title={ post.title.rendered.trim() }
							link={ post.link }
						/>

						{ show_speaker && <SessionSpeakers session={ post } /> }

						{ show_images && (
							<FeaturedImage
								imageData={ get( post, '_embedded.wp:featuredmedia[0]', {} ) }
								width={ Number( featured_image_width ) }
								className={ `wordcamp-sessions__featured-image align-${ image_align }` }
								imageLink={ post.link }
							/>
						) }

						{ 'none' !== content && (
							<DangerousItemHTMLContent
								className={ `wordcamp-sessions__content is-${ content }` }
								content={ 'full' === content ? post.content.rendered.trim() : post.excerpt.rendered.trim() }
							/>
						) }

						{ ( show_meta || show_category ) && (
							<div className="wordcamp-sessions__details">
								{ show_meta && <div className="wordcamp-sessions__time-location">{ post.details }</div> }
								{ show_category && <SessionCategory session={ post } /> }
							</div>
						) }
					</div>
				) ) }
			</PostList>
		);
	}
}

export default SessionList;
