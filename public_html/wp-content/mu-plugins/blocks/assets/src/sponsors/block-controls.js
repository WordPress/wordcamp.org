/**
 * WordPress dependencies.
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies.
 */
import { BlockControls, PlaceholderNoContent } from "../shared/block-controls";
import SponsorBlockContent from './block-content';
import CustomPostTypeSelect from '../shared/block-controls/custom-post-select'

const LABEL = __( 'Sponsors', 'wordcamporg' );

function SponsorOption( { type, label = '', featuredImage, count = 0 } ) {


}

/**
 * Implements sponsor block controls.
 */
class SponsorBlockControls extends BlockControls {

	constructor( props ) {
		super(props);
	}

	/**
	 * Renders Sponsor Block Control view
	 */
	render() {
		const { sponsorPosts, attributes } = this.props;
		const { mode } = attributes;

		const hasPosts = Array.isArray( sponsorPosts ) && sponsorPosts.length;

		// Check if posts are still loading.
		if ( mode && ! hasPosts ) {
			return (
				<PlaceholderNoContent
					label = { LABEL }
					loading = { () => {
						return ! Array.isArray( sponsorPosts );
					} }
				/>
			)
		}

		let output;

		switch ( mode ) {
			case 'all' :
				output = (
					<SponsorBlockContent { ...this.props } />
				);
				break;
			default:
				output = (
					<CustomPostTypeSelect
						allPosts = { this.props.sponsorPosts }
						allTerms = { this.props.sponsorLevels }
						{ ...this.props }
						postLabel = { __( 'Sponsors', 'wordcamporg' ) }
						termLabel = { __( 'Sponsors Level', 'wordcamporg' ) }
					/>
				)
		}

		return output;
	}

}

export default SponsorBlockControls;