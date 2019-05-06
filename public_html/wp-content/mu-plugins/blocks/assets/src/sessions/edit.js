/**
 * External dependencies
 */
import { get } from 'lodash';

/**
 * WordPress dependencies
 */
const { withSelect }          = wp.data;
const { Component, Fragment } = wp.element;

/**
 * Internal dependencies
 */
import SessionsBlockControls     from './block-controls';
import SessionsInspectorControls from './inspector-controls';
import GridToolbar               from '../shared/grid-layout/toolbar';
import { ICON }                  from './index';
import { WC_BLOCKS_STORE }       from '../blocks-store';

const blockData = window.WordCampBlocks.sessions || {};

/**
 * Top-level component for the editing UI for the block.
 */
class SessionsEdit extends Component {
	/**
	 * Render the block's editing UI.
	 *
	 * @return {Element}
	 */
	render() {
		const { mode } = this.props.attributes;

		return (
			<Fragment>
				<SessionsBlockControls
					icon={ ICON }
					{ ...this.props }
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

const sessionsSelect = ( select ) => {
	const { getEntities } = select( WC_BLOCKS_STORE );

	/**
	 * Filter out non-"regular" sessions.
	 *
	 * The REST API doesn't have a way to do meta queries without creating a custom endpoint, so we have to
	 * do this filtering as a separate step with the query results instead.
	 *
	 * TODO: This isn't very performant, and probably causes a lot of unnecessary extra repaints. We should
	 *       find a better place or way to do this.
	 */
	let sessions = getEntities( 'postType', 'wcb_session', { _embed: true } );
	if ( Array.isArray( sessions ) ) {
		sessions = sessions.filter( ( session ) => {
			return 'session' === get( session, 'meta._wcpt_session_type', '' );
		} );
	}

	const entities = {
		wcb_session          : sessions,
		wcb_track            : getEntities( 'taxonomy', 'wcb_track' ),
		wcb_session_category : getEntities( 'taxonomy', 'wcb_session_category' ),
	};

	return {
		blockData,
		entities,
	};
};

export const edit = withSelect( sessionsSelect )( SessionsEdit );
