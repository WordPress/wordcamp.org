import RawHTML from '@wordpress/element/build-module/raw-html';
import {get} from 'lodash';
import {FeaturedImage} from '../shared/block-content';

const { Component } = wp.element;

function SponsorDetail( { sponsorPost } ) {
	return (
		<div className={"wordcamp-sponsor-details"}>
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
