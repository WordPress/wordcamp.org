/**
 * Displays sponsor block.
 */

import SponsorInspectorControls from './inspector-controls';
import SponsorBlocksControls from './block-controls';

/**
 WordPress dependencies.
 **/
const { withSelect } = wp.data;
const { Component, Fragment } = wp.element;

class SponsorsEdit extends Component {

	/**
	 * Constructor for SponsorsEdit block.
	 *
	 * @param props
	 */
	constructor( props ) {
		super( props );
	}

	/**
	 * Renders SponsorEdit component.
	 */
	render() {

		return (
			<Fragment>
				<SponsorBlocksControls
					{ ...this.props }
					{ ...this.state }
				/>
				<Fragment>
					<SponsorInspectorControls { ...this.props } />
				</Fragment>
			</Fragment>
		)
	}
}

/**
 * API call for wcb_sponsor post type data.
 *
 * @param select
 * @param props
 */
const sponsorSelect = ( select, props ) => {
	const { getEntityRecords } = select( 'core' );

	let sponsorPosts = getEntityRecords( 'postType', 'wcb_sponsor', { _embed: true } );
	return {
		sponsorPosts
	}
};

export const edit = withSelect( sponsorSelect )( SponsorsEdit );