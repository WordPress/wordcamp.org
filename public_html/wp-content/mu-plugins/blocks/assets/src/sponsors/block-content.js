/**
 * External dependencies.
 */
import { get } from 'lodash';
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
const { Component } = wp.element;

/**
 * Internal dependencies.
 */
import FeaturedImage from '../shared/featured-image';
import GridContentLayout from '../shared/grid-layout/block-content';
import { ItemTitle, ItemHTMLContent } from '../shared/block-content';

/**
 * Renders individual sponsor post inside editor.
 *
 * @param sponsorPost
 * @param attributes
 * @param onFeatureImageChange
 * @returns {*}
 * @constructor
 */
function SponsorDetail( { sponsorPost, attributes, onFeatureImageChange } ) {

	const {
		show_name, show_logo, show_desc, featured_image_height, featured_image_width
	} = attributes;
	const featuredImageSize = { height: featured_image_height, width: featured_image_width };

	const featuredImageSizes = get( sponsorPost, "_embedded.wp:featuredmedia[0].media_details.sizes", {} );

	return (
		<div className={ "wordcamp-sponsor-details"}>

			{ ( show_name || show_name === undefined ) &&
			<ItemTitle
				className='wordcamp-sponsor-title'
				headingLevel={ 3 }
				title={ sponsorPost.title.rendered.trim() }
				link={ sponsorPost.link }
			/>
			}
			{ ( show_logo || show_logo === undefined ) &&
			<FeaturedImage
				className={"wordcamp-sponsor-featured-image wordcamp-sponsor-logo"}
				wpMediaDetails={featuredImageSizes}
				size={featuredImageSize}
				alt={sponsorPost.title.rendered}
				onChange={onFeatureImageChange}
			/>
			}
			{ ( show_desc || show_desc === undefined ) &&
			<ItemHTMLContent
				className={ classnames( 'wordcamp-sponsor-content' ) }
				content={ sponsorPost.content.rendered.trim() }
			/>
			}
		</div>
	);
}

/**
 * Component for rendering Sponsors post inside editor.
 */
class SponsorBlockContent extends Component {

	/**
	 * Call back for when featured image URL is changed for a post.
	 * We are storing the URL object as JSON stringified value because I was not able to get object type to work properly. Maybe its not supported in Gutenberg yet.
	 *
	 * @param sponsorId
	 * @param imageURL
	 */
	setFeaturedImageURL( sponsorId, imageURL) {
		const sponsor_image_urls = this.sponsorImageUrl || {};
		sponsor_image_urls[ sponsorId ] = imageURL;
		this.sponsorImageUrl = sponsor_image_urls;

		const { setAttributes } = this.props;
		const sponsor_image_urls_latest = this.sponsorImageUrl;
		setAttributes( { sponsor_image_urls: encodeURIComponent( JSON.stringify( sponsor_image_urls_latest ) ) } );
	}

	/**
	 * Renders Sponsor Block content inside editor.
	 *
	 * @returns {*}
	 */
	render() {
		const { selectedPosts, attributes } = this.props;

		return (
			<GridContentLayout
				{ ...this.props }
			>
				{
					selectedPosts.map( ( post ) => {
						return (
								<SponsorDetail
									sponsorPost={ post }
									attributes={ attributes }
									onFeatureImageChange = {
										( imageURL ) => {
											this.setFeaturedImageURL( post.id, imageURL );
										}
									}
								/>
						)
					} )
				}
			</GridContentLayout>
		)
	}

}

export default SponsorBlockContent;
