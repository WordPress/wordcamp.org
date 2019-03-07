import { get } from 'lodash';
const { RawHTML } = wp.element;
import classnames from 'classnames';
import FeaturedImage from '../shared/featured-image';
import './block-content.scss';

const { Component } = wp.element;

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
		show_name, show_logo, show_desc, sponsor_logo_height, sponsor_logo_width
	} = attributes;
	const featuredImageSize = { height: sponsor_logo_height, width: sponsor_logo_width };

	const featuredImageSizes = get( sponsorPost, "_embedded.wp:featuredmedia[0].media_details.sizes", {} );

	return (
		<div className={"wordcamp-sponsor-details"}>
			{ ( show_logo || show_logo === undefined ) &&
			<FeaturedImage
				className={"wordcamp-sponsor-featured-image wordcamp-sponsor-logo"}
				wpMediaDetails={featuredImageSizes}
				size={featuredImageSize}
				alt={sponsorPost.title.rendered}
				onChange={onFeatureImageChange}
			/>
			}
			{ ( show_name || show_name === undefined ) &&
			<div className={"wordcamp-sponsor-name"}>
				<a href={sponsorPost.link}>
					<h3> {sponsorPost.title.rendered} </h3>
				</a>
			</div>
			}
			{ ( show_desc || show_desc === undefined ) &&
			<RawHTML>
				{sponsorPost.content.rendered}
			</RawHTML>
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
		const columns = attributes.columns || 1;
		const containerClasses = [
			'wordcamp-sponsors-block',
			'wordcamp-sponsors-list',
		];
		if ( 1 !== columns ) {
			containerClasses.push( 'grid-columns-' + Number( columns ) );
			containerClasses.push( 'layout-grid' );
			containerClasses.push( 'layout-' + columns );
		}

		return (
			<ul className={ classnames( containerClasses ) } >
				{
					selectedPosts.map( ( post ) => {
						return (
							<li
								className={ classnames(
									'wordcamp-sponsor',
									'wordcamp-clearfix',
									'wordcamp-sponsor-' + post.slug
								)}
							>
								<SponsorDetail
									sponsorPost={ post }
									attributes={ attributes }
									onFeatureImageChange = {
										( imageURL ) => {
											this.setFeaturedImageURL( post.id, imageURL );
										}
									}
								/>
							</li>
						)
					} )
				}
			</ul>
		)
	}

}

export default SponsorBlockContent;
