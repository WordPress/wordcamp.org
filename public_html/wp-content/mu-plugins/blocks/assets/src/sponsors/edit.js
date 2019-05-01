/**
 * WordPress dependencies
 */
const { Component, Fragment } = wp.element;
const { withSelect }          = wp.data;

/**
 * Internal dependencies
 */
import SponsorInspectorControls from './inspector-controls';
import SponsorBlockControls     from './block-controls';
import GridToolbar              from '../shared/grid-layout/toolbar';
import { ICON }                 from './index';
import { WC_BLOCKS_STORE } from '../blocks-store';

const blockData = window.WordCampBlocks.sponsors || {};

class SponsorsEdit extends Component {
	/**
	 * Renders SponsorEdit component.
	 *
	 * @return {Element}
	 */
	render() {
		const { mode } = this.props.attributes;

		return (
			<Fragment>
				<SponsorBlockControls
					icon={ ICON }
					{ ...this.props }
				/>
				{ mode &&
					<Fragment>
						<SponsorInspectorControls { ...this.props } />
						<GridToolbar { ...this.props } />
					</Fragment>
				}
			</Fragment>
		);
	}
}

const sponsorSelect = ( select ) => {
	const { getEntities, getSiteSettings } = select( WC_BLOCKS_STORE );

	const entities = {
		wcb_sponsor       : getEntities( 'postType', 'wcb_sponsor', { _embed: true } ),
		wcb_sponsor_level : getEntities( 'taxonomy', 'wcb_sponsor_level' ),
	};

	return {
		blockData,
		entities,
		siteSettings : getSiteSettings(),
	}
};

export const edit = withSelect( sponsorSelect )( SponsorsEdit );
