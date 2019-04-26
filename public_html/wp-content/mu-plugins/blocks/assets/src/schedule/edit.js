/**
 * External dependencies
 */
import { isUndefined, pickBy, split } from 'lodash';

/**
 * WordPress dependencies
 */
const { withSelect }          = wp.data;
const { Component, Fragment } = wp.element;

/**
 * Internal dependencies
 */
import ScheduleBlockControls     from './block-controls';
import ScheduleInspectorControls from './inspector-controls';
import { ICON }                  from './index';
import { WC_BLOCKS_STORE }       from '../blocks-store';

// todo need to diff against other blocks to pull in latest changes


const blockData = window.WordCampBlocks.sessions || {}; // todo does this already exist b/c sessions block is loaded, so don't need to do any queries to fetch it?
														// is there any other input, like the day? i guess that's fetched automatically, or derived from sessions. and then configured in inspector controls

class ScheduleEdit extends Component {
	// rebased this against origin/vedanshu-store to get new data stuff, so make sure that PR is merged to master first, and that this one doesn't introduce any artifcats (git diff master and check each line)

	render() {
		const { attributes, categories, sessions, tracks } = this.props;

		return (
			<Fragment>
				<ScheduleBlockControls
					icon={ ICON }
					attributes={ attributes }
					categories={ categories }
					sessions={ sessions }
					tracks={ tracks }
				/>

				<ScheduleInspectorControls />
				{/* might need to pass in some props but not sure yet */}
			</Fragment>
		);
	}
}

const scheduleSelect = ( select, props ) => {
	const { getEntities } = select( WC_BLOCKS_STORE );

	return {
		blockData : blockData,  // todo don't need this? maybe do to avoid re-fetching?
		sessions  : getEntities( 'postType', 'wcb_session', { _embed: true } ),
		tracks    : getEntities( 'taxonomy', 'wcb_track' ),
		categories: getEntities( 'taxonomy', 'wcb_session_category' ),

		// todo need to rename other blocks match ^, don't need "allSessionPosts" it's unnecessarily verbose, since no vars that only have _some_ posts
	};
};

export const edit = withSelect( scheduleSelect )( ScheduleEdit );
