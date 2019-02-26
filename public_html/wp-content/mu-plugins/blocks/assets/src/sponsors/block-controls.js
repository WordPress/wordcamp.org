/**
 * WordPress dependencies.
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies.
 */
import { BlockControls, PlaceholderNoContent, PlaceholderSpecificMode } from "../shared/block-controls";
import SponsorBlockContent from './block-content';

const LABEL = __( 'Sponsors', 'wordcamporg' );

/**
 * Implements sponsor block controls.
 */
class SponsorBlockControls extends BlockControls {

	/**
	 * Renders Sponsor Block Control view
	 */
	render() {
		const { sponsorPosts, attributes } = this.props;
		const mode = attributes.modes || 'all';
		console.log(sponsorPosts);

		const hasPosts = Array.isArray( sponsorPosts ) && sponsorPosts.length;

		console.log("Mode: ", mode, " hasPosrs: ", hasPosts);
		if ( mode && ! hasPosts ) {
			console.log("Should have come here...");
			return (
				<PlaceholderNoContent
					label = { LABEL }
					loading = { () => {
						return ! Array.isArray( sponsorPosts );
					} }
				/>
			)
		}

		return (
			<SponsorBlockContent
				{ ...this.props }
				{ ...this.state }
			/>
		)


	}

}

export default SponsorBlockControls;