/**
 * WordPress dependencies
 */
import { withSelect } from '@wordpress/data';
import { Component, Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { WC_BLOCKS_STORE } from '../../data';
import { ScheduleGrid } from './schedule-grid';
import InspectorControls from './inspector-controls';
import { ICON } from './index';

const blockData = window.WordCampBlocks.sessions || {};

/**
 * Top-level component for the editing UI for the block.
 */
class ScheduleEdit extends Component {
	/**
	 * Render the block's editing UI.
	 *
	 * @return {Element}
	 */
	render() {
		const { attributes, entities } = this.props;

		return (
			<Fragment>
				<ScheduleGrid
					icon={ ICON }
					attributes={ attributes }
					entities={ entities }
				/>

				<InspectorControls />
			</Fragment>
		);
	}
}

const scheduleSelect = ( select ) => {
	const { getEntities, getSiteSettings } = select( WC_BLOCKS_STORE );

	const sessionArgs = {
		_embed: true,
	};

	const entities = {
		sessions : getEntities( 'postType', 'wcb_session', sessionArgs ),
		settings : getSiteSettings(),
	};

	return { blockData, entities };
};

export const Edit = withSelect( scheduleSelect )( ScheduleEdit );
