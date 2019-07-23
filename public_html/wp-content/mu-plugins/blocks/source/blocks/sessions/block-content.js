/**
 * External dependencies
 */
import classnames from 'classnames';
import { get }    from 'lodash';

/**
 * WordPress dependencies
 */
import { __ }        from '@wordpress/i18n';
import { Component } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { BlockNoContent, PostList } from '../../components/post-list';
import {
	DangerousItemHTMLContent,
	ItemPermalink,
	ItemTitle,
}                                   from '../../components/block-content';
import { FeaturedImage }            from '../../components/image';
import { filterEntities }           from '../../data';
import SessionCategory              from './content/session-category';
import SessionSpeakers              from './content/session-speakers';

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

			args.filter  = [
				{
					fieldName  : fieldName,
					fieldValue : item_ids,
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

		const posts     = this.getFilteredPosts();
		const isLoading = ! Array.isArray( posts );
		const hasPosts  = ! isLoading && posts.length > 0;

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
