import { get } from 'lodash';
import RawHTML from '@wordpress/element/build-module/raw-html';
import FeaturedImage from '../shared/featured-image';

const { Component } = wp.element;

function SponsorDetail( { sponsorPost } ) {
	const featuredImageSizes = get( sponsorPost, "_embedded.wp:featuredmedia[0].media_details.sizes", {} );

	return (
		<div className={"wordcamp-sponsor-details"}>
			<FeaturedImage
				wpMediaDetails = { featuredImageSizes }
				size = { 'm' }
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
		const { selectedPosts } = this.props;
		return (
			<ul>
				{
					selectedPosts.map( ( post ) => {
						return (
							<SponsorDetail sponsorPost={ post }/>
						)
					} )
				}
			</ul>
		)
	}

}

export default SponsorBlockContent;
