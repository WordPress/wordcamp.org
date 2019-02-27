import RawHTML from '@wordpress/element/build-module/raw-html';
import {get} from 'lodash';
import {WCPTFeaturedImage} from '../shared/block-content';

const { Component } = wp.element;

function SponsorImage( { sponsorPost } ) {

}

function SponsorDetail( { sponsorPost } ) {

	return (
		<div className={"wordcamp-sponsor-details"}>
			<WCPTFeaturedImage
				post={ sponsorPost }
				imageClass={ "wordcamp-sponsor-featured-image" }
				defaultImageClass={ "wordcamp-sponsor-def-image" }
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

		console.log("Comes here..");

		const { attributes, sponsorPosts } = this.props;
		return (
			<ul>
				{
					sponsorPosts.map( ( post ) => {
						return (
							<SponsorDetail sponsorPost={post}/>
						)
					} )
				}
			</ul>
		)
	}

}

export default SponsorBlockContent;
