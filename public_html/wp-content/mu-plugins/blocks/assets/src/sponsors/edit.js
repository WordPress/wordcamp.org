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
		const { attributes } = this.props;
		const { mode } = attributes;

		return (
			<Fragment>
				{
					<SponsorBlockControls
						icon={ ICON }
						{ ...this.props }
					/>
				}
				<Fragment>
					<SponsorInspectorControls
						{ ...this.props }
					/>

					{ mode &&
						<GridToolbar
							{ ...this.props }
						/>
					}
				</Fragment>
			</Fragment>
		);
	}
}

const sponsorSelect = ( select ) => {

	const { getEntities, getSiteSettings } = select( WC_BLOCKS_STORE );

	return {
		blockData,
		sponsorPosts  : getEntities( 'postType', 'wcb_sponsor', { _embed: true } ),
		sponsorLevels : getEntities( 'taxonomy', 'wcb_sponsor_level' ),
		siteSettings  : getSiteSettings(),
	}
};

export const edit = withSelect( sponsorSelect )( SponsorsEdit );
