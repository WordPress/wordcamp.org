/**
 * External dependencies
 */
import {get, difference, intersection} from 'lodash';
import classnames          from 'classnames';

/**
 * WordPress dependencies
 */
const { Component }       = wp.element;
const { escapeAttribute } = wp.escapeHtml;
const { __ }              = wp.i18n;

/**
 * Internal dependencies
 */
import FeaturedImage                  from '../shared/featured-image';
import GridContentLayout              from '../shared/grid-layout/block-content';
import {ItemTitle, ItemHTMLContent, ItemPermalink} from '../shared/block-content';

/**
 * Renders individual sponsor post inside editor.
 *
 * @param {Object} sponsorPost
 * @param {Object} attributes
 *
 * @return {Element}
 */
function SponsorDetail( { sponsorPost, attributes } ) {
	const { show_name, show_logo, content, featured_image_width } = attributes;
	const displayContent = 'full' === content ? sponsorPost.content.rendered.trim() : sponsorPost.excerpt.rendered.trim();

	return (
		<div className={ 'wordcamp-sponsor-details wordcamp-sponsor-details-' + escapeAttribute( sponsorPost.slug ) }>

			{ ( show_name || show_name === undefined ) &&
				<ItemTitle
					className="wordcamp-sponsor-title"
					headingLevel={ 3 }
					title={ sponsorPost.title.rendered.trim() }
					link={ sponsorPost.link }
				/>
			}

			{ ( show_logo || show_logo === undefined ) &&
				<FeaturedImage
					imageData={ get( sponsorPost, '_embedded.wp:featuredmedia[0]', {} ) }
					width={ featured_image_width }
					className={ classnames( 'wordcamp-sponsor-featured-image', 'wordcamp-sponsor-logo' ) }
					imageLink={ sponsorPost.link }
				/>
			}

			{ ( 'none' !== content ) &&
				<ItemHTMLContent
					className={ classnames( 'wordcamp-sponsor-content' ) }
					content={ displayContent }
				/>
			}

			{ ( 'full' === content ) &&
				<ItemPermalink
					link={ sponsorPost.link }
					linkText={ __( 'Visit sponsor page', 'wordcamporg' ) }
				/>
			}
		</div>
	);
}

/**
 * Component for rendering Sponsors post inside editor.
 */
class SponsorBlockContent extends Component {
	constructor( props ) {
		super( props );
		this.state = {
			selectedPosts : [],
		};
	}

	static getDerivedStateFromProps( nextProps, state ) {
		// Sort the sponsor posts. Since this could potentially be expensive, lets do it in getDerivedStateFromProps hook and set state with result if anything is changed.
		const {
			selectedPosts    : newSelectedPosts,
			attributes       : newAttributes,
			sponsorTermOrder : newSponsorTermOrder
		} = nextProps;

		const { sort_by: newSortBy } = newAttributes;
		const newSelectedPostIds = newSelectedPosts.map( ( post ) => post.id ).sort();

		const { selectedPosts, sortBy } = state;
		const selectedPostsIds = selectedPosts.map( ( post ) => post.id ).sort();

		if ( sortBy === newSortBy && newSelectedPosts.length === selectedPosts.length && difference( selectedPostsIds, newSelectedPostIds ).length === 0 ) {
			// Everything is same. No need to calculate sorting. Lets bail.
			return;
		}

		let sortedPosts;

		switch ( newSortBy ) {
			case 'sponsor_level' :
				if ( ! Array.isArray( newSponsorTermOrder ) ||
					newSponsorTermOrder.length === 0 ) {
					break;
				}
				sortedPosts = newSelectedPosts.sort( ( sponsor1, sponsor2 ) => {
					return newSponsorTermOrder.indexOf( ( sponsor1.sponsor_level || [] )[ 0 ] ) - newSponsorTermOrder.indexOf( ( sponsor2.sponsor_level || [] )[ 0 ] );
				} );
				break;

			case 'name_desc' :
				sortedPosts = newSelectedPosts.sort( ( sponsor1, sponsor2 ) => {
					const title1 = sponsor1.title.rendered.trim();
					const title2 = sponsor2.title.rendered.trim();
					return title1 > title2 ? -1 : 1;
				} );
				break;

			case 'name_asc' :
			default:
				sortedPosts = newSelectedPosts.sort( ( sponsor1, sponsor2 ) => {
					const title1 = sponsor1.title.rendered.trim();
					const title2 = sponsor2.title.rendered.trim();
					return title1 < title2 ? -1 : 1;
				} );
				break;
		}

		return( {
			selectedPosts : sortedPosts,
			sortBy        : newSortBy,
		} );
	}

	/**
	 * Renders Sponsor Block content inside editor.
	 *
	 * @return {Element}
	 */
	render() {
		const { selectedPosts } = this.state;
		const { attributes }    = this.props;
		return (
			<GridContentLayout
				className="wordcamp-sponsors-block"
				{ ...this.props }
			>
				{
					selectedPosts.map( ( post ) => {
						return (
							<SponsorDetail
								key={ post.id }
								sponsorPost={ post }
								attributes={ attributes }
							/>
						);
					} )
				}
			</GridContentLayout>
		);
	}
}

export default SponsorBlockContent;
