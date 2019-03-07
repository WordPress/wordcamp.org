import { get } from 'lodash';
const { RawHTML } = wp.element;
import classnames from 'classnames';
import FeaturedImage from '../shared/featured-image';
import './block-content.scss';

const { Component } = wp.element;

function SponsorDetail( { sponsorPost, attributes } ) {

	const {
		show_name, show_logo, show_desc, sponsor_logo_height, sponsor_logo_width
	} = attributes;
	const featuredImageSize = { height: sponsor_logo_height, width: sponsor_logo_width };

	const featuredImageSizes = get( sponsorPost, "_embedded.wp:featuredmedia[0].media_details.sizes", {} );

	return (
		<div className={"wordcamp-sponsor-details"}>
			{ ( show_logo || show_logo === undefined ) &&
			<FeaturedImage
				wpMediaDetails={featuredImageSizes}
				size={featuredImageSize}
				alt={sponsorPost.title.rendered}
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

class SponsorBlockContent extends Component {

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
									'wordcamp-clearfix'
								)}
							>
								<SponsorDetail
									sponsorPost={ post }
									attributes={ attributes }
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
