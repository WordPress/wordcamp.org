/**
 * WordPress dependencies
 */
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

/**
 * Component for displaying the block content.
 */
class OrganizerList extends Component {
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
		const { wcb_organizer: posts } = entities;
		const { mode, item_ids, sort } = attributes;

		const args = {};

		if ( Array.isArray( item_ids ) && item_ids.length > 0 ) {
			args.filter = [
				{
					fieldName: mode === 'wcb_organizer' ? 'id' : 'organizer_team',
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
		const { avatar_size, avatar_align, content, headingAlign, show_avatars } = attributes;

		const posts = this.getFilteredPosts();
		const isLoading = ! Array.isArray( posts );
		const hasPosts = ! isLoading && posts.length > 0;

		if ( isLoading || ! hasPosts ) {
			return <NoContent loading={ isLoading } />;
		}

		/* Note that organizer posts are not 'public', so there are no permalinks. */
		return (
			<PostList attributes={ attributes } className="wordcamp-organizers">
				{ posts.map( ( post ) => (
					<div key={ post.slug } className={ `wordcamp-organizers__post slug-${ post.slug.trim() }` }>
						<ItemTitle
							className="wordcamp-organizers__title"
							align={ headingAlign }
							headingLevel={ 3 }
							title={ post.title.rendered.trim() }
						/>

						{ show_avatars && (
							<AvatarImage
								className={ `align-${ avatar_align }` }
								name={ post.title.rendered.trim() || '' }
								size={ avatar_size }
								url={ post.avatar_urls[ '24' ] }
							/>
						) }

						{ 'none' !== content && (
							<DangerousItemHTMLContent
								className={ `wordcamp-organizers__content is-${ content }` }
								content={ 'full' === content ? post.content.rendered.trim() : post.excerpt.rendered.trim() }
							/>
						) }
					</div>
				) ) }
			</PostList>
		);
	}
}

export default OrganizerList;
