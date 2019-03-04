import { get } from 'lodash';
import RawHTML from '@wordpress/element/build-module/raw-html';
import FeaturedImage from '../shared/featured-image';

const { Component } = wp.element;

function SponsorDetail( { sponsorPost, featuredImageSize } ) {
	const featuredImageSizes = get( sponsorPost, "_embedded.wp:featuredmedia[0].media_details.sizes", {} );

	return (
		<div className={"wordcamp-sponsor-details"}>
			<FeaturedImage
				wpMediaDetails = { featuredImageSizes }
				size = { featuredImageSize }
				alt = { sponsorPost.title.rendered }
			/>
			<div className={"wordcamp-sponsor-name"}>
				<a href={sponsorPost.link} > <h3> { sponsorPost.title.rendered} </h3> </a>
			</div>
			<RawHTML>
				{ sponsorPost.content.rendered }
			</RawHTML>
		</div>
	)
}

class SponsorBlockContent extends Component {

	render() {
		const { selectedPosts, attributes } = this.props;
		const { featuredImageSize } = attributes;
		return (
			<ul>
				{
					selectedPosts.map( ( post ) => {
						return (
							<SponsorDetail
								sponsorPost={ post }
								featuredImageSize={ featuredImageSize }
							/>
						)
					} )
				}
			</ul>
		)
	}

}

export default SponsorBlockContent;
