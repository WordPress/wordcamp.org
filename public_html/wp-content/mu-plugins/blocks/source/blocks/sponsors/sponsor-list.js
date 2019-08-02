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
import {
	DangerousItemHTMLContent,
	FeaturedImage,
	ItemPermalink,
	ItemTitle,
	NoContent,
	PostList,
} from '../../components';
import { filterEntities } from '../../data';

/**
 * Component for displaying the block content.
 */
class SponsorList extends Component {
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
		const { wcb_sponsor: posts } = entities;
		const { mode, item_ids, sort } = attributes;

		const args = {};

		if ( Array.isArray( item_ids ) && item_ids.length > 0 ) {
			args.filter = [
				{
					fieldName: mode === 'wcb_sponsor' ? 'id' : 'sponsor_level',
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
		const { attributes } = this.props;
		const { content, featured_image_width, headingAlign, image_align, show_logo, show_name } = attributes;

		const posts = this.getFilteredPosts();
		const isLoading = ! Array.isArray( posts );
		const hasPosts = ! isLoading && posts.length > 0;

		if ( isLoading || ! hasPosts ) {
			return <NoContent loading={ isLoading } />;
		}

		return (
			<PostList attributes={ attributes } className="wordcamp-sponsors">
				{ posts.map( ( post ) => (
					<div key={ post.slug } className={ classnames( 'wordcamp-sponsors__post', `slug-${ post.slug }` ) }>
						{ show_name && (
							<ItemTitle
								className="wordcamp-sponsors__title"
								align={ headingAlign }
								headingLevel={ 3 }
								title={ post.title.rendered.trim() }
								link={ post.link }
							/>
						) }

						{ show_logo && (
							<FeaturedImage
								imageData={ get( post, '_embedded.wp:featuredmedia[0]', {} ) }
								width={ featured_image_width }
								className={ classnames( [
									'wordcamp-sponsors__featured-image',
									'wordcamp-sponsors__logo',
									`align-${ image_align }`,
								] ) }
								imageLink={ post.link }
							/>
						) }

						{ 'none' !== content && (
							<DangerousItemHTMLContent
								className={ `wordcamp-sponsors__content is-${ content }` }
								content={ 'full' === content ? post.content.rendered.trim() : post.excerpt.rendered.trim() }
							/>
						) }

						{ 'full' === content && (
							<ItemPermalink
								link={ post.link }
								linkText={ __( 'Visit sponsor page', 'wordcamporg' ) }
								className="wordcamp-sponsors__permalink"
							/>
						) }
					</div>
				) ) }
			</PostList>
		);
	}
}

export default SponsorList;
