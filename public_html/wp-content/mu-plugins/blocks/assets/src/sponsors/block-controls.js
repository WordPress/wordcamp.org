/**
 * WordPress dependencies.
 */
import Placeholder from 'react-select/src/components/Placeholder';

const { __ } = wp.i18n;

/**
 * Internal dependencies.
 */
import { BlockControls, PlaceholderNoContent, PlaceholderSpecificMode } from "../shared/block-controls";
import SponsorBlockContent from './block-content';
import VersatileSelect from '../shared/versatile-select';

const LABEL = __( 'Sponsors', 'wordcamporg' );

/**
 * Render select box for selecting sponsors.
 */
function SponsorSelect( { select, props } ) {
	return (
		<VersatileSelect
		/>
	)
}

/**
 * Implements sponsor block controls.
 */
class SponsorBlockControls extends BlockControls {

	/**
	 * Renders Sponsor Block Control view
	 */
	render() {
		const { sponsorPosts, attributes } = this.props;
		const { mode } = attributes;

		const hasPosts = Array.isArray( sponsorPosts ) && sponsorPosts.length;

		console.log("Mode is: ", mode);
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
					<Placeholder
					/>
				)
		}

		return (
				<SponsorBlockContent
					{ ...this.props }
					{ ...this.state }
				/>
			);
	}

}

export default SponsorBlockControls;