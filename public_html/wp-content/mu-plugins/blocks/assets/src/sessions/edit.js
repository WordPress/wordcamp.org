/**
 * External dependencies
 */
import { isUndefined, pickBy, split } from 'lodash';

/**
 * WordPress dependencies
 */
const apiFetch = wp.apiFetch;
const { withSelect } = wp.data;
const { Component, Fragment } = wp.element;
const { addQueryArgs } = wp.url;

/**
 * Internal dependencies
 */
import SessionsBlockControls from './block-controls';
import SessionsInspectorControls from './inspector-controls';
import GridToolbar from '../shared/grid-layout/toolbar';
import { ICON }                  from './index';
import { WC_BLOCKS_STORE } from '../blocks-store';

const blockData = window.WordCampBlocks.sessions || {};

class SessionsEdit extends Component {
	render() {
		const { mode } = this.props.attributes;

		return (
			<Fragment>
				<SessionsBlockControls
					icon={ ICON }
					{ ...this.props }
					{ ...this.state }
				/>
				{ mode &&
				<Fragment>
					<SessionsInspectorControls { ...this.props } />
					<GridToolbar { ...this.props } />
				</Fragment>
				}
			</Fragment>
		);
	}
}

const sessionsSelect = ( select, props ) => {

	const { getEntities } = select( WC_BLOCKS_STORE );

	return {
		blockData,
		allSessionPosts: getEntities( 'postType', 'wcb_session' ),
		allSessionTracks: getEntities( 'taxonomy', 'wcb_track' ),
		allSessionCategories: getEntities( 'taxonomy', 'wcb_session_category' ),
	};
};

export const edit = withSelect( sessionsSelect )( SessionsEdit );
